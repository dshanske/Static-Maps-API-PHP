<?php

/**
 * Parsing of `path[]` and `polyline` parameters.
 */

namespace StaticMaps;

/**
 * Turns `path[]` values, and a `polyline`, into paths and shapes to draw.
 *
 * A path is a string of `key:value;` pairs and its geometry, one of:
 *
 * - points written as `[lng,lat]`, e.g. `color:ff0000;weight:4;[-122.6,45.5][-122.7,45.6]`;
 * - `enc:<encoded polyline>` in Google's Encoded Polyline format (lat,lng
 *   pairs; `precision:5` by default, `precision:6` for OSRM and Valhalla) (#43, #36);
 * - `circle:lng,lat,radius` with the radius in metres, drawn as a polygon that
 *   follows the Earth's curvature;
 * - `rect:minLng,minLat,maxLng,maxLat`.
 *
 * Style keys (#36): `color` and `fillcolor` (RRGGBB, or RRGGBBAA with opacity;
 * a `fillcolor` closes and fills the shape), `weight` (pixels, 0-100),
 * `linestyle` (`solid`, `dashed`, `dotted`) and `geodesic:true` (follow great
 * circles). `polyline` is a shortcut for one `enc:` path, drawn after the
 * `path[]` ones.
 */
final class PathParser
{
    /**
     * @param mixed $defaultColor  Stroke colour when a path has none: hex RGB without `#`.
     * @param mixed $defaultWeight Stroke width in pixels when a path has none.
     * @param int   $maxPoints     Most points in all paths together (`limits.max_points`); a
     *                             path that would go over is left out, with an `X-Map-Limit` header.
     */
    public function __construct(
        private mixed $defaultColor,
        private mixed $defaultWeight,
        private int $maxPoints = PHP_INT_MAX
    ) {
    }

    /** @var int Points in a circle. */
    private const CIRCLE_POINTS = 72;

    /** @var float Mean Earth radius in metres (for circles and great circles). */
    private const EARTH_RADIUS = 6371008.8;

    /** @var float Largest circle radius in metres: about half the Earth's circumference. */
    private const MAX_RADIUS = 20000000.0;

    /**
     * Parse the paths of a request.
     *
     * A path that can't be drawn (no or unreadable geometry, points out of
     * range, an invalid style) is left out and reported in an `X-Path-<n>`
     * header: n counts the `path[]` values from 1, then the `polyline`. A path
     * that would take the total number of points over `$maxPoints` is left out
     * (not drawn, not in the bounds) and counted in an `X-Map-Limit` header.
     *
     * @param mixed  $paths    The `path` parameter: a string, a list of strings, or false.
     * @param mixed  $polyline The `polyline` parameter, or false.
     * @param Bounds $bounds   Box to widen with every point.
     *
     * @return array{paths: list<array<string, mixed>>, headers: list<string>} The paths
     *         (`color`, `weight`, `path`: a list of [lng, lat] points, `closed`, `fill`
     *         (RRGGBB[AA] or null), `linestyle`, `geodesic`, plus any other `key:value`
     *         pairs) and headers to send.
     */
    public function parse(mixed $paths, mixed $polyline, Bounds $bounds): array
    {
        $values = $paths ? array_values(is_array($paths) ? $paths : [$paths]) : [];
        if ($polyline) {
            $values[] = is_string($polyline) ? self::asPolyline($polyline) : ['polyline' => $polyline];
        }

        $result = array();
        $headers = array();
        $total = 0;
        $over = 0;
        foreach ($values as $n => $value) {
            [$path, $problem] = $this->parseOne($value);
            if ($path === null) {
                $headers[] = 'X-Path-' . ($n + 1) . ': ' . $problem;
                continue;
            }
            if (!$this->withinLimit($total, count($path['path']))) {
                $over++;
                continue;
            }
            // Each segment the short way round (#35); geodesic paths are already split into short steps.
            $bounds->extendPath($path['closed'] ? [...$path['path'], $path['path'][0]] : $path['path']);
            $result[] = $path;
        }
        if ($over > 0) {
            $headers[] = $this->limitHeader($over);
        }

        return ['paths' => $result, 'headers' => $headers];
    }

    /**
     * Mark a `polyline` value, so parseOne() can tell it from a `path[]` value.
     *
     * @param string $value The `polyline` parameter.
     *
     * @return array{polyline: string} The value, marked.
     */
    private static function asPolyline(string $value): array
    {
        return ['polyline' => $value];
    }

    /**
     * Parse one `path[]` value or the `polyline`.
     *
     * @param mixed $value A `path[]` string, or ['polyline' => value].
     *
     * @return array{array<string, mixed>|null, string|null} The path, or null and the problem.
     */
    private function parseOne(mixed $value): array
    {
        $isPolyline = is_array($value) && array_key_exists('polyline', $value);
        $string = $isPolyline ? $value['polyline'] : $value;
        if (!is_string($string)) {
            return [null, $isPolyline ? 'not a polyline' : 'not a path'];
        }
        $properties = $this->styleProperties($string);

        $style = $this->checkStyle($properties);
        if ($style !== null) {
            return [null, $style];
        }

        $kinds = array_values(array_filter(['enc', 'circle', 'rect'], fn (string $k): bool => isset($properties[$k])));
        $hasPoints = preg_match_all('/(?P<point>\[[0-9\.-]+,[0-9\.-]+\])/', $string, $matches) > 0;
        if (count($kinds) + ($hasPoints ? 1 : 0) > 1) {
            return [null, 'use one of [lng,lat] points, enc, circle or rect'];
        }

        $closed = isset($properties['fillcolor']);
        if (isset($properties['circle'])) {
            [$points, $problem] = self::circle((string) $properties['circle']);
            $closed = true;
        } elseif (isset($properties['rect'])) {
            [$points, $problem] = self::rect((string) $properties['rect']);
            $closed = true;
        } elseif (isset($properties['enc'])) {
            [$points, $problem] = self::decode((string) $properties['enc'], $properties['precision'] ?? '5');
        } elseif ($hasPoints) {
            $points = json_decode('[' . implode(',', $matches['point']) . ']');
            $problem = self::validPoints($points) ? null : 'points must be [lng,lat] numbers in range';
        } else {
            return [null, $isPolyline
                ? 'no points (expected enc:<encoded polyline>)'
                : 'no points (expected [lng,lat] pairs, enc, circle or rect)'];
        }
        if ($problem !== null) {
            return [null, $problem];
        }

        $geodesic = in_array($properties['geodesic'] ?? 'false', ['true', '1'], true);
        if ($geodesic && !isset($properties['circle']) && !isset($properties['rect'])) {
            $points = self::greatCircle($closed ? [...$points, $points[0]] : $points);
            if ($closed) {
                array_pop($points);
            }
        }

        $properties['path'] = $points;
        $properties['closed'] = $closed;
        $properties['fill'] = $properties['fillcolor'] ?? null;
        $properties['linestyle'] = $properties['linestyle'] ?? 'solid';
        $properties['geodesic'] = $geodesic;
        $properties['vehicle'] = isset($properties['icon']) ? [
            'type'  => (string) $properties['icon'],
            'at'    => self::positions((string) ($properties['iconat'] ?? '50')) ?? [50.0],
            'color' => strtolower(substr((string) ($properties['iconcolor'] ?? $properties['color']), 0, 6)),
        ] : null;

        return [$properties, null];
    }

    /**
     * Check a path's style values.
     *
     * @param array<string, mixed> $properties The path's `key:value` pairs (with defaults).
     *
     * @return string|null The problem, or null if the style is valid.
     */
    private function checkStyle(array $properties): ?string
    {
        foreach (['color', 'fillcolor'] as $key) {
            $value = $properties[$key] ?? null;
            if ($value !== null && !preg_match('/^[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', (string) $value)) {
                return "$key must be RRGGBB or RRGGBBAA (hex)";
            }
        }
        $weight = $properties['weight'];
        if (!is_numeric($weight) || (float) $weight < 0 || (float) $weight > 100) {
            return 'weight must be a number of pixels from 0 to 100';
        }
        $linestyle = $properties['linestyle'] ?? 'solid';
        if (!in_array($linestyle, ['solid', 'dashed', 'dotted'], true)) {
            return 'linestyle must be solid, dashed or dotted';
        }
        if (isset($properties['geodesic']) && !in_array($properties['geodesic'], ['true', 'false', '1', '0'], true)) {
            return 'geodesic must be true or false';
        }
        if (isset($properties['precision']) && !in_array($properties['precision'], ['5', '6'], true)) {
            return 'precision must be 5 or 6';
        }
        // Vehicle icons along the path (#106).
        if (isset($properties['icon']) && !in_array($properties['icon'], PathIcon::TYPES, true)) {
            return 'icon must be ' . implode(', ', PathIcon::TYPES);
        }
        if (isset($properties['iconat']) && self::positions((string) $properties['iconat']) === null) {
            return 'iconat must be 1 to ' . PathIcon::MAX_POSITIONS . ' comma-separated percentages from 0 to 100';
        }
        if (isset($properties['iconcolor']) && !preg_match('/^[0-9a-fA-F]{6}$/', (string) $properties['iconcolor'])) {
            return 'iconcolor must be RRGGBB (hex)';
        }
        if ((isset($properties['iconat']) || isset($properties['iconcolor'])) && !isset($properties['icon'])) {
            return 'iconat and iconcolor need an icon';
        }

        return null;
    }

    /**
     * Parse `iconat`: where icons go along a path.
     *
     * @param string $value Comma-separated percentages of the path's length.
     *
     * @return list<float>|null The percentages (0-100), or null if invalid or too many.
     */
    private static function positions(string $value): ?array
    {
        $parts = array_map('trim', explode(',', $value));
        if (count($parts) > PathIcon::MAX_POSITIONS) {
            return null;
        }
        $positions = [];
        foreach ($parts as $part) {
            if (!is_numeric($part) || (float) $part < 0 || (float) $part > 100) {
                return null;
            }
            $positions[] = (float) $part;
        }

        return $positions;
    }

    /**
     * Decode an encoded polyline.
     *
     * @param string $encoded   The encoded polyline.
     * @param mixed  $precision Decimal places: '5' (Google) or '6' (OSRM, Valhalla).
     *
     * @return array{list<array{float, float}>, string|null} [lng, lat] points, and a problem or null.
     */
    private static function decode(string $encoded, mixed $precision): array
    {
        // Google's encoding stores each point as lat,lng (#43); paths use [lng, lat].
        $points = array_map(
            static fn (array $pair): array => count($pair) === 2 ? [$pair[1], $pair[0]] : $pair,
            Polyline::pair(Polyline::decode($encoded, (int) $precision))
        );

        return [$points, self::validPoints($points) ? null : 'the polyline has points out of range'];
    }

    /**
     * The points of a circle, following the Earth's curvature.
     *
     * @param string $value The `circle` value, `lng,lat,radius`: centre in degrees, radius in metres.
     *
     * @return array{list<array{float, float}>, string|null} [lng, lat] points round the circle
     *         (latitudes kept within the map's limits), and a problem or null.
     */
    private static function circle(string $value): array
    {
        $parts = array_map('trim', explode(',', $value));
        if (
            count($parts) !== 3 || !Parameters::isValidPoint($parts[1], $parts[0])
            || !is_numeric($parts[2]) || (float) $parts[2] <= 0 || (float) $parts[2] > self::MAX_RADIUS
        ) {
            return [[], 'circle must be lng,lat,radius with the radius in metres (up to 20000000)'];
        }
        $lat = deg2rad((float) $parts[1]);
        $lng = deg2rad((float) $parts[0]);
        $d = (float) $parts[2] / self::EARTH_RADIUS;

        $points = [];
        for ($i = 0; $i < self::CIRCLE_POINTS; $i++) {
            $bearing = 2 * M_PI * $i / self::CIRCLE_POINTS;
            $lat2 = asin(sin($lat) * cos($d) + cos($lat) * sin($d) * cos($bearing));
            $lng2 = $lng + atan2(sin($bearing) * sin($d) * cos($lat), cos($d) - sin($lat) * sin($lat2));
            $points[] = [Bounds::wrap(rad2deg($lng2)), self::clampLat(rad2deg($lat2))];
        }

        return [$points, null];
    }

    /**
     * The corners of a rectangle, with points along the long edges so they are drawn the right way round.
     *
     * @param string $value The `rect` value, `minLng,minLat,maxLng,maxLat` in degrees (minLng > maxLng
     *                      crosses ±180°).
     *
     * @return array{list<array{float, float}>, string|null} [lng, lat] points, and a problem or null.
     */
    private static function rect(string $value): array
    {
        $parts = array_map('trim', explode(',', $value));
        if (
            count($parts) !== 4 || !Parameters::isValidPoint($parts[1], $parts[0])
            || !Parameters::isValidPoint($parts[3], $parts[2]) || (float) $parts[1] > (float) $parts[3]
        ) {
            return [[], 'rect must be minLng,minLat,maxLng,maxLat with minLat no more than maxLat'];
        }
        [$west, $south, $east, $north] = array_map('floatval', $parts);
        $width = $east >= $west ? $east - $west : $east + 360 - $west;

        // Four steps along the top and bottom, so no step is more than 90 degrees.
        $points = [];
        for ($i = 0; $i <= 4; $i++) {
            $points[] = [Bounds::wrap($west + $width * $i / 4), $south];
        }
        for ($i = 4; $i >= 0; $i--) {
            $points[] = [Bounds::wrap($west + $width * $i / 4), $north];
        }

        return [$points, null];
    }

    /**
     * Split each segment along its great circle, in steps of at most 1 degree.
     *
     * @param list<array{mixed, mixed}> $points The path's [lng, lat] points in degrees.
     *
     * @return list<array{float, float}> The points along the great circles.
     */
    private static function greatCircle(array $points): array
    {
        $result = [[(float) $points[0][0], (float) $points[0][1]]];
        for ($i = 1, $count = count($points); $i < $count; $i++) {
            [$lng1, $lat1] = [deg2rad((float) $points[$i - 1][0]), deg2rad((float) $points[$i - 1][1])];
            [$lng2, $lat2] = [deg2rad((float) $points[$i][0]), deg2rad((float) $points[$i][1])];
            $d = 2 * asin(sqrt(
                sin(($lat2 - $lat1) / 2) ** 2 + cos($lat1) * cos($lat2) * sin(($lng2 - $lng1) / 2) ** 2
            ));
            $steps = (int) min(180, max(1, ceil(rad2deg($d))));
            for ($k = 1; $k <= $steps; $k++) {
                if ($d < 1e-12) {
                    break;
                }
                $f = $k / $steps;
                $a = sin((1 - $f) * $d) / sin($d);
                $b = sin($f * $d) / sin($d);
                $x = $a * cos($lat1) * cos($lng1) + $b * cos($lat2) * cos($lng2);
                $y = $a * cos($lat1) * sin($lng1) + $b * cos($lat2) * sin($lng2);
                $z = $a * sin($lat1) + $b * sin($lat2);
                $result[] = [rad2deg(atan2($y, $x)), self::clampLat(rad2deg(atan2($z, sqrt($x * $x + $y * $y))))];
            }
            if ($d < 1e-12) {
                $result[] = [(float) $points[$i][0], (float) $points[$i][1]];
            }
        }

        return $result;
    }

    /**
     * Keep a latitude within the map (Web Mercator stops at about ±85.05°).
     *
     * @param float $lat Latitude in degrees.
     *
     * @return float The latitude, clamped.
     */
    private static function clampLat(float $lat): float
    {
        return max(-Parameters::MAX_LATITUDE, min(Parameters::MAX_LATITUDE, $lat));
    }

    /**
     * Count a path's points against the limit.
     *
     * @param int $total  Points accepted so far; increased if this path fits.
     * @param int $points Points in this path.
     *
     * @return bool True if the path fits within `$maxPoints`.
     */
    private function withinLimit(int &$total, int $points): bool
    {
        if ($total + $points > $this->maxPoints) {
            return false;
        }
        $total += $points;

        return true;
    }

    /**
     * The header for paths left out over the point limit.
     *
     * @param int $count How many paths were left out.
     *
     * @return string The `X-Map-Limit` header line.
     */
    private function limitHeader(int $count): string
    {
        return "X-Map-Limit: left out $count path(s) over the limit of $this->maxPoints points";
    }

    /**
     * Whether a decoded list of points is usable.
     *
     * @param mixed $points Decoded points: a list of [lng, lat] pairs.
     *
     * @return bool True if it is a non-empty list of valid pairs.
     */
    private static function validPoints(mixed $points): bool
    {
        if (!is_array($points) || $points === []) {
            return false;
        }
        foreach ($points as $point) {
            if (!is_array($point) || count($point) !== 2 || !Parameters::isValidPoint($point[1], $point[0])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Read the `key:value` pairs of a path and fill in the default style.
     *
     * @param string $value A `path[]` or `polyline` value.
     *
     * @return array<string, mixed> The pairs, with `color` and `weight` always set.
     */
    private function styleProperties(string $value): array
    {
        $properties = array();
        if (preg_match_all('/(?P<k>[a-z]+):(?P<v>[^;]+)/', $value, $matches)) {
            foreach ($matches['k'] as $j => $key) {
                $properties[$key] = $matches['v'][$j];
            }
        }

        // Set default color and weight if none specified
        if (!array_key_exists('color', $properties)) {
            $properties['color'] = $this->defaultColor;
        }
        if (!array_key_exists('weight', $properties)) {
            $properties['weight'] = $this->defaultWeight;
        }

        return $properties;
    }
}

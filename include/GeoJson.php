<?php

/**
 * GeoJSON overlays (#38).
 */

namespace StaticMaps;

/**
 * Turns a `geojson` parameter into marker and path values.
 *
 * Accepts a GeoJSON Feature, FeatureCollection or bare geometry (RFC 7946),
 * as an object in a JSON body or as a JSON string. Each feature becomes the
 * same `marker[]` or `path[]` value a client could have sent, so the usual
 * checks, limits and `X-Marker-<n>` / `X-Path-<n>` notices apply; GeoJSON
 * markers and paths are numbered after the request's own.
 *
 * Styling follows simplestyle-spec 1.1.0 (https://github.com/mapbox/simplestyle-spec):
 *
 * - Point, MultiPoint: a pin with `marker-color` (default #7e7e7e) and
 *   `marker-size` (`small`, `medium`, `large`). A short `marker-symbol` (one or
 *   two letters or digits, or a number up to 999) becomes its label; icon
 *   names (Maki) aren't supported and are ignored.
 * - LineString, MultiLineString: `stroke` (default #555555), `stroke-width`
 *   (2) and `stroke-opacity` (1).
 * - Polygon, MultiPolygon: the outer ring, with the stroke properties plus
 *   `fill` (default #555555) and `fill-opacity` (0.6). Holes aren't drawn.
 * - GeometryCollection: each member, with the feature's properties.
 *
 * Features without a geometry, or with an unknown type, are skipped and
 * counted in an `X-Map-GeoJSON` header.
 */
final class GeoJson
{
    /** @var array<string, string|float> simplestyle-spec defaults. */
    private const DEFAULTS = [
        'marker-color'   => '7e7e7e',
        'marker-size'    => 'medium',
        'stroke'         => '555555',
        'stroke-width'   => 2.0,
        'stroke-opacity' => 1.0,
        'fill'           => '555555',
        'fill-opacity'   => 0.6,
    ];

    /**
     * Convert a `geojson` parameter.
     *
     * @param mixed $value The parameter: false when not given, a decoded object (array), or a JSON string.
     *
     * @return array{markers: list<string>, paths: list<string>, headers: list<string>} `marker[]` and
     *         `path[]` values, and headers to send.
     *
     * @throws InvalidRequest If it isn't valid JSON or not a GeoJSON object.
     */
    public static function toOverlays(mixed $value): array
    {
        $result = ['markers' => [], 'paths' => [], 'headers' => []];
        if ($value === false || $value === '' || $value === null) {
            return $result;
        }
        if (is_string($value)) {
            $value = json_decode($value, true, 64);
        }
        if (!is_array($value) || !is_string($value['type'] ?? null)) {
            throw new InvalidRequest('geojson must be a GeoJSON Feature, FeatureCollection or geometry');
        }

        $features = match ($value['type']) {
            'FeatureCollection' => is_array($value['features'] ?? null) ? array_values($value['features']) : null,
            'Feature'           => [$value],
            default             => [['type' => 'Feature', 'geometry' => $value, 'properties' => []]],
        };
        if ($features === null) {
            throw new InvalidRequest('geojson must be a GeoJSON Feature, FeatureCollection or geometry');
        }

        $skipped = 0;
        foreach ($features as $feature) {
            $geometry = is_array($feature) ? ($feature['geometry'] ?? null) : null;
            $properties = is_array($feature) && is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            if (!is_array($geometry) || !self::add($geometry, $properties, $result)) {
                $skipped++;
            }
        }
        if ($skipped > 0) {
            $result['headers'][] = "X-Map-GeoJSON: skipped $skipped feature(s) without a supported geometry";
        }

        return $result;
    }

    /**
     * Add the markers or paths of one geometry.
     *
     * @param array<string, mixed>                                                    $geometry   The geometry.
     * @param array<string, mixed>                                                    $properties The feature's
     *                                                                                            properties.
     * @param array{markers: list<string>, paths: list<string>, headers: list<string>} $result    Receives them.
     *
     * @return bool False if the geometry type isn't supported or its coordinates aren't lists.
     */
    private static function add(array $geometry, array $properties, array &$result): bool
    {
        $c = $geometry['coordinates'] ?? null;
        switch ($geometry['type'] ?? null) {
            case 'Point':
                $result['markers'][] = self::marker($c, $properties);
                return true;
            case 'MultiPoint':
                foreach (self::lists($c) as $point) {
                    $result['markers'][] = self::marker($point, $properties);
                }
                return is_array($c);
            case 'LineString':
                $result['paths'][] = self::path($c, $properties, false);
                return true;
            case 'MultiLineString':
                foreach (self::lists($c) as $line) {
                    $result['paths'][] = self::path($line, $properties, false);
                }
                return is_array($c);
            case 'Polygon':
                $result['paths'][] = self::path(self::lists($c)[0] ?? [], $properties, true);
                return true;
            case 'MultiPolygon':
                foreach (self::lists($c) as $polygon) {
                    $result['paths'][] = self::path(self::lists($polygon)[0] ?? [], $properties, true);
                }
                return is_array($c);
            case 'GeometryCollection':
                $all = true;
                foreach (self::lists($geometry['geometries'] ?? null) as $member) {
                    $all = self::add($member, $properties, $result) && $all;
                }
                return $all;
        }

        return false;
    }

    /**
     * A `marker[]` value for a point.
     *
     * @param mixed                $point      GeoJSON position, [lng, lat].
     * @param array<string, mixed> $properties The feature's properties.
     *
     * @return string The marker (invalid coordinates are left for MarkerParser to report).
     */
    private static function marker(mixed $point, array $properties): string
    {
        $point = is_array($point) ? array_values($point) : [];
        $marker = 'lat:' . self::number($point[1] ?? null) . ';lng:' . self::number($point[0] ?? null)
            . ';icon:pin;color:' . self::color($properties['marker-color'] ?? null, self::DEFAULTS['marker-color'])
            . ';size:' . self::text($properties['marker-size'] ?? self::DEFAULTS['marker-size']);
        $symbol = $properties['marker-symbol'] ?? null;
        $short = (is_string($symbol) || is_int($symbol))
            && preg_match('/^([A-Za-z0-9]{1,2}|[0-9]{1,3})$/', (string) $symbol);
        if ($short) {
            $marker .= ';label:' . $symbol;
        }

        return $marker;
    }

    /**
     * A `path[]` value for a line or a polygon's outer ring.
     *
     * @param mixed                $positions  GeoJSON positions, each [lng, lat].
     * @param array<string, mixed> $properties The feature's properties.
     * @param bool                 $filled     Whether it is a polygon (filled).
     *
     * @return string The path (invalid points are left for PathParser to report).
     */
    private static function path(mixed $positions, array $properties, bool $filled): string
    {
        $path = 'color:' . self::color($properties['stroke'] ?? null, self::DEFAULTS['stroke'])
            . self::alpha($properties['stroke-opacity'] ?? null, self::DEFAULTS['stroke-opacity'])
            . ';weight:' . self::number($properties['stroke-width'] ?? self::DEFAULTS['stroke-width']);
        if ($filled) {
            $path .= ';fillcolor:' . self::color($properties['fill'] ?? null, self::DEFAULTS['fill'])
                . self::alpha($properties['fill-opacity'] ?? null, self::DEFAULTS['fill-opacity']);
        }
        $path .= ';';
        foreach (self::lists($positions) as $position) {
            $position = array_values($position);
            $path .= '[' . self::number($position[0] ?? null) . ',' . self::number($position[1] ?? null) . ']';
        }

        return $path;
    }

    /**
     * The members of a GeoJSON list that are lists themselves.
     *
     * @param mixed $value A list (or anything else).
     *
     * @return list<array<mixed>> Its array members.
     */
    private static function lists(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * A simplestyle colour as RRGGBB.
     *
     * @param mixed  $value   The colour, `#rrggbb` or `#rgb` (the `#` is optional), or null.
     * @param string $default RRGGBB used when the value is missing.
     *
     * @return string RRGGBB; an invalid value is passed on (without `;` and `:`) so the parser reports it.
     */
    private static function color(mixed $value, string $default): string
    {
        if ($value === null) {
            return $default;
        }
        $hex = ltrim(self::text($value), '#');
        if (preg_match('/^[0-9a-fA-F]{3}$/', $hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return $hex;
    }

    /**
     * The AA part of an RRGGBBAA colour for an opacity.
     *
     * @param mixed $value   Opacity from 0 to 1, or null.
     * @param float $default Opacity used when the value is missing or not a number.
     *
     * @return string Two hex digits, or '' for fully opaque.
     */
    private static function alpha(mixed $value, float $default): string
    {
        $opacity = is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
            ? (float) $value
            : $default;
        $opacity = max(0.0, min(1.0, $opacity));

        return $opacity >= 1 ? '' : sprintf('%02x', (int) round($opacity * 255));
    }

    /**
     * A number written the way the marker and path syntax reads it.
     *
     * @param mixed $value A number.
     *
     * @return string Plain decimal notation (no exponent), or the value as text if it isn't a number.
     */
    private static function number(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            $text = rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');

            return $text === '-0' ? '0' : $text;
        }

        return self::text($value);
    }

    /**
     * A value as text that can't break the `key:value;` syntax.
     *
     * @param mixed $value The value.
     *
     * @return string It as a string, without `;`, `:`, `[` or `]`.
     */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? str_replace([';', ':', '[', ']'], '', (string) $value) : '';
    }
}

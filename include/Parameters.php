<?php

/**
 * Checked numeric request parameters.
 */

namespace StaticMaps;

/**
 * The numeric parameters of a map request, checked and converted (#9).
 *
 * An empty value (`width=`) counts as not given. A value that isn't a number,
 * or is out of range, is a bad request: parse() throws InvalidRequest with a
 * message for the client, and the renderer answers 400. `width` and `height`
 * above the install's `limits` are not an error: they are reduced to the
 * limit, and sizeHeaders() reports it.
 */
final class Parameters
{
    /** @var float Highest latitude Web Mercator can show, in degrees. */
    public const MAX_LATITUDE = 85.0511287798;

    /** @var int Highest zoom level accepted in `zoom` and `maxzoom`. */
    public const MAX_ZOOM = 30;

    /**
     * @param int          $width        Image width in pixels, after limits.
     * @param int          $height       Image height in pixels, after limits.
     * @param list<string> $sizeHeaders  Headers to send if the size was reduced.
     * @param float|null   $latitude     Centre latitude in degrees, or null if not given.
     * @param float|null   $longitude    Centre longitude in degrees, or null if not given.
     * @param int|null     $zoom         Requested zoom, or null to fit the markers and paths.
     * @param int|null     $maxzoom      Highest zoom to use, or null for no extra limit.
     * @param int          $quality      JPEG and WebP quality, 1-100.
     * @param float        $bezier       Curve angle for paths in degrees; 0 for straight lines.
     * @param list<float>|null $bbox     Area to show: [minLng, minLat, maxLng, maxLat] in degrees
     *                                   (note: longitude first), or null.
     * @param list<array{float, float}> $visible Points to keep in view without drawing them,
     *                                   each [lat, lng] in degrees (note: latitude first).
     * @param array{int, int, int, int} $padding Space to keep around fitted content: top, right,
     *                                   bottom, left, in pixels.
     */
    private function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly array $sizeHeaders,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
        public readonly ?int $zoom,
        public readonly ?int $maxzoom,
        public readonly int $quality,
        public readonly float $bezier,
        public readonly ?array $bbox = null,
        public readonly array $visible = [],
        public readonly array $padding = [0, 0, 0, 0]
    ) {
    }

    /**
     * Check and convert the numeric parameters of a request.
     *
     * @param Request              $request The request.
     * @param array<string, mixed> $config  Configuration from Config::load() (`defaults`, `limits`).
     *
     * @return self The checked values.
     *
     * @throws InvalidRequest With a message for the client if a value is invalid.
     */
    public static function parse(Request $request, array $config): self
    {
        $latitude = self::number($request, 'latitude', -self::MAX_LATITUDE, self::MAX_LATITUDE);
        $longitude = self::number($request, 'longitude', -180, 180);
        if (($latitude === null) !== ($longitude === null)) {
            throw new InvalidRequest('latitude and longitude must be given together');
        }
        // center=lat,lng is shorthand for latitude and longitude (#35).
        $center = self::points($request, 'center', 1);
        if ($center !== null) {
            if ($latitude !== null) {
                throw new InvalidRequest('give center or latitude and longitude, not both');
            }
            [$latitude, $longitude] = $center[0];
        }

        $requestedWidth = self::integer($request, 'width', 1, PHP_INT_MAX) ?? (int) $config['defaults']['width'];
        $requestedHeight = self::integer($request, 'height', 1, PHP_INT_MAX) ?? (int) $config['defaults']['height'];
        $width = min($requestedWidth, (int) $config['limits']['max_width']);
        $height = min($requestedHeight, (int) $config['limits']['max_height']);
        $sizeHeaders = [];
        if ($width !== $requestedWidth || $height !== $requestedHeight) {
            $sizeHeaders[] = 'X-Map-Size: clamped; requested=' . $requestedWidth . 'x' . $requestedHeight
                . '; drawn=' . $width . 'x' . $height;
        }

        $bezier = self::number($request, 'bezier', -89, 89) ?? 0.0;
        $padding = self::padding($request->get('padding', false)) ?? self::padding($config['defaults']['padding']);

        return new self(
            $width,
            $height,
            $sizeHeaders,
            $latitude,
            $longitude,
            self::integer($request, 'zoom', 0, self::MAX_ZOOM),
            self::integer($request, 'maxzoom', 0, self::MAX_ZOOM),
            self::integer($request, 'quality', 1, 100) ?? (int) $config['defaults']['jpeg_quality'],
            $bezier,
            self::bbox($request),
            self::points($request, 'visible', PHP_INT_MAX) ?? [],
            $padding ?? [0, 0, 0, 0]
        );
    }

    /**
     * Parse `padding`: 1 to 4 whole numbers of pixels, comma-separated, in CSS order
     * (one value for all sides; two: top/bottom, left/right; three: top, left/right,
     * bottom; four: top, right, bottom, left).
     *
     * @param mixed $value The parameter or setting: a string, an int, or false/'' for none.
     *
     * @return array{int, int, int, int}|null Top, right, bottom and left in pixels, or null
     *                                        if not given.
     *
     * @throws InvalidRequest If it isn't 1-4 whole numbers from 0 to 1000.
     */
    public static function padding(mixed $value): ?array
    {
        if ($value === false || $value === null || $value === '') {
            return null;
        }
        $parts = is_int($value) ? [(string) $value] : (is_string($value) ? explode(',', $value) : []);
        $values = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (!ctype_digit($part) || (int) $part > 1000) {
                $values = [];
                break;
            }
            $values[] = (int) $part;
        }
        if ($values === [] || count($values) > 4) {
            throw new InvalidRequest('padding must be 1 to 4 comma-separated whole numbers of pixels from 0 to 1000');
        }

        [$top, $right, $bottom, $left] = match (count($values)) {
            1 => [$values[0], $values[0], $values[0], $values[0]],
            2 => [$values[0], $values[1], $values[0], $values[1]],
            3 => [$values[0], $values[1], $values[2], $values[1]],
            default => $values,
        };

        return [$top, $right, $bottom, $left];
    }

    /**
     * Parse `bbox=minLng,minLat,maxLng,maxLat` (#35).
     *
     * @param Request $request The request.
     *
     * @return list<float>|null [minLng, minLat, maxLng, maxLat] in degrees, or null if not given.
     *
     * @throws InvalidRequest If it isn't four numbers in range with minLat <= maxLat.
     */
    private static function bbox(Request $request): ?array
    {
        $value = $request->get('bbox', false);
        if ($value === false || $value === '') {
            return null;
        }
        $parts = is_string($value) ? array_map('trim', explode(',', $value)) : [];
        $valid = count($parts) === 4
            && Parameters::isValidPoint($parts[1], $parts[0])
            && Parameters::isValidPoint($parts[3], $parts[2])
            && (float) $parts[1] <= (float) $parts[3];
        if (!$valid) {
            throw new InvalidRequest(
                'bbox must be minLng,minLat,maxLng,maxLat: longitudes -180 to 180, latitudes -85.0511 to 85.0511'
                . ', minLat no more than maxLat'
            );
        }

        return array_map('floatval', $parts);
    }

    /**
     * Parse a list of `lat,lng` points separated by `|` (`center`, `visible`).
     *
     * @param Request $request The request.
     * @param string  $name    Parameter name.
     * @param int     $max     Most points allowed.
     *
     * @return list<array{float, float}>|null The points as [lat, lng] in degrees, or null if
     *                                        not given.
     *
     * @throws InvalidRequest If a point isn't `lat,lng` in range, or there are too many.
     */
    private static function points(Request $request, string $name, int $max): ?array
    {
        $value = $request->get($name, false);
        if ($value === false || $value === '') {
            return null;
        }
        $points = [];
        foreach (is_string($value) ? explode('|', $value) : [null] as $point) {
            $pair = $point === null ? [] : array_map('trim', explode(',', $point));
            if (count($pair) !== 2 || !Parameters::isValidPoint($pair[0], $pair[1])) {
                $what = $max === 1 ? 'lat,lng' : 'lat,lng points separated by |';
                throw new InvalidRequest(
                    "$name must be $what (latitude -85.0511 to 85.0511, longitude -180 to 180)"
                );
            }
            $points[] = [(float) $pair[0], (float) $pair[1]];
        }
        if (count($points) > $max) {
            throw new InvalidRequest("$name must be one lat,lng point");
        }

        return $points;
    }

    /**
     * Whether a point is a valid latitude/longitude pair.
     *
     * Used for marker and path coordinates, which are skipped (not rejected) when invalid.
     *
     * @param mixed $lat Latitude in degrees.
     * @param mixed $lng Longitude in degrees.
     *
     * @return bool True if both are numbers in range.
     */
    public static function isValidPoint(mixed $lat, mixed $lng): bool
    {
        return (is_int($lat) || is_float($lat) || (is_string($lat) && is_numeric(trim($lat))))
            && (is_int($lng) || is_float($lng) || (is_string($lng) && is_numeric(trim($lng))))
            && abs((float) $lat) <= self::MAX_LATITUDE
            && abs((float) $lng) <= 180;
    }

    /**
     * A decimal parameter.
     *
     * @param Request   $request The request.
     * @param string    $name    Parameter name.
     * @param int|float $min     Lowest allowed value.
     * @param int|float $max     Highest allowed value.
     *
     * @return float|null The value, or null if not given or empty.
     *
     * @throws InvalidRequest If it isn't a number in range.
     */
    private static function number(Request $request, string $name, int|float $min, int|float $max): ?float
    {
        $value = $request->get($name);
        if ($value === false || $value === '') {
            return null;
        }
        if (!is_string($value) || !is_numeric(trim($value)) || !is_finite((float) $value)) {
            throw new InvalidRequest("$name must be a number");
        }
        $number = (float) $value;
        if ($number < $min || $number > $max) {
            throw new InvalidRequest("$name must be between " . self::format($min) . ' and ' . self::format($max));
        }

        return $number;
    }

    /**
     * A whole-number parameter.
     *
     * @param Request $request The request.
     * @param string  $name    Parameter name.
     * @param int     $min     Lowest allowed value.
     * @param int     $max     Highest allowed value.
     *
     * @return int|null The value, or null if not given or empty.
     *
     * @throws InvalidRequest If it isn't a whole number in range.
     */
    private static function integer(Request $request, string $name, int $min, int $max): ?int
    {
        $value = $request->get($name);
        if ($value === false || $value === '') {
            return null;
        }
        if (!is_string($value) || !preg_match('/^\s*[+-]?\d+\s*$/', $value)) {
            throw new InvalidRequest("$name must be a whole number");
        }
        $digits = ltrim(trim($value), '+-');
        if (strlen(ltrim($digits, '0')) > 18) {
            // Too long for an int: as large (or small) as possible, then range-checked.
            $number = str_starts_with(trim($value), '-') ? PHP_INT_MIN : PHP_INT_MAX;
        } else {
            $number = (int) $value;
        }
        if ($number < $min || $number > $max) {
            throw new InvalidRequest(
                $max === PHP_INT_MAX ? "$name must be $min or more" : "$name must be between $min and $max"
            );
        }

        return $number;
    }

    /**
     * Format a limit for an error message.
     *
     * @param int|float $value The limit.
     *
     * @return string E.g. `85.0511` or `180`.
     */
    private static function format(int|float $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }
}

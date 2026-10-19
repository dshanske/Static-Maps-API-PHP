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
     * @param int          $quality      JPEG quality, 1-100.
     * @param float        $bezier       Curve angle for paths in degrees; 0 for straight lines.
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
        public readonly float $bezier
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

        return new self(
            $width,
            $height,
            $sizeHeaders,
            $latitude,
            $longitude,
            self::integer($request, 'zoom', 0, self::MAX_ZOOM),
            self::integer($request, 'maxzoom', 0, self::MAX_ZOOM),
            self::integer($request, 'quality', 1, 100) ?? (int) $config['defaults']['jpeg_quality'],
            $bezier
        );
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

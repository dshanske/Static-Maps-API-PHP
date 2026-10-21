<?php

/**
 * The optional scale bar (#102).
 */

namespace StaticMaps;

use GdImage;

/**
 * Chooses and draws a scale bar: a round distance (1, 2 or 5 × 10ⁿ metres,
 * kilometres, feet or miles) with its bar, on a light strip in a corner, in
 * the attribution's style.
 *
 * The scale is measured at the map's centre. In Web Mercator it changes with
 * latitude, so a map whose scale at its top or bottom edge differs from the
 * centre's by more than MAX_VARIATION gets no scale bar (low zooms, and maps
 * far from the equator at middle zooms): one bar would be misleading there.
 */
final class ScaleBar
{
    /** @var list<string> Accepted values of `scalebar`. */
    public const UNITS = ['metric', 'imperial', 'both'];

    /** @var float Largest change of scale across the map's height, as a fraction, for a bar to be drawn. */
    public const MAX_VARIATION = 0.10;

    /** @var float Metres per pixel at zoom 0 on the equator (Earth's circumference / 256). */
    private const METRES_PER_PIXEL = 156543.03392804097;

    /** @var int Longest bar in CSS pixels. */
    private const MAX_LENGTH = 150;

    /** @var int Space around the bars inside the strip, in CSS pixels. */
    private const PADDING = 3;

    /**
     * Ground distance per CSS pixel at a latitude.
     *
     * @param float     $latitude Latitude in degrees.
     * @param int|float $zoom     Zoom level.
     *
     * @return float Metres per pixel.
     */
    public static function metresPerPixel(float $latitude, int|float $zoom): float
    {
        return self::METRES_PER_PIXEL * cos(deg2rad($latitude)) / 2 ** $zoom;
    }

    /**
     * How much the scale changes between the centre and the top or bottom edge.
     *
     * @param float $latitude Centre latitude in degrees.
     * @param float $south    Latitude of the bottom edge.
     * @param float $north    Latitude of the top edge.
     *
     * @return float The largest change, as a fraction of the centre's scale (0.1 is 10%).
     */
    public static function variation(float $latitude, float $south, float $north): float
    {
        $centre = cos(deg2rad($latitude));
        if ($centre <= 0) {
            return INF;
        }

        return max(abs(1 - cos(deg2rad($south)) / $centre), abs(1 - cos(deg2rad($north)) / $centre));
    }

    /**
     * The largest round number (1, 2 or 5 × 10ⁿ) not above a value.
     *
     * @param float $value A positive number.
     *
     * @return float The round number.
     */
    public static function round(float $value): float
    {
        $power = 10 ** floor(log10($value));
        $digit = $value / $power;
        $digit = $digit >= 5 ? 5 : ($digit >= 2 ? 2 : 1);

        return $digit * $power;
    }

    /**
     * The bars to draw.
     *
     * @param string $units          Units: `metric`, `imperial` or `both` (metric first).
     * @param float  $metresPerPixel Ground distance per CSS pixel at the centre.
     * @param int    $width          Map width in CSS pixels.
     *
     * @return list<array{length: float, label: string}> Each bar's length in CSS pixels and its label,
     *         e.g. "500 m", "2 km", "1000 ft", "5 mi".
     */
    public static function bars(string $units, float $metresPerPixel, int $width): array
    {
        $maxLength = min(self::MAX_LENGTH, max(40, intdiv($width, 4)));
        $maxMetres = $maxLength * $metresPerPixel;
        $bars = [];
        if ($units !== 'imperial') {
            $metres = self::round($maxMetres);
            $bars[] = [
                'length' => $metres / $metresPerPixel,
                'label'  => $metres >= 1000 ? self::number($metres / 1000) . ' km' : self::number($metres) . ' m',
            ];
        }
        if ($units !== 'metric') {
            $feet = $maxMetres / 0.3048;
            if ($feet > 5280) {
                $miles = self::round($feet / 5280);
                $bars[] = ['length' => $miles * 1609.344 / $metresPerPixel, 'label' => self::number($miles) . ' mi'];
            } else {
                $feet = self::round($feet);
                $bars[] = ['length' => $feet * 0.3048 / $metresPerPixel, 'label' => self::number($feet) . ' ft'];
            }
        }

        return $bars;
    }

    /**
     * Draw the bars on a light strip in a corner.
     *
     * The strip is flush with the corner, like the attribution's. If it would
     * overlap what the attribution drew, it moves past it: up from a bottom
     * corner, down from a top one.
     *
     * @param GdImage                       $im       The map image.
     * @param list<array{length: float, label: string}> $bars From bars().
     * @param string                        $position Corner: one of Attribution::POSITIONS.
     * @param int                           $width    Image width in pixels (after `scale`).
     * @param int                           $height   Image height in pixels.
     * @param int                           $scale    Pixel density: lengths and text are this many times larger.
     * @param array{int, int, int, int}|null $avoid   Box the attribution drew (x1, y1, x2, y2), or null.
     *
     * @return void
     */
    public static function draw(
        GdImage $im,
        array $bars,
        string $position,
        int $width,
        int $height,
        int $scale,
        ?array $avoid
    ): void {
        if ($bars === []) {
            return;
        }
        $size = (intdiv($width, $scale) < 250 ? 6.0 : 7.0) * $scale;
        $padding = self::PADDING * $scale;
        $gap = 4 * $scale;
        $box = (array) imagettfbbox($size, 0, Attribution::FONT, 'Ag');
        $rowHeight = (int) ceil(($box[1] - $box[7]) * 1.15) + $scale;
        $longest = (int) ceil(max(array_column($bars, 'length')) * $scale);
        $labelWidth = 0;
        foreach ($bars as $bar) {
            $labelBox = (array) imagettfbbox($size, 0, Attribution::FONT, $bar['label']);
            $labelWidth = max($labelWidth, (int) ($labelBox[2] - $labelBox[0]));
        }

        $stripWidth = min($width, $padding + $longest + $gap + $labelWidth + $padding);
        $stripHeight = min($height, count($bars) * $rowHeight + $padding);
        $left = str_ends_with($position, 'left');
        $top = str_starts_with($position, 'top');
        $x = $left ? 0 : $width - $stripWidth;
        $y = $top ? 0 : $height - $stripHeight;
        if (
            $avoid !== null
            && $x < $avoid[2] && $avoid[0] < $x + $stripWidth
            && $y < $avoid[3] && $avoid[1] < $y + $stripHeight
        ) {
            $y = $top ? $avoid[3] + $scale : $avoid[1] - $stripHeight - $scale;
        }

        // As the attribution: a 75% opaque white strip, dark gray lines and text.
        $background = (int) imagecolorallocatealpha($im, 255, 255, 255, 32);
        $color = (int) imagecolorallocate($im, 51, 51, 51);
        imagefilledrectangle($im, $x, $y, $x + $stripWidth - 1, $y + $stripHeight - 1, $background);
        imagesetthickness($im, $scale);
        foreach ($bars as $i => $bar) {
            $line = $y + $padding + ($i + 1) * $rowHeight - 2 * $scale;
            $start = $x + $padding;
            $end = $start + (int) round($bar['length'] * $scale);
            imageline($im, $start, $line, $end, $line, $color);
            imageline($im, $start, $line - 4 * $scale, $start, $line, $color);
            imageline($im, $end, $line - 4 * $scale, $end, $line, $color);
            imagettftext($im, $size, 0, $start + $longest + $gap, $line, $color, Attribution::FONT, $bar['label']);
        }
        imagesetthickness($im, 1);
    }

    /**
     * A round distance as text.
     *
     * @param float $value The distance.
     *
     * @return string Without trailing zeros, e.g. "500", "0.5".
     */
    private static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');
    }
}

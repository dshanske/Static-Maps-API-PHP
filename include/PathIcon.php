<?php

/**
 * Vehicle icons along a path (#106).
 */

namespace StaticMaps;

use GdImage;

/**
 * A plane, train, car, bus or ship on a path: a round badge in a colour,
 * with a white outline and a white glyph, at a percentage of the path's
 * length on the map. The plane turns to the path's direction there; the
 * others, front and side views, stay upright.
 *
 * The glyphs are Mapbox's Maki icons (CC0), rendered once to PNG in
 * assets/path-icons/ (their SVG sources are in assets/path-icons/src/).
 */
final class PathIcon
{
    /** @var list<string> Accepted values of a path's `icon`. */
    public const TYPES = ['plane', 'train', 'car', 'bus', 'ship'];

    /** @var list<string> Icons that turn to the path's direction (seen from above, nose up). */
    private const TURNING = ['plane'];

    /** @var string Directory with the glyphs: white on transparent, 64 px square. */
    private const DIR = __DIR__ . '/../assets/path-icons';

    /** @var int Badge diameter in CSS pixels. */
    public const SIZE = 20;

    /** @var int Most positions on one path. */
    public const MAX_POSITIONS = 5;

    /** @var int Supersampling factor for smooth edges. */
    private const OVERSAMPLE = 4;

    /**
     * Where a percentage of a line's length is, and which way the line runs there.
     *
     * @param list<array{x: int|float, y: int|float}> $pixels  The line as drawn, in pixels, in path order.
     * @param float                                   $percent Percent of its length: 0 (the first point) to 100.
     *
     * @return array{x: float, y: float, angle: float}|null The point, and the line's direction there
     *         in degrees clockwise from east (y grows downwards); null for a line without length.
     */
    public static function along(array $pixels, float $percent): ?array
    {
        $lengths = [];
        $total = 0.0;
        for ($i = 1, $n = count($pixels); $i < $n; $i++) {
            $length = hypot($pixels[$i]['x'] - $pixels[$i - 1]['x'], $pixels[$i]['y'] - $pixels[$i - 1]['y']);
            $lengths[$i] = $length;
            $total += $length;
        }
        if ($total <= 0) {
            return null;
        }

        $target = $total * max(0.0, min(100.0, $percent)) / 100;
        $walked = 0.0;
        foreach ($lengths as $i => $length) {
            if ($length <= 0) {
                continue;
            }
            if ($walked + $length >= $target || $i === count($pixels) - 1) {
                $f = min(1.0, max(0.0, ($target - $walked) / $length));
                $a = $pixels[$i - 1];
                $b = $pixels[$i];

                return [
                    'x'     => $a['x'] + ($b['x'] - $a['x']) * $f,
                    'y'     => $a['y'] + ($b['y'] - $a['y']) * $f,
                    'angle' => rad2deg(atan2($b['y'] - $a['y'], $b['x'] - $a['x'])),
                ];
            }
            $walked += $length;
        }

        return null;
    }

    /**
     * Draw an icon centred on a point.
     *
     * @param GdImage $im    The map image.
     * @param string  $type  One of TYPES.
     * @param string  $color Badge colour, RRGGBB.
     * @param float   $x     Centre x in image pixels.
     * @param float   $y     Centre y in image pixels.
     * @param float   $angle The path's direction there, in degrees clockwise from east.
     * @param int     $scale Pixel density: the badge is this many times larger.
     *
     * @return void
     */
    public static function draw(
        GdImage $im,
        string $type,
        string $color,
        float $x,
        float $y,
        float $angle,
        int $scale
    ): void {
        $badge = self::badge($type, $color, in_array($type, self::TURNING, true) ? $angle + 90 : 0.0, $scale);
        $size = imagesx($badge);
        imagecopy($im, $badge, (int) round($x - $size / 2), (int) round($y - $size / 2), 0, 0, $size, $size);
    }

    /**
     * The badge image.
     *
     * @param string $type     One of TYPES.
     * @param string $color    Badge colour, RRGGBB.
     * @param float  $rotation Clockwise rotation of the glyph in degrees.
     * @param int    $scale    Pixel density.
     *
     * @return GdImage The badge, SIZE × scale pixels square, transparent outside the circle.
     */
    private static function badge(string $type, string $color, float $rotation, int $scale): GdImage
    {
        $size = self::SIZE * $scale;
        $big = $size * self::OVERSAMPLE;
        $canvas = imagecreatetruecolor($big, $big);
        imagealphablending($canvas, false);
        imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);

        // White outline, then the coloured disc.
        $rgb = array_map('hexdec', str_split($color, 2));
        $white = (int) imagecolorallocate($canvas, 255, 255, 255);
        imagefilledellipse($canvas, intdiv($big, 2), intdiv($big, 2), $big - 2, $big - 2, $white);
        $ring = (int) round($big * 0.1);
        imagefilledellipse(
            $canvas,
            intdiv($big, 2),
            intdiv($big, 2),
            $big - 2 * $ring,
            $big - 2 * $ring,
            (int) imagecolorallocate($canvas, $rgb[0], $rgb[1], $rgb[2])
        );

        // The glyph, 60% of the badge, turned if asked.
        $glyph = imagecreatefrompng(self::DIR . "/$type.png");
        if ($glyph !== false) {
            $glyphSize = (int) round($big * 0.6);
            $scaled = imagecreatetruecolor($glyphSize, $glyphSize);
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            imagefill($scaled, 0, 0, (int) imagecolorallocatealpha($scaled, 0, 0, 0, 127));
            imagecopyresampled($scaled, $glyph, 0, 0, 0, 0, $glyphSize, $glyphSize, imagesx($glyph), imagesy($glyph));
            if (abs(fmod($rotation, 360)) > 0.01) {
                // imagerotate() turns anticlockwise.
                $turned = imagerotate($scaled, -$rotation, (int) imagecolorallocatealpha($scaled, 0, 0, 0, 127));
                if ($turned !== false) {
                    $scaled = $turned;
                }
            }
            $w = imagesx($scaled);
            $h = imagesy($scaled);
            imagecopy($canvas, $scaled, intdiv($big - $w, 2), intdiv($big - $h, 2), 0, 0, $w, $h);
        }

        $badge = imagecreatetruecolor($size, $size);
        imagealphablending($badge, false);
        imagesavealpha($badge, true);
        imagefill($badge, 0, 0, (int) imagecolorallocatealpha($badge, 0, 0, 0, 127));
        imagecopyresampled($badge, $canvas, 0, 0, 0, 0, $size, $size, $big, $big);
        imagealphablending($badge, true);

        return $badge;
    }
}

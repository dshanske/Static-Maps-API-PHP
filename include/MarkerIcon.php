<?php

/**
 * Marker icons: generated pins, dots and labels, and placing any icon on the map.
 */

namespace StaticMaps;

use GdImage;
use Imagick;
use ImagickDraw;
use ImagickPixel;

/**
 * Draws generated marker icons (#37) and works out how any icon is placed.
 *
 * A marker carries its icon image, the image's pixel density (pixels per CSS
 * pixel), an anchor and its own `scale`, `rotation` and `opacity`. place()
 * turns that into the image to copy onto the map and the anchor point within
 * it; drawing (Compositor) and fitting (Viewport) both use it, so they agree.
 */
final class MarkerIcon
{
    /** @var list<string> Generated icon types: a pin, a dot, or a text label alone. */
    public const TYPES = ['pin', 'dot', 'none'];

    /** @var array<string, int> Pin heights in CSS pixels, by `size`. */
    public const PIN_SIZES = ['small' => 30, 'medium' => 40, 'large' => 50];

    /** @var array<string, int> Dot diameters in CSS pixels, by `size`. */
    public const DOT_SIZES = ['small' => 12, 'medium' => 18, 'large' => 24];

    /** @var array<string, array{float, float}> Named anchors as fractions of the icon's width and height. */
    public const ANCHORS = [
        'center'      => [0.5, 0.5],
        'top'         => [0.5, 0.0],
        'bottom'      => [0.5, 1.0],
        'left'        => [0.0, 0.5],
        'right'       => [1.0, 0.5],
        'topleft'     => [0.0, 0.0],
        'topright'    => [1.0, 0.0],
        'bottomleft'  => [0.0, 1.0],
        'bottomright' => [1.0, 1.0],
    ];

    /** @var string Default colour of generated pins and dots. */
    public const DEFAULT_COLOR = 'd32f2f';

    /** @var int Longest label on a pin or dot, in characters. */
    public const MAX_PIN_LABEL = 3;

    /** @var int Longest text label (`icon:none`), in characters. */
    public const MAX_TEXT_LABEL = 32;

    /**
     * Draw a generated icon.
     *
     * @param string      $type       One of TYPES.
     * @param string      $color      Fill colour, RRGGBB.
     * @param string      $size       Size: `small`, `medium` or `large`.
     * @param string|null $label      Text on the pin or dot, or the text of an `icon:none` label.
     * @param string|null $labelColor Label colour RRGGBB, or null for white or dark to suit the fill.
     * @param int         $density    Pixels per CSS pixel to draw at (the request's `scale`), for sharp icons.
     *
     * @return array{GdImage, array{string, float, float}} The icon, and its default anchor (see place()).
     */
    public static function generate(
        string $type,
        string $color,
        string $size,
        ?string $label,
        ?string $labelColor,
        int $density
    ): array {
        $d = $density;
        $draw = new ImagickDraw();
        $draw->setFont(Attribution::FONT);
        $textColor = new ImagickPixel('#' . ($labelColor ?? (self::isLight($color) ? '333333' : 'ffffff')));

        if ($type === 'none') {
            // A text label with a white halo, centred on the point.
            $fontSize = 12 * $d;
            $draw->setFontSize($fontSize);
            $metrics = (new Imagick())->queryFontMetrics($draw, (string) $label);
            $pad = 3 * $d;
            $w = (int) ceil($metrics['textWidth'] + 2 * $pad);
            $h = (int) ceil($metrics['textHeight'] + 2 * $pad);
            $mg = self::canvas($w, $h);
            $draw->setGravity(Imagick::GRAVITY_CENTER);
            $draw->setStrokeColor(new ImagickPixel('white'));
            $draw->setStrokeWidth(3 * $d);
            $draw->setFillColor(new ImagickPixel('white'));
            $mg->annotateImage($draw, 0, 0, 0, (string) $label);
            $text = new ImagickDraw();
            $text->setFont(Attribution::FONT);
            $text->setFontSize($fontSize);
            $text->setGravity(Imagick::GRAVITY_CENTER);
            $text->setFillColor(new ImagickPixel('#' . ($labelColor ?? '333333')));
            $mg->annotateImage($text, 0, 0, 0, (string) $label);

            return [self::toGd($mg), ['frac', 0.5, 0.5]];
        }

        $stroke = 1.5 * $d;
        $fill = new ImagickPixel('#' . $color);
        $outline = new ImagickPixel('#' . self::darker($color));
        if ($type === 'dot') {
            $diameter = self::DOT_SIZES[$size] * $d;
            $w = $h = (int) ceil($diameter + 2 * $stroke);
            $mg = self::canvas($w, $h);
            $draw->setFillColor($fill);
            $draw->setStrokeColor(new ImagickPixel('white'));
            $draw->setStrokeWidth(2 * $stroke);
            $draw->circle($w / 2, $h / 2, $w / 2, $stroke);
            $mg->drawImage($draw);
            self::drawLabel($mg, $label, $textColor, $w / 2, $h / 2, $diameter / 2);

            return [self::toGd($mg), ['frac', 0.5, 0.5]];
        }

        // A pin: a circle on top of a point, outlined, with a white centre or the label.
        $h = (int) ceil(self::PIN_SIZES[$size] * $d);
        $w = (int) ceil($h * 0.64);
        $mg = self::canvas($w, $h);
        $r = $w / 2 - $stroke;
        $cx = $w / 2;
        $cy = $r + $stroke;
        $tip = $h - $stroke;
        // Where lines from the tip touch the circle.
        $angle = acos($r / ($tip - $cy));
        $dx = $r * sin($angle);
        $dy = $r * cos($angle);
        $draw->setFillColor($fill);
        $draw->setStrokeColor($outline);
        $draw->setStrokeWidth($stroke);
        $draw->setStrokeLineJoin(Imagick::LINEJOIN_ROUND);
        $draw->pathStart();
        $draw->pathMoveToAbsolute($cx, $tip);
        $draw->pathLineToAbsolute($cx - $dx, $cy + $dy);
        $draw->pathEllipticArcAbsolute($r, $r, 0, true, true, $cx + $dx, $cy + $dy);
        $draw->pathClose();
        $draw->pathFinish();
        if ($label === null) {
            $draw->setFillColor(new ImagickPixel('white'));
            $draw->setStrokeOpacity(0);
            $draw->circle($cx, $cy, $cx + $r * 0.38, $cy);
        }
        $mg->drawImage($draw);
        self::drawLabel($mg, $label, $textColor, $cx, $cy, $r);

        return [self::toGd($mg), ['frac', 0.5, 1.0]];
    }

    /**
     * The image to copy onto the map for a marker, and its anchor point.
     *
     * The icon is resized by the marker's `scale`, the request's `scale` (#10),
     * its own density, and 1/1.5 on maps under 120 CSS pixels; then rotated
     * about its anchor and faded by its opacity.
     *
     * @param array<string, mixed> $marker A marker from MarkerParser (`iconImg`, `density`, `anchor`,
     *                                     `scale`, `rotation`, `opacity`).
     * @param int|float            $width  Map width in CSS pixels (before the request's `scale`).
     * @param int|float            $height Map height in CSS pixels.
     * @param int                  $scale  The request's `scale`.
     *
     * @return array{GdImage, float, float} The image, and the anchor's x and y in it, in pixels.
     */
    public static function place(array $marker, int|float $width, int|float $height, int $scale): array
    {
        $icon = $marker['iconImg'];
        $shrink = ($width < 120 || $height < 120) ? 1.5 : 1;
        $factor = (float) ($marker['scale'] ?? 1) * $scale / (float) ($marker['density'] ?? 1) / $shrink;
        if (abs($factor - 1) > 1e-9) {
            $icon = self::resize($icon, $factor);
        }
        $w = imagesx($icon);
        $h = imagesy($icon);
        // Without an anchor: `dot-*` icons are centred, others anchored at their bottom centre.
        $anchor = $marker['anchor'] ?? (str_starts_with((string) ($marker['icon'] ?? ''), 'dot-')
            ? ['frac', 0.5, 0.5]
            : ['frac', 0.5, 1.0]);
        if ($anchor[0] === 'frac') {
            // As icons were always placed: the anchor rounded to whole pixels, the bottom edge exactly.
            $ax = (float) round($w * $anchor[1]);
            $ay = $anchor[2] == 1 ? (float) $h : (float) round($h * $anchor[2]);
        } else {
            $ax = $anchor[1] * $factor;
            $ay = $anchor[2] * $factor;
        }

        $rotation = (float) ($marker['rotation'] ?? 0);
        if ($rotation != 0) {
            [$icon, $ax, $ay] = self::rotate($icon, $rotation, $ax, $ay);
        }
        $opacity = (float) ($marker['opacity'] ?? 1);
        if ($opacity < 1) {
            $icon = self::fade($icon, $opacity);
        }

        return [$icon, $ax, $ay];
    }

    /**
     * Whether a colour is light enough to need a dark label.
     *
     * @param string $hex RRGGBB.
     *
     * @return bool True if its relative brightness is over 60%.
     */
    private static function isLight(string $hex): bool
    {
        [$r, $g, $b] = array_map('hexdec', str_split(substr($hex, 0, 6), 2));

        return (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255 > 0.6;
    }

    /**
     * A darker shade of a colour, for outlines.
     *
     * @param string $hex RRGGBB.
     *
     * @return string RRGGBB at 70% brightness.
     */
    private static function darker(string $hex): string
    {
        return implode('', array_map(
            static fn (string $c): string => sprintf('%02x', (int) (hexdec($c) * 0.7)),
            str_split(substr($hex, 0, 6), 2)
        ));
    }

    /**
     * Write a short label centred in a circle.
     *
     * @param Imagick      $mg     The icon.
     * @param string|null  $label  The label, or null for none.
     * @param ImagickPixel $color  Text colour.
     * @param float        $cx     Circle centre x in pixels.
     * @param float        $cy     Circle centre y in pixels.
     * @param float        $radius Circle radius in pixels.
     *
     * @return void
     */
    private static function drawLabel(
        Imagick $mg,
        ?string $label,
        ImagickPixel $color,
        float $cx,
        float $cy,
        float $radius
    ): void {
        if ($label === null) {
            return;
        }
        $text = new ImagickDraw();
        $text->setFont(Attribution::FONT);
        $text->setFillColor($color);
        $text->setFontSize($radius * (mb_strlen($label) > 1 ? 0.9 : 1.2));
        $text->setTextAntialias(true);
        $metrics = $mg->queryFontMetrics($text, $label);
        $x = $cx - $metrics['textWidth'] / 2;
        $y = $cy + ($metrics['ascender'] + $metrics['descender']) / 2;
        $mg->annotateImage($text, $x, $y, 0, $label);
    }

    /**
     * A transparent ImageMagick canvas.
     *
     * @param int $width  Width in pixels.
     * @param int $height Height in pixels.
     *
     * @return Imagick The canvas.
     */
    private static function canvas(int $width, int $height): Imagick
    {
        $mg = new Imagick();
        $mg->newImage(max(1, $width), max(1, $height), new ImagickPixel('none'));
        $mg->setImageFormat('png');

        return $mg;
    }

    /**
     * Convert an ImageMagick image to GD, keeping transparency.
     *
     * @param Imagick $mg The image.
     *
     * @return GdImage The image.
     */
    private static function toGd(Imagick $mg): GdImage
    {
        $im = imagecreatefromstring($mg->getImageBlob());
        if ($im === false) {
            $im = imagecreatetruecolor(1, 1);
        }
        imagealphablending($im, true);
        imagesavealpha($im, true);

        return $im;
    }

    /**
     * Resize an icon, keeping transparency.
     *
     * @param GdImage $icon   The icon.
     * @param float   $factor Size factor.
     *
     * @return GdImage The resized icon (at least 1x1).
     */
    private static function resize(GdImage $icon, float $factor): GdImage
    {
        $resized = imagecreatetruecolor(
            max(1, (int) round(imagesx($icon) * $factor)),
            max(1, (int) round(imagesy($icon) * $factor))
        );
        imagealphablending($resized, true);
        imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));
        imagecopyresampled(
            $resized,
            $icon,
            0,
            0,
            0,
            0,
            imagesx($resized),
            imagesy($resized),
            imagesx($icon),
            imagesy($icon)
        );

        return $resized;
    }

    /**
     * Rotate an icon clockwise about its anchor.
     *
     * @param GdImage $icon    The icon.
     * @param float   $degrees Clockwise rotation in degrees.
     * @param float   $ax      Anchor x in pixels.
     * @param float   $ay      Anchor y in pixels.
     *
     * @return array{GdImage, float, float} The rotated icon (on a larger canvas) and the anchor in it.
     */
    private static function rotate(GdImage $icon, float $degrees, float $ax, float $ay): array
    {
        $w = imagesx($icon);
        $h = imagesy($icon);
        imagealphablending($icon, false);
        imagesavealpha($icon, true);
        // GD rotates anticlockwise about the centre and grows the canvas to fit.
        $rotated = imagerotate($icon, -$degrees, (int) imagecolorallocatealpha($icon, 0, 0, 0, 127));
        if ($rotated === false) {
            return [$icon, $ax, $ay];
        }
        imagealphablending($rotated, true);
        imagesavealpha($rotated, true);
        $t = deg2rad($degrees);
        $x = $ax - $w / 2;
        $y = $ay - $h / 2;

        return [
            $rotated,
            imagesx($rotated) / 2 + $x * cos($t) - $y * sin($t),
            imagesy($rotated) / 2 + $x * sin($t) + $y * cos($t),
        ];
    }

    /**
     * Make an icon more transparent.
     *
     * @param GdImage $icon    The icon.
     * @param float   $opacity Opacity from 0 (invisible) to 1 (as it is).
     *
     * @return GdImage The faded icon.
     */
    private static function fade(GdImage $icon, float $opacity): GdImage
    {
        $w = imagesx($icon);
        $h = imagesy($icon);
        $faded = imagecreatetruecolor($w, $h);
        imagealphablending($faded, false);
        imagesavealpha($faded, true);
        for ($x = 0; $x < $w; $x++) {
            for ($y = 0; $y < $h; $y++) {
                $rgba = imagecolorsforindex($icon, imagecolorat($icon, $x, $y));
                $alpha = (int) round(127 - (127 - $rgba['alpha']) * $opacity);
                imagesetpixel(
                    $faded,
                    $x,
                    $y,
                    (int) imagecolorallocatealpha($faded, $rgba['red'], $rgba['green'], $rgba['blue'], $alpha)
                );
            }
        }
        imagealphablending($faded, true);

        return $faded;
    }
}

<?php

/**
 * Drawing tiles, paths and markers onto the map image.
 */

namespace StaticMaps;

use GdImage;
use Imagick;
use ImagickDraw;
use ImagickPixel;

/**
 * Draws the layers of a map onto a GD image.
 *
 * Positions are global pixel coordinates at the map's zoom level; $leftEdge
 * and $topEdge are the global pixel of the image's top-left corner.
 */
final class Compositor
{
    /**
     * @param WebMercator $webmercator Projection maths.
     */
    public function __construct(private WebMercator $webmercator)
    {
    }

    /**
     * Copy a block of tiles onto the image.
     *
     * @param GdImage                          $im      The map image.
     * @param array<int, array<int, GdImage>>  $tiles   Tiles indexed by column, then row.
     * @param array{x: float, y: float}        $tilePos Tile that contains the image centre.
     * @param array{x: float, y: float}        $pos     Position of the image centre inside that tile.
     * @param mixed                            $width   Image width in pixels.
     * @param mixed                            $height  Image height in pixels.
     * @param int                              $tileSize Size each tile is drawn at, in pixels; tiles of
     *                                                  another size are resized to it (scale=2, #10).
     *
     * @return void
     */
    public function drawTiles(
        GdImage $im,
        array $tiles,
        array $tilePos,
        array $pos,
        mixed $width,
        mixed $height,
        int $tileSize = WebMercator::TILE_SIZE
    ): void {
        foreach ($tiles as $x => $yTiles) {
            foreach ($yTiles as $y => $tile) {
                $x = intval($x);
                $y = intval($y);

                $ox = (($x - $tilePos['x']) * $tileSize) - $pos['x'] + ($width / 2);
                $oy = (($y - $tilePos['y']) * $tileSize) - $pos['y'] + ($height / 2);

                // Truncated to whole pixels, as PHP did implicitly before 8.1.
                if (imagesx($tile) === $tileSize && imagesy($tile) === $tileSize) {
                    imagecopy($im, $tile, (int) $ox, (int) $oy, 0, 0, $tileSize, $tileSize);
                } else {
                    imagecopyresampled(
                        $im,
                        $tile,
                        (int) $ox,
                        (int) $oy,
                        0,
                        0,
                        $tileSize,
                        $tileSize,
                        imagesx($tile),
                        imagesy($tile)
                    );
                }
            }
        }
    }

    /**
     * Draw paths with ImageMagick (for anti-aliasing) and copy them onto the image.
     *
     * Each path's style applies to it and stays set for later paths that don't
     * override it (one shared ImagickDraw).
     *
     * @param GdImage                     $im       The map image.
     * @param list<array<string, mixed>>  $paths    Paths from PathParser::parse().
     * @param mixed                       $zoom     Zoom level.
     * @param int|float                   $leftEdge Global pixel x of the image's left edge.
     * @param int|float                   $topEdge  Global pixel y of the image's top edge.
     * @param mixed                       $width    Image width in pixels.
     * @param mixed                       $height   Image height in pixels.
     * @param mixed                       $bezier   The `bezier` parameter: curve angle in degrees,
     *                                              or falsy for straight segments.
     * @param int                         $scale    Pixel density (#10): path widths are multiplied by it.
     *
     * @return void
     */
    public function drawPaths(
        GdImage $im,
        array $paths,
        mixed $zoom,
        int|float $leftEdge,
        int|float $topEdge,
        mixed $width,
        mixed $height,
        mixed $bezier,
        int $scale = 1
    ): void {
        if (!count($paths)) {
            return;
        }

        // Draw the path with ImageMagick because GD sucks as anti-aliased lines
        $mg = new Imagick();
        $mg->newImage($width, $height, new ImagickPixel('none'));

        $draw = new ImagickDraw();

        foreach ($paths as $path) {
            $draw->setStrokeColor(new ImagickPixel('#' . $path['color']));
            $draw->setStrokeWidth($path['weight'] * $scale);
            $draw->setFillOpacity(0);
            $draw->setStrokeLineCap(Imagick::LINECAP_ROUND);
            $draw->setStrokeLineJoin(Imagick::LINEJOIN_ROUND);

            // The first point on the copy of the world nearest the map centre, each
            // following one the short way round from the one before (#35).
            $from = null;
            foreach ($path['path'] as $point) {
                $to = $this->webmercator->latLngToPixels($point[1], $point[0], $zoom);
                $to['x'] = Viewport::nearest($to['x'], $from['x'] ?? $leftEdge + $width / 2, $zoom);
                if ($from !== null) {
                    if ($bezier) {
                        $this->drawCurve($draw, $from, $to, $bezier, $leftEdge, $topEdge);
                    } else {
                        $draw->line(
                            $from['x'] - $leftEdge,
                            $from['y'] - $topEdge,
                            $to['x'] - $leftEdge,
                            $to['y'] - $topEdge
                        );
                    }
                }
                $from = $to;
            }
        }

        $mg->drawImage($draw);
        $mg->setImageFormat("png");

        $pathImg = imagecreatefromstring($mg);
        imagecopy($im, $pathImg, 0, 0, 0, 0, $width, $height);
    }

    /**
     * The quadratic Bezier curve drawn between two points: its ends and control point.
     *
     * The ends are ordered left to right (or top to bottom when the segment is
     * steeper than 45 degrees), and the control point is off the midpoint at
     * `$bezier` degrees, so the curve bulges to the same side whichever way the
     * path runs.
     *
     * @param array{x: int|float, y: int|float} $from   One end in pixels.
     * @param array{x: int|float, y: int|float} $to     The other end in pixels.
     * @param mixed                             $bezier Curve angle in degrees (-89 to 89).
     *
     * @return array{array{x: int|float, y: int|float}, array{x: int|float, y: int|float},
     *               array{x: int|float, y: int|float}} Start, control point and end, in pixels.
     */
    public static function curve(array $from, array $to, mixed $bezier): array
    {
        $x_dist = abs($from['x'] - $to['x']);
        $y_dist = abs($from['y'] - $to['y']);

        // If the X distance is longer than Y distance, draw from left to right
        if ($x_dist > $y_dist) {
            // Draw from left to right
            if ($from['x'] > $to['x']) {
                [$from, $to] = [$to, $from];
            }
        } else {
            // Draw from top to bottom
            if ($from['y'] > $to['y']) {
                [$from, $to] = [$to, $from];
            }
        }

        $angle = 1 * $bezier;

        // Midpoint between the two ends
        $M = array(
            'x' => ($from['x'] + $to['x']) / 2,
            'y' => ($from['y'] + $to['y']) / 2
        );

        // Derived from http://math.stackexchange.com/a/383648 and
        // http://www.wolframalpha.com/input/?i=triangle+%5B1,1%5D+%5B5,2%5D+%5B1-1%2Fsqrt(3),1%2B4%2Fsqrt(3)%5D
        $A = $from;
        $B = $to;

        $P = array(
            'x' => ($M['x']) - (($A['y'] - $M['y']) * tan(deg2rad($angle))),
            'y' => ($M['y']) + (($A['x'] - $M['x']) * tan(deg2rad($angle)))
        );

        return [$A, $P, $B];
    }

    /**
     * The points of a curve that reach furthest on each axis (for fitting the map to it).
     *
     * @param array{x: int|float, y: int|float} $from   One end in pixels.
     * @param array{x: int|float, y: int|float} $to     The other end in pixels.
     * @param mixed                             $bezier Curve angle in degrees.
     *
     * @return list<array{x: float, y: float}> Up to two points on the curve, in pixels: where it
     *         turns in x and in y, if that happens between its ends.
     */
    public static function curveExtremes(array $from, array $to, mixed $bezier): array
    {
        [$A, $P, $B] = self::curve($from, $to, $bezier);
        $points = [];
        foreach (['x', 'y'] as $axis) {
            $d = $A[$axis] - 2 * $P[$axis] + $B[$axis];
            $t = $d == 0 ? -1 : ($A[$axis] - $P[$axis]) / $d;
            if ($t > 0 && $t < 1) {
                $points[] = [
                    'x' => (1 - $t) ** 2 * $A['x'] + 2 * $t * (1 - $t) * $P['x'] + $t ** 2 * $B['x'],
                    'y' => (1 - $t) ** 2 * $A['y'] + 2 * $t * (1 - $t) * $P['y'] + $t ** 2 * $B['y'],
                ];
            }
        }

        return $points;
    }

    /**
     * Add one quadratic Bézier segment between two points.
     *
     * Segments are drawn left to right (or top to bottom when taller than wide),
     * so the curve bends the same way whatever the point order.
     *
     * @see http://math.stackexchange.com/a/383648
     *
     * @param ImagickDraw              $draw     The drawing to add to.
     * @param array{x: float, y: float} $from     Global pixel of the segment start.
     * @param array{x: float, y: float} $to       Global pixel of the segment end.
     * @param mixed                    $bezier   Curve angle in degrees.
     * @param int|float                $leftEdge Global pixel x of the image's left edge.
     * @param int|float                $topEdge  Global pixel y of the image's top edge.
     *
     * @return void
     */
    private function drawCurve(
        ImagickDraw $draw,
        array $from,
        array $to,
        mixed $bezier,
        int|float $leftEdge,
        int|float $topEdge
    ): void {
        [$A, $P, $B] = self::curve($from, $to, $bezier);

        $draw->pathStart();
        $draw->pathMoveToAbsolute($A['x'] - $leftEdge, $A['y'] - $topEdge);
        $draw->pathCurveToQuadraticBezierAbsolute(
            $P['x'] - $leftEdge,
            $P['y'] - $topEdge,
            $B['x'] - $leftEdge,
            $B['y'] - $topEdge
        );

        $draw->pathFinish();
    }

    /**
     * Where a marker icon sits around its anchor point, as drawn by drawMarkers().
     *
     * Icons are shrunk by 1.5 on maps under 120 px wide or high. Icons with a
     * shadow are anchored at their bottom centre; `dot-*` icons at their centre.
     *
     * @param GdImage $icon   The icon image.
     * @param string  $name   The icon name (or URL); a `dot-` prefix means centre-anchored.
     * @param int     $width  Map width in pixels.
     * @param int     $height Map height in pixels.
     *
     * @return array{top: int, right: int, bottom: int, left: int} Pixels the icon reaches from
     *         its anchor in each direction.
     */
    public static function markerBox(GdImage $icon, string $name, int $width, int $height): array
    {
        $shrink = ($width < 120 || $height < 120) ? 1.5 : 1;
        $w = $shrink > 1 ? max(1, (int) round(imagesx($icon) / $shrink)) : imagesx($icon);
        $h = $shrink > 1 ? max(1, (int) round(imagesy($icon) / $shrink)) : imagesy($icon);
        $left = (int) round($w / 2);
        $top = preg_match('/^dot-/', $name) ? (int) round($h / 2) : $h;

        return ['top' => $top, 'right' => $w - $left, 'bottom' => $h - $top, 'left' => $left];
    }

    /**
     * Copy marker icons onto the image.
     *
     * Icons whose name starts with `dot-` have no shadow and are centred on their
     * point; other icons are anchored at their bottom-centre pixel. On images
     * under 120px wide or high, icons are shrunk by 1.5.
     *
     * @param GdImage                     $im       The map image.
     * @param list<array<string, mixed>>  $markers  Markers from MarkerParser::parse().
     * @param mixed                       $zoom     Zoom level.
     * @param int|float                   $leftEdge Global pixel x of the image's left edge.
     * @param int|float                   $topEdge  Global pixel y of the image's top edge.
     * @param mixed                       $width    Image width in pixels.
     * @param mixed                       $height   Image height in pixels.
     * @param int                         $scale    Pixel density (#10): icons are drawn this many
     *                                              times larger (the image is that much larger too).
     *
     * @return void
     */
    public function drawMarkers(
        GdImage $im,
        array $markers,
        mixed $zoom,
        int|float $leftEdge,
        int|float $topEdge,
        mixed $width,
        mixed $height,
        int $scale = 1
    ): void {
        foreach ($markers as $marker) {
            // Icons that start with 'dot-' do not have a shadow
            $shadow = !preg_match('/^dot-/', $marker['icon']);

            // Small maps (in CSS pixels, before scaling) shrink icons.
            if ($width / $scale < 120 || $height / $scale < 120) {
                $shrinkFactor = 1.5 / $scale;
            } else {
                $shrinkFactor = 1 / $scale;
            }

            // Icons with a shadow are centered at the bottom middle pixel.
            // Icons with no shadow are centered in the center pixel.

            $px = $this->webmercator->latLngToPixels($marker['lat'], $marker['lng'], $zoom);
            // The copy of the marker nearest the map centre (maps across the antimeridian, #35).
            $px['x'] = Viewport::nearest($px['x'], $leftEdge + $width / 2, $zoom);
            $pos = array(
                'x' => $px['x'] - $leftEdge,
                'y' => $px['y'] - $topEdge
            );

            if ($shrinkFactor != 1) {
                $icon = $marker['iconImg'];
                $markerImg = imagecreatetruecolor(
                    max(1, (int) round(imagesx($icon) / $shrinkFactor)),
                    max(1, (int) round(imagesy($icon) / $shrinkFactor))
                );
                imagealphablending($markerImg, true);
                $color = imagecolorallocatealpha($markerImg, 0, 0, 0, 127);
                imagefill($markerImg, 0, 0, $color);
                imagecopyresampled(
                    $markerImg,
                    $icon,
                    0,
                    0,
                    0,
                    0,
                    imagesx($markerImg),
                    imagesy($markerImg),
                    imagesx($icon),
                    imagesy($icon)
                );
            } else {
                $markerImg = $marker['iconImg'];
            }

            if ($shadow) {
                $iconPos = array(
                    'x' => $pos['x'] - round(imagesx($markerImg) / 2),
                    'y' => $pos['y'] - imagesy($markerImg)
                );
            } else {
                $iconPos = array(
                    'x' => $pos['x'] - round(imagesx($markerImg) / 2),
                    'y' => $pos['y'] - round(imagesy($markerImg) / 2)
                );
            }

            imagecopy(
                $im,
                $markerImg,
                (int) $iconPos['x'],
                (int) $iconPos['y'],
                0,
                0,
                imagesx($markerImg),
                imagesy($markerImg)
            );
        }
    }
}

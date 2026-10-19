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
     *
     * @return void
     */
    public function drawTiles(GdImage $im, array $tiles, array $tilePos, array $pos, mixed $width, mixed $height): void
    {
        foreach ($tiles as $x => $yTiles) {
            foreach ($yTiles as $y => $tile) {
                $x = intval($x);
                $y = intval($y);

                $ox = (($x - $tilePos['x']) * WebMercator::TILE_SIZE) - $pos['x'] + ($width / 2);
                $oy = (($y - $tilePos['y']) * WebMercator::TILE_SIZE) - $pos['y'] + ($height / 2);

                // Truncated to whole pixels, as PHP did implicitly before 8.1.
                imagecopy($im, $tile, (int) $ox, (int) $oy, 0, 0, imagesx($tile), imagesy($tile));
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
        mixed $bezier
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
            $draw->setStrokeWidth($path['weight']);
            $draw->setFillOpacity(0);
            $draw->setStrokeLineCap(Imagick::LINECAP_ROUND);
            $draw->setStrokeLineJoin(Imagick::LINEJOIN_ROUND);

            $previous = false;
            foreach ($path['path'] as $point) {
                if ($previous) {
                    $from = $this->webmercator->latLngToPixels($previous[1], $previous[0], $zoom);
                    $to = $this->webmercator->latLngToPixels($point[1], $point[0], $zoom);

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
                $previous = $point;
            }
        }

        $mg->drawImage($draw);
        $mg->setImageFormat("png");

        $pathImg = imagecreatefromstring($mg);
        imagecopy($im, $pathImg, 0, 0, 0, 0, $width, $height);
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
        mixed $height
    ): void {
        foreach ($markers as $marker) {
            // Icons that start with 'dot-' do not have a shadow
            $shadow = !preg_match('/^dot-/', $marker['icon']);

            if ($width < 120 || $height < 120) {
                $shrinkFactor = 1.5;
            } else {
                $shrinkFactor = 1;
            }

            // Icons with a shadow are centered at the bottom middle pixel.
            // Icons with no shadow are centered in the center pixel.

            $px = $this->webmercator->latLngToPixels($marker['lat'], $marker['lng'], $zoom);
            $pos = array(
                'x' => $px['x'] - $leftEdge,
                'y' => $px['y'] - $topEdge
            );

            if ($shrinkFactor > 1) {
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

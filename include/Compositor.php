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
     * @param float                            $opacity  Layer opacity, 0-1 (overlays, #30).
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
        int $tileSize = WebMercator::TILE_SIZE,
        float $opacity = 1.0
    ): void {
        foreach ($tiles as $x => $yTiles) {
            foreach ($yTiles as $y => $tile) {
                $x = intval($x);
                $y = intval($y);

                $ox = (($x - $tilePos['x']) * $tileSize) - $pos['x'] + ($width / 2);
                $oy = (($y - $tilePos['y']) * $tileSize) - $pos['y'] + ($height / 2);

                // Truncated to whole pixels, as PHP did implicitly before 8.1.
                if ($opacity < 1) {
                    // Add transparency to every pixel (fully transparent ones stay so).
                    $tile = self::faded($tile, $opacity);
                }
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
        $icons = [];

        foreach ($paths as $path) {
            // Each path's settings (dashes, fill) end with it.
            $draw->push();
            $weight = (float) $path['weight'] * $scale;
            // Opacity is set separately: ImageMagick's opacity settings replace a colour's own alpha.
            [$stroke, $strokeOpacity] = self::color((string) $path['color']);
            $draw->setStrokeColor($stroke);
            $draw->setStrokeOpacity($weight > 0 ? $strokeOpacity : 0);
            $draw->setStrokeWidth($weight);
            $draw->setFillOpacity(0);
            $draw->setStrokeLineCap(Imagick::LINECAP_ROUND);
            $draw->setStrokeLineJoin(Imagick::LINEJOIN_ROUND);
            $linestyle = $path['linestyle'] ?? 'solid';
            if ($linestyle === 'dashed') {
                $draw->setStrokeDashArray([max(1.0, $weight * 3), max(1.0, $weight * 2)]);
            } elseif ($linestyle === 'dotted') {
                // Round caps turn very short dashes into dots.
                $draw->setStrokeDashArray([0.1, max(2.0, $weight * 2)]);
            }

            // The first point on the copy of the world nearest the map centre, each
            // following one the short way round from the one before (#35).
            $pixels = [];
            $from = null;
            foreach ($path['path'] as $point) {
                $to = $this->webmercator->latLngToPixels($point[1], $point[0], $zoom);
                $to['x'] = Viewport::nearest($to['x'], $from['x'] ?? $leftEdge + $width / 2, $zoom);
                $pixels[] = ['x' => $to['x'] - $leftEdge, 'y' => $to['y'] - $topEdge];
                $from = $to;
            }

            // The line as drawn, in path order, for vehicle icons along it (#106).
            if (($path['vehicle'] ?? null) !== null) {
                $icons[] = [$path['vehicle'], $this->drawnLine($path, $pixels, $bezier, $leftEdge, $topEdge)];
            }

            if ($path['closed'] ?? false) {
                // Polygons, circles and rectangles (#36): closed, filled if they have a fillcolor.
                if (isset($path['fill'])) {
                    [$fill, $fillOpacity] = self::color((string) $path['fill']);
                    $draw->setFillColor($fill);
                    $draw->setFillOpacity($fillOpacity);
                }
                $draw->polygon($pixels);
            } elseif ($bezier && !($path['geodesic'] ?? false)) {
                for ($i = 1, $n = count($pixels); $i < $n; $i++) {
                    $this->drawCurve(
                        $draw,
                        ['x' => $pixels[$i - 1]['x'] + $leftEdge, 'y' => $pixels[$i - 1]['y'] + $topEdge],
                        ['x' => $pixels[$i]['x'] + $leftEdge, 'y' => $pixels[$i]['y'] + $topEdge],
                        $bezier,
                        $leftEdge,
                        $topEdge
                    );
                }
            } elseif ($linestyle !== 'solid') {
                // One line, so the dash pattern runs on round corners.
                $draw->polyline($pixels);
            } else {
                for ($i = 1, $n = count($pixels); $i < $n; $i++) {
                    $draw->line($pixels[$i - 1]['x'], $pixels[$i - 1]['y'], $pixels[$i]['x'], $pixels[$i]['y']);
                }
            }
            $draw->pop();
        }

        $mg->drawImage($draw);
        $mg->setImageFormat("png");

        $pathImg = imagecreatefromstring($mg);
        imagecopy($im, $pathImg, 0, 0, 0, 0, $width, $height);

        // Vehicle icons on top of every path, under the markers (#106).
        foreach ($icons as [$vehicle, $line]) {
            foreach ($vehicle['at'] as $percent) {
                $point = PathIcon::along($line, (float) $percent);
                if ($point !== null) {
                    PathIcon::draw(
                        $im,
                        $vehicle['type'],
                        $vehicle['color'],
                        $point['x'],
                        $point['y'],
                        $point['angle'],
                        $scale
                    );
                }
            }
        }
    }

    /**
     * A path's line as it is drawn: its points, closed for shapes, and with
     * `bezier` curves sampled, all in path order.
     *
     * @param array<string, mixed>                    $path     The path (`closed`, `geodesic`).
     * @param list<array{x: int|float, y: int|float}> $pixels   Its points in image pixels.
     * @param mixed                                   $bezier   Curve angle in degrees, or 0.
     * @param int|float                               $leftEdge Global pixel x of the image's left edge.
     * @param int|float                               $topEdge  Global pixel y of the image's top edge.
     *
     * @return list<array{x: int|float, y: int|float}> The line in image pixels.
     */
    private function drawnLine(
        array $path,
        array $pixels,
        mixed $bezier,
        int|float $leftEdge,
        int|float $topEdge
    ): array {
        if ($path['closed'] ?? false) {
            return $pixels === [] ? [] : [...$pixels, $pixels[0]];
        }
        if (!$bezier || ($path['geodesic'] ?? false)) {
            return $pixels;
        }
        $line = $pixels === [] ? [] : [$pixels[0]];
        for ($i = 1, $n = count($pixels); $i < $n; $i++) {
            $from = ['x' => $pixels[$i - 1]['x'] + $leftEdge, 'y' => $pixels[$i - 1]['y'] + $topEdge];
            $to = ['x' => $pixels[$i]['x'] + $leftEdge, 'y' => $pixels[$i]['y'] + $topEdge];
            [$a, $p, $b] = self::curve($from, $to, $bezier);
            // curve() may swap the ends; walk it from this segment's start.
            $swapped = $a['x'] != $from['x'] || $a['y'] != $from['y'];
            for ($k = 1; $k <= 24; $k++) {
                $t = $swapped ? 1 - $k / 24 : $k / 24;
                $line[] = [
                    'x' => (1 - $t) ** 2 * $a['x'] + 2 * $t * (1 - $t) * $p['x'] + $t ** 2 * $b['x'] - $leftEdge,
                    'y' => (1 - $t) ** 2 * $a['y'] + 2 * $t * (1 - $t) * $p['y'] + $t ** 2 * $b['y'] - $topEdge,
                ];
            }
        }

        return $line;
    }

    /**
     * A path colour as an ImageMagick colour and an opacity.
     *
     * @param string $hex RRGGBB, or RRGGBBAA where AA is the opacity (00 transparent, ff opaque).
     *
     * @return array{ImagickPixel, float} The colour without alpha, and the opacity from 0 to 1.
     */
    private static function color(string $hex): array
    {
        $opacity = strlen($hex) === 8 ? hexdec(substr($hex, 6, 2)) / 255 : 1.0;

        return [new ImagickPixel('#' . substr($hex, 0, 6)), (float) $opacity];
    }

    /**
     * A copy of a tile with more transparency.
     *
     * @param GdImage $tile    The tile.
     * @param float   $opacity Opacity from 0 to 1.
     *
     * @return GdImage The faded copy.
     */
    private static function faded(GdImage $tile, float $opacity): GdImage
    {
        $copy = imagecreatetruecolor(imagesx($tile), imagesy($tile));
        imagealphablending($copy, false);
        imagesavealpha($copy, true);
        imagefill($copy, 0, 0, (int) imagecolorallocatealpha($copy, 0, 0, 0, 127));
        imagecopy($copy, $tile, 0, 0, 0, 0, imagesx($tile), imagesy($tile));
        imagefilter($copy, IMG_FILTER_COLORIZE, 0, 0, 0, (int) round(127 * (1 - $opacity)));
        imagealphablending($copy, true);

        return $copy;
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
     * @param array<string, mixed> $marker A marker from MarkerParser (see MarkerIcon::place()).
     * @param int                  $width  Map width in CSS pixels.
     * @param int                  $height Map height in CSS pixels.
     *
     * @return array{top: int, right: int, bottom: int, left: int} Pixels the icon reaches from
     *         its anchor in each direction, at the map's CSS size.
     */
    public static function markerBox(array $marker, int $width, int $height): array
    {
        [$icon, $ax, $ay] = MarkerIcon::place($marker, $width, $height, 1);

        return [
            'top'    => (int) ceil($ay),
            'right'  => (int) ceil(imagesx($icon) - $ax),
            'bottom' => (int) ceil(imagesy($icon) - $ay),
            'left'   => (int) ceil($ax),
        ];
    }

    /**
     * Copy marker icons onto the image.
     *
     * Each icon is placed by MarkerIcon::place(): resized (marker `scale`, the
     * request's `scale`, 1/1.5 on maps under 120 CSS pixels), rotated and faded,
     * with its anchor on the marker's point. By default `dot-*` and generated
     * dots are anchored at their centre, other icons at their bottom centre.
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
            // The icon at its size (marker scale, request scale, small maps), rotated and faded (#37).
            [$markerImg, $ax, $ay] = MarkerIcon::place($marker, $width / $scale, $height / $scale, $scale);

            $px = $this->webmercator->latLngToPixels($marker['lat'], $marker['lng'], $zoom);
            // The copy of the marker nearest the map centre (maps across the antimeridian, #35).
            $px['x'] = Viewport::nearest($px['x'], $leftEdge + $width / 2, $zoom);

            imagecopy(
                $im,
                $markerImg,
                (int) ($px['x'] - $leftEdge - $ax),
                (int) ($px['y'] - $topEdge - $ay),
                0,
                0,
                imagesx($markerImg),
                imagesy($markerImg)
            );
        }
    }
}

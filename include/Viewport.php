<?php

/**
 * Choosing the centre and zoom level of a map.
 */

namespace StaticMaps;

/**
 * Centre and zoom selection.
 *
 * Without a `zoom`, the map uses the highest zoom at which everything to show
 * fits: the markers, paths, `bbox` and `visible` points (their Bounds), plus
 * margins in pixels for marker icons, path widths and `padding` (#35). Without
 * a centre, the map is centred on that padded content box.
 */
final class Viewport
{
    /** @var int Highest zoom tried when fitting. */
    public const MAX_FIT_ZOOM = 20;

    /**
     * @param WebMercator $webmercator Projection maths.
     */
    public function __construct(private WebMercator $webmercator)
    {
    }

    /**
     * The zoom to use: `zoom` if given, else the highest that fits.
     *
     * With a centre, the content must fit around that centre; without one, the
     * content box (plus margins) must fit in the image.
     *
     * @param int|null                  $zoom      The checked `zoom`, or null to fit.
     * @param float|null                $latitude  Centre latitude in degrees, or null.
     * @param float|null                $longitude Centre longitude in degrees, or null.
     * @param int                       $width     Image width in pixels.
     * @param int                       $height    Image height in pixels.
     * @param Bounds                    $bounds    Everything to show (markers, paths, bbox, visible).
     * @param array{int, int, int, int} $margins   Pixels to keep around the content: top, right,
     *                                             bottom, left (see margins()).
     *
     * @return int The zoom level, before clampZoom(). 0 if nothing fits, or there is no
     *             content to fit.
     */
    public function zoom(
        ?int $zoom,
        ?float $latitude,
        ?float $longitude,
        int $width,
        int $height,
        Bounds $bounds,
        array $margins
    ): int {
        if ($zoom !== null) {
            return $zoom;
        }
        if ($bounds->isEmpty()) {
            return 0;
        }

        [$top, $right, $bottom, $left] = $margins;
        for ($z = self::MAX_FIT_ZOOM; $z > 0; $z--) {
            $box = $this->box($bounds, $z, $margins);
            if ($latitude !== null && $longitude !== null) {
                $c = $this->webmercator->latLngToPixels($latitude, $longitude, $z);
                // The copy of the centre nearest the content (it may be across the antimeridian).
                $c['x'] = self::nearest($c['x'], ($box['left'] + $box['right']) / 2, $z);
                $fits = $c['x'] - $box['left'] <= $width / 2 && $box['right'] - $c['x'] <= $width / 2
                    && $c['y'] - $box['top'] <= $height / 2 && $box['bottom'] - $c['y'] <= $height / 2;
            } else {
                $fits = $box['right'] - $box['left'] <= $width && $box['bottom'] - $box['top'] <= $height;
            }
            if ($fits) {
                return $z;
            }
        }

        return 0;
    }

    /**
     * The map centre: the given one, else the middle of the content box (with its
     * margins) at the zoom used, so asymmetric padding shifts the content.
     *
     * @param float|null                $latitude  The checked latitude (or from `center`), or null.
     * @param float|null                $longitude The checked longitude, or null.
     * @param int|float                 $zoom      The zoom used (after clampZoom()).
     * @param Bounds                    $bounds    Everything to show.
     * @param array{int, int, int, int} $margins   Pixels around the content: top, right, bottom, left.
     *
     * @return array{latitude: float, longitude: float, located: bool} The centre in degrees,
     *         and whether the request placed the map at all (false: no centre and nothing
     *         to show, centre 0,0).
     */
    public function center(?float $latitude, ?float $longitude, int|float $zoom, Bounds $bounds, array $margins): array
    {
        if ($latitude !== null && $longitude !== null) {
            return ['latitude' => $latitude, 'longitude' => $longitude, 'located' => true];
        }
        if ($bounds->isEmpty()) {
            return ['latitude' => 0.0, 'longitude' => 0.0, 'located' => false];
        }

        $box = $this->box($bounds, $zoom, $margins);
        $center = $this->webmercator->pixelsToLatLng(
            ($box['left'] + $box['right']) / 2,
            ($box['top'] + $box['bottom']) / 2,
            $zoom
        );

        return [
            'latitude'  => (float) $center['lat'],
            'longitude' => Bounds::wrap((float) $center['lng']),
            'located'   => true,
        ];
    }

    /**
     * Widen the bounds to the bulge of curved paths (`bezier`), which can reach
     * beyond the points themselves.
     *
     * Curves are worked out in pixels at MAX_FIT_ZOOM; Mercator pixels scale by
     * the same factor at every zoom, so the result holds at any zoom.
     *
     * @param list<array<string, mixed>> $paths  Paths from PathParser (`path`: [lng, lat] points).
     * @param float                      $bezier Curve angle in degrees; 0 does nothing.
     * @param Bounds                     $bounds Box to widen.
     *
     * @return void
     */
    public function extendForCurves(array $paths, float $bezier, Bounds $bounds): void
    {
        if ($bezier == 0) {
            return;
        }
        foreach ($paths as $path) {
            $previous = null;
            foreach ($path['path'] as $point) {
                $px = $this->webmercator->latLngToPixels($point[1], $point[0], self::MAX_FIT_ZOOM);
                if ($previous !== null) {
                    // Segments go the short way round, as they are drawn.
                    $px['x'] = self::nearest($px['x'], $previous['x'], self::MAX_FIT_ZOOM);
                    foreach (Compositor::curveExtremes($previous, $px, $bezier) as $extreme) {
                        $at = $this->webmercator->pixelsToLatLng($extreme['x'], $extreme['y'], self::MAX_FIT_ZOOM);
                        $bounds->extend($at['lat'], $at['lng']);
                    }
                }
                $previous = $px;
            }
        }
    }

    /**
     * Pixels to keep around the content: `padding`, plus room for the largest
     * marker icon on each side of its anchor and half the widest path.
     *
     * Using the largest icon for every side is conservative: an icon never gets
     * cut off at the edge, at the cost of a little extra room.
     *
     * @param array{int, int, int, int}   $padding Top, right, bottom, left padding in pixels.
     * @param list<array<string, mixed>>  $markers Markers from MarkerParser (with `iconImg`).
     * @param list<array<string, mixed>>  $paths   Paths from PathParser (with `weight`).
     * @param int                         $width   Image width in pixels (small maps shrink icons).
     * @param int                         $height  Image height in pixels.
     *
     * @return array{int, int, int, int} Top, right, bottom, left margins in pixels.
     */
    public static function margins(array $padding, array $markers, array $paths, int $width, int $height): array
    {
        $extra = [0, 0, 0, 0];
        foreach ($markers as $marker) {
            $box = Compositor::markerBox($marker, $width, $height);
            foreach ([$box['top'], $box['right'], $box['bottom'], $box['left']] as $i => $size) {
                $extra[$i] = max($extra[$i], $size);
            }
        }
        foreach ($paths as $path) {
            $half = (int) ceil((float) $path['weight'] / 2);
            foreach ($extra as $i => $size) {
                $extra[$i] = max($size, $half);
            }
        }

        return [
            $padding[0] + $extra[0],
            $padding[1] + $extra[1],
            $padding[2] + $extra[2],
            $padding[3] + $extra[3],
        ];
    }

    /**
     * How much of the map a coverage area (`bounds`, #30) covers.
     *
     * @param list<float|int>|null $bounds    The area, [minLng, minLat, maxLng, maxLat] (minLng > maxLng crosses
     *                                        the antimeridian), or null for the whole world.
     * @param float                $latitude  Map centre latitude in degrees.
     * @param float                $longitude Map centre longitude in degrees.
     * @param int|float            $zoom      Map zoom.
     * @param int                  $width     Map width in CSS pixels.
     * @param int                  $height    Map height in CSS pixels.
     *
     * @return string `full`, `partial` or `none`.
     */
    public function coverage(
        ?array $bounds,
        float $latitude,
        float $longitude,
        int|float $zoom,
        int $width,
        int $height
    ): string {
        if ($bounds === null) {
            return 'full';
        }
        $c = $this->webmercator->latLngToPixels($latitude, $longitude, $zoom);
        $nw = $this->webmercator->pixelsToLatLng($c['x'] - $width / 2, $c['y'] - $height / 2, $zoom);
        $se = $this->webmercator->pixelsToLatLng($c['x'] + $width / 2, $c['y'] + $height / 2, $zoom);
        [$west, $east] = [(float) $nw['lng'], (float) $se['lng']];
        [$south, $north] = [(float) $se['lat'], (float) $nw['lat']];

        [$bw, $bs, $be, $bn] = array_map('floatval', $bounds);
        if ($be < $bw) {
            $be += 360;
        }
        if ($north <= $bs || $south >= $bn) {
            return 'none';
        }
        $overlaps = false;
        foreach ([-360, 0, 360] as $shift) {
            if ($bw + $shift <= $west && $east <= $be + $shift && $bs <= $south && $north <= $bn) {
                return 'full';
            }
            $overlaps = $overlaps || max($west, $bw + $shift) < min($east, $be + $shift);
        }

        return $overlaps ? 'partial' : 'none';
    }

    /**
     * The copy of an x pixel position (one per trip round the world) nearest another.
     *
     * @param int|float $x      A position in world pixels.
     * @param int|float $target The position to be near.
     * @param int|float $zoom   Zoom level (sets the world's width in pixels).
     *
     * @return float $x plus or minus whole world widths, within half a world of $target.
     */
    public static function nearest(int|float $x, int|float $target, int|float $zoom): float
    {
        $world = WebMercator::TILE_SIZE * 2 ** $zoom;

        return $x - round(($x - $target) / $world) * $world;
    }

    /**
     * The content box in world pixels at a zoom, with margins.
     *
     * @param Bounds                    $bounds  Everything to show (not empty).
     * @param int|float                 $zoom    Zoom level.
     * @param array{int, int, int, int} $margins Top, right, bottom, left margins in pixels.
     *
     * @return array{left: float, top: float, right: float, bottom: float} Edges in world pixels.
     */
    private function box(Bounds $bounds, int|float $zoom, array $margins): array
    {
        // West to east may cross the antimeridian: east is then past 180, and its
        // pixels past the world's right edge, as the drawing expects.
        [$west, $east] = $bounds->span();
        $nw = $this->webmercator->latLngToPixels($bounds->maxLat, $west, $zoom);
        $se = $this->webmercator->latLngToPixels($bounds->minLat, $east, $zoom);

        return [
            'left'   => (float) $nw['x'] - $margins[3],
            'top'    => (float) $nw['y'] - $margins[0],
            'right'  => (float) $se['x'] + $margins[1],
            'bottom' => (float) $se['y'] + $margins[2],
        ];
    }

    /**
     * Limit a zoom level by `maxzoom` and the basemap's zoom range, in that order.
     *
     * @param mixed $zoom    Zoom level from zoom().
     * @param int|null $maxzoom The checked `maxzoom` parameter, or null (ignored when not lower).
     * @param int   $minZoom The basemap's lowest zoom level.
     * @param int   $maxZoom The basemap's highest zoom level.
     *
     * @return mixed The zoom level to render at.
     */
    public function clampZoom(mixed $zoom, ?int $maxzoom, int $minZoom, int $maxZoom): mixed
    {
        if ($maxzoom !== null && $maxzoom < $zoom) {
            $zoom = $maxzoom;
        }

        if ($zoom < $minZoom) {
            $zoom = $minZoom;
        }

        if ($zoom > $maxZoom) {
            $zoom = $maxZoom;
        }

        return $zoom;
    }
}

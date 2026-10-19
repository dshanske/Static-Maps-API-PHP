<?php

/**
 * Choosing the centre and zoom level of a map.
 */

namespace StaticMaps;

/**
 * Centre and zoom selection.
 */
final class Viewport
{
    /**
     * @param WebMercator $webmercator Projection maths.
     */
    public function __construct(private WebMercator $webmercator)
    {
    }

    /**
     * The map centre: `latitude`/`longitude`, else the centre of the markers and
     * paths (0,0 if there are none).
     *
     * `longitude` is only read when `latitude` is given (Renderer rejects a request
     * that has only one of them).
     *
     * @param Request $request The request.
     * @param Bounds  $bounds  Bounds of the markers and paths.
     *
     * @return array{latitude: mixed, longitude: mixed, located: bool} The centre in
     *         degrees (request values are passed through unchanged), and whether the
     *         request located the map at all (false means the 0,0 fallback).
     */
    public function center(Request $request, Bounds $bounds): array
    {
        if ($request->get('latitude') !== false) {
            return [
                'latitude'  => $request->get('latitude'),
                'longitude' => $request->get('longitude'),
                'located'   => true,
            ];
        }

        $default = $bounds->center();

        return ['latitude' => $default['lat'], 'longitude' => $default['lng'], 'located' => !$bounds->isEmpty()];
    }

    /**
     * The zoom level, before clamping.
     *
     * A truthy `zoom` parameter is used as is. Otherwise the zoom steps down from
     * 20 and stops one level *below* the first one at which $bounds fits into
     * width × height (or at 1); this extra step is existing behaviour and gives
     * the markers some margin.
     *
     * @param mixed  $zoom      The `zoom` parameter, or false.
     * @param mixed  $latitude  Centre latitude in degrees.
     * @param mixed  $longitude Centre longitude in degrees.
     * @param mixed  $width     Image width in pixels.
     * @param mixed  $height    Image height in pixels.
     * @param Bounds $bounds    Bounds of the markers and paths.
     *
     * @return mixed The zoom level (the request value when given, else an int).
     */
    public function zoom(
        mixed $zoom,
        mixed $latitude,
        mixed $longitude,
        mixed $width,
        mixed $height,
        Bounds $bounds
    ): mixed {
        if ($zoom) {
            return $zoom;
        }

        // start at max zoom level (20)
        $fitZoom = 20;
        $doesNotFit = true;
        while ($fitZoom > 1 && $doesNotFit) {
            $center = $this->webmercator->latLngToPixels($latitude, $longitude, $fitZoom);

            $leftEdge = $center['x'] - $width / 2;
            $topEdge = $center['y'] - $height / 2;

            // check if the bounding rectangle fits within width/height
            $sw = $this->webmercator->latLngToPixels($bounds->minLat, $bounds->minLng, $fitZoom);
            $ne = $this->webmercator->latLngToPixels($bounds->maxLat, $bounds->maxLng, $fitZoom);

            $fitHeight = abs($ne['y'] - $sw['y']);
            $fitWidth = abs($ne['x'] - $sw['x']);

            if ($fitHeight <= $height && $fitWidth <= $width) {
                $doesNotFit = false;
            }

            $fitZoom--;
        }

        return $fitZoom;
    }

    /**
     * Limit a zoom level by `maxzoom` and the basemap's zoom range, in that order.
     *
     * @param mixed $zoom    Zoom level from zoom().
     * @param mixed $maxzoom The `maxzoom` parameter, or false (ignored when falsy or not lower).
     * @param int   $minZoom The basemap's lowest zoom level.
     * @param int   $maxZoom The basemap's highest zoom level.
     *
     * @return mixed The zoom level to render at.
     */
    public function clampZoom(mixed $zoom, mixed $maxzoom, int $minZoom, int $maxZoom): mixed
    {
        if ($maxzoom && $maxzoom < $zoom) {
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

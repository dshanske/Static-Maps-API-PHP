<?php

/**
 * What a map request will draw, before any tile is fetched (#98).
 */

namespace StaticMaps;

/**
 * The planning stages of a map request: its markers and paths, its viewport,
 * the map and overlays drawn, and the headers they produce.
 *
 * Renderer::render() draws from a plan; the query API can make the same plan
 * to describe a map without drawing it (#95).
 */
final class MapPlan
{
    /**
     * @param list<string>               $headers  Response headers collected so far.
     * @param list<array<string, mixed>> $markers  Markers to draw (from MarkerParser).
     * @param list<array<string, mixed>> $paths    Paths to draw (from PathParser).
     * @param array<string, mixed>       $basemap  The map drawn (after any fallback).
     * @param list<array<string, mixed>> $overlays The overlays drawn (within their zoom range and coverage).
     * @param mixed                      $zoom     The zoom level (after clampZoom()).
     * @param float                      $latitude  Latitude of the map centre in degrees.
     * @param float                      $longitude Longitude of the map centre in degrees.
     */
    private function __construct(
        public readonly array $headers,
        public readonly array $markers,
        public readonly array $paths,
        public readonly array $basemap,
        public readonly array $overlays,
        public readonly mixed $zoom,
        public readonly float $latitude,
        public readonly float $longitude
    ) {
    }

    /**
     * Plan the map for a request.
     *
     * Parses the markers (loading their icons) and paths, checks `errors.strict`,
     * fits the viewport, chooses the map (falling back to the default for a
     * regional map entirely outside its coverage) and keeps the overlays that
     * apply at its zoom and position.
     *
     * @param array<string, mixed> $config        Configuration from Config::load().
     * @param WebMercator          $mercator      Projection maths.
     * @param Request              $request       The request.
     * @param Client|null          $client        The request's client, if any.
     * @param Parameters           $params        Its checked parameters.
     * @param array{markers: list<string>, paths: list<string>, headers: list<string>} $geojson Its GeoJSON
     *                                            markers and paths (GeoJson::toOverlays()).
     * @param RemoteFetcher        $remote        Downloads icon URLs.
     * @param list<string>         $headers       Headers collected before planning.
     * @param bool                 $fetchIconUrls False to measure icon URLs at their largest size
     *                                            instead of downloading them (the query API, #95).
     *
     * @return self|Response The plan; or a 400 if the request doesn't locate the map, or (with
     *                       `errors.strict`) a marker or path can't be drawn.
     */
    public static function make(
        array $config,
        WebMercator $mercator,
        Request $request,
        ?Client $client,
        Parameters $params,
        array $geojson,
        RemoteFetcher $remote,
        array $headers,
        bool $fetchIconUrls = true
    ): self|Response {
        $bounds = new Bounds();
        $mayFetchUrls = $client !== null && $client->externalUrls;

        // Markers and paths; with no centre given, the map is centred on them
        $limits = $config['limits'];
        $parsed = (new MarkerParser(
            $remote,
            (int) $limits['max_markers'],
            $params->scale,
            (int) $limits['icon_urls'],
            (int) $limits['icon_bytes'],
            (int) $limits['icon_pixels'],
            $fetchIconUrls
        ))->parse(
            array_merge(self::values($request->get('marker')), $geojson['markers']),
            $mayFetchUrls,
            $bounds
        );
        $markers = $parsed['markers'];
        $headers = array_merge($headers, $geojson['headers']);
        $headers = array_merge($headers, $parsed['headers']);

        // Geocoding was removed (#16): `location` is ignored, with a deprecation notice.
        $usesLocation = $request->get('location') !== false;
        if ($usesLocation || $parsed['deprecated']) {
            $headers = array_merge($headers, Deprecation::headers());
        }

        $parsedPaths = (new PathParser(
            $config['defaults']['path_color'],
            $config['defaults']['path_weight'],
            (int) $config['limits']['max_points']
        ))->parse(
            array_merge(self::values($request->get('path')), $geojson['paths']),
            $request->get('polyline'),
            $bounds
        );
        $paths = $parsedPaths['paths'];
        $headers = array_merge($headers, $parsedPaths['headers']);

        // errors.strict: a marker or path that can't be drawn is an error, not a header (#39).
        if ($config['errors']['strict']) {
            foreach ($headers as $header) {
                if (preg_match('/^X-(Marker|Path)-(\d+): (.*)$/', $header, $m)) {
                    return Response::error(400, strtolower($m[1]) . " $m[2]: $m[3]", $headers);
                }
            }
        }

        // Curved paths bulge past their points; areas and points to keep in view without drawing them (#35).
        $viewport = new Viewport($mercator);
        $viewport->extendForCurves($paths, $params->bezier, $bounds);
        if ($params->bbox !== null) {
            // minLng > maxLng means the box crosses the antimeridian.
            $bounds->extendArea($params->bbox[1], $params->bbox[0], $params->bbox[3], $params->bbox[2]);
        }
        foreach ($params->visible as [$lat, $lng]) {
            $bounds->extend($lat, $lng);
        }

        if (($params->latitude === null || $params->longitude === null) && $bounds->isEmpty()) {
            return Response::error(
                400,
                $usesLocation
                    ? 'The location parameter (geocoding) is no longer supported: use latitude and longitude'
                    : 'A map needs a location: latitude and longitude (or center), bbox, visible, or at least '
                        . 'one marker or path',
                $headers
            );
        }

        // Within the install's limits (#9); X-Map-Size says if the size was reduced.
        $width = $params->width;
        $height = $params->height;
        $headers = array_merge($headers, $params->sizeHeaders);

        [$basemap, $basemapHeaders] = (new MapChooser($config))->choose($request, $client);
        $headers = array_merge($headers, $basemapHeaders);

        // Fit to the content, its icons, path widths and padding; centre on it unless a centre is given.
        $margins = Viewport::margins($params->padding, $markers, $paths, $width, $height);
        $zoom = $viewport->zoom(
            $params->zoom,
            $params->latitude,
            $params->longitude,
            $width,
            $height,
            $bounds,
            $margins
        );
        $fitZoom = $zoom;
        $place = static function (array $map) use ($viewport, $fitZoom, $params, $bounds, $margins): array {
            $zoom = $viewport->clampZoom($fitZoom, $params->maxzoom, $map['minzoom'], $map['maxzoom']);
            $center = $viewport->center($params->latitude, $params->longitude, $zoom, $bounds, $margins);

            return [$zoom, $center['latitude'], $center['longitude']];
        };
        [$zoom, $latitude, $longitude] = $place($basemap);

        // A regional map (#30) entirely outside its coverage gets the default map instead.
        $coverage = $viewport->coverage($basemap['bounds'] ?? null, $latitude, $longitude, $zoom, $width, $height);
        if ($coverage === 'none' && ($fallback = (new MapChooser($config))->outside($basemap, $client)) !== null) {
            [$basemap, $fallbackHeaders] = $fallback;
            $headers = array_merge($headers, $fallbackHeaders);
            [$zoom, $latitude, $longitude] = $place($basemap);
            $coverage = $viewport->coverage($basemap['bounds'] ?? null, $latitude, $longitude, $zoom, $width, $height);
        }
        if ($coverage !== 'full') {
            $headers[] = "X-Map-Coverage: $coverage";
        }

        // Overlays (#30), skipped outside their zoom range or coverage.
        [$overlays, $overlayHeaders] = (new MapChooser($config))->overlays($request, $client);
        $headers = array_merge($headers, $overlayHeaders);
        $overlays = array_values(array_filter(
            $overlays,
            static fn (array $o): bool => $zoom >= $o['minzoom'] && $zoom <= $o['maxzoom']
                && $viewport->coverage($o['bounds'] ?? null, $latitude, $longitude, $zoom, $width, $height) !== 'none'
        ));

        return new self($headers, $markers, $paths, $basemap, $overlays, $zoom, $latitude, $longitude);
    }

    /**
     * A `marker[]` or `path[]` parameter as a list.
     *
     * @param mixed $value The parameter: false when not given, a string, or a list.
     *
     * @return list<mixed> Its values.
     */
    private static function values(mixed $value): array
    {
        if ($value === false || $value === '' || $value === null) {
            return [];
        }

        return is_array($value) ? array_values($value) : [$value];
    }
}

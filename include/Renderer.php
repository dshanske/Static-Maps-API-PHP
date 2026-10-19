<?php

/**
 * The map rendering pipeline.
 */

namespace StaticMaps;

use GdImage;

/**
 * Renders the map for one request.
 *
 * Stages, in order: parse markers and paths (which also gives their bounds) →
 * pick the centre → pick the basemap → pick the zoom → download tiles →
 * draw tiles, paths and markers → draw the attribution → encode the image.
 *
 * A request must locate the map: `latitude` and `longitude`, a `location`
 * that geocodes, or at least one valid marker or path. Otherwise it gets a
 * 400 and no tiles are downloaded (#47).
 */
final class Renderer
{
    /** @var WebMercator Projection maths. */
    private WebMercator $webmercator;

    /** @var callable(string): ArcGISGeocoderResult|null Geocoder for `location`; null for the default. */
    private $geocode;

    /**
     * @param array<string, mixed>                       $config  Configuration from Config::load().
     * @param Tokens                                     $tokens  Valid access tokens.
     * @param callable(string): ArcGISGeocoderResult|null $geocode Geocoder for `location`;
     *                                                           null for ArcGISGeocoder::geocode().
     */
    public function __construct(private array $config, private Tokens $tokens, ?callable $geocode = null)
    {
        $this->webmercator = new WebMercator();
        $this->geocode = $geocode;
    }

    /**
     * Render the map for a request.
     *
     * @param Request $request The request parameters.
     *
     * @return Response Headers and the encoded image; a 400 with a plain-text reason
     *                  if the request doesn't locate the map. An unknown `format`
     *                  gives an empty body and no Content-type (#18).
     */
    public function render(Request $request): Response
    {
        if (($request->get('latitude') === false) !== ($request->get('longitude') === false)) {
            return Response::error(400, 'latitude and longitude must be given together');
        }

        $config = $this->config;
        $headers = array();
        $bounds = new Bounds();
        $is_authenticated = $this->tokens->isValid($request->get('token'));

        // Markers and paths; with no centre given, the map is centred on them
        $parsed = (new MarkerParser($this->geocode))->parse($request->get('marker'), $is_authenticated, $bounds);
        $markers = $parsed['markers'];
        $headers = array_merge($headers, $parsed['headers']);

        $paths = (new PathParser($config['defaults']['path_color'], $config['defaults']['path_weight']))
            ->parse($request->get('path'), $request->get('polyline'), $bounds);

        $viewport = new Viewport($this->webmercator, $this->geocode);
        $center = $viewport->center($request, $bounds);
        $latitude = $center['latitude'];
        $longitude = $center['longitude'];
        $headers = array_merge($headers, $center['headers']);
        if (!$center['located']) {
            return Response::error(
                400,
                'A map needs a location: latitude and longitude, or at least one marker or path',
                $headers
            );
        }

        $width = $request->get('width', $config['defaults']['width']);
        $height = $request->get('height', $config['defaults']['height']);

        [$basemap, $basemapHeaders] = $this->basemap($request, $is_authenticated);
        $headers = array_merge($headers, $basemapHeaders);
        $tileURL = $basemap['layers'][0];
        $overlayURL = $basemap['layers'][1] ?? false;
        $tileKey = $basemap['key'] ?? null;

        $zoom = $viewport->zoom($request->get('zoom'), $latitude, $longitude, $width, $height, $bounds);
        $zoom = $viewport->clampZoom($zoom, $request->get('maxzoom'), $basemap['minzoom'], $basemap['maxzoom']);

        $im = imagecreatetruecolor($width, $height);

        // Find the pixel coordinate of the center of the map
        $center = $this->webmercator->latLngToPixels($latitude, $longitude, $zoom);

        $leftEdge = $center['x'] - $width / 2;
        $topEdge = $center['y'] - $height / 2;

        $tilePos = $this->webmercator->pixelsToTile($center['x'], $center['y']);
        $pos = $this->webmercator->positionInTile($center['x'], $center['y']);

        // For the given number of pixels, determine how many tiles are needed in each direction
        $neTile = $this->webmercator->pixelsToTile($center['x'] + $width / 2, $center['y'] + $height / 2);
        $swTile = $this->webmercator->pixelsToTile($center['x'] - $width / 2, $center['y'] - $height / 2);

        $fetched = (new TileFetcher($config['user_agent']))
            ->fetch($tileURL, $overlayURL, $tileKey, $swTile, $neTile, $zoom, $im);

        $compositor = new Compositor($this->webmercator);
        $compositor->drawTiles($im, $fetched['tiles'], $tilePos, $pos, $width, $height);
        if ($overlayURL) {
            $compositor->drawTiles($im, $fetched['overlays'], $tilePos, $pos, $width, $height);
        }
        $compositor->drawPaths($im, $paths, $zoom, $leftEdge, $topEdge, $width, $height, $request->get('bezier'));
        $compositor->drawMarkers($im, $markers, $zoom, $leftEdge, $topEdge, $width, $height);

        // The request's attribution, else the basemap's default, else the global default
        $attribution = $request->get('attribution');
        if ($attribution === false) {
            $attribution = $basemap['attribution'] ?? $config['defaults']['attribution'];
        }
        (new Attribution())->draw($im, $attribution, $width, $height);

        [$formatHeaders, $body] = $this->encode($im, $request);

        return new Response(array_merge($headers, $formatHeaders), $body);
    }

    /**
     * The map to draw.
     *
     * - `provider` and/or `style`: that provider (default: `defaults.provider`)
     *   with that style (default: the provider's `default_style`).
     *   `provider=custom` uses `tileurl` and needs a valid token.
     * - Otherwise the deprecated `basemap`: the provider/style its id maps to in
     *   `basemap_aliases`, or `basemap=custom` with `tileurl` and a token. Any use
     *   of `basemap` adds `Deprecation` and `Link` headers.
     * - Anything unknown, disabled or token-only (without a token) gets the
     *   default map, `defaults.provider` / `defaults.style`.
     *
     * @param Request $request          The request.
     * @param bool    $is_authenticated Whether the request has a valid token.
     *
     * @return array{array<string, mixed>, list<string>} Map settings (with `layers`,
     *         `minzoom` and `maxzoom`), and headers to send.
     */
    private function basemap(Request $request, bool $is_authenticated): array
    {
        $config = $this->config;
        $provider = $request->get('provider');
        $style = $request->get('style');
        $legacy = $request->get('basemap');
        $headers = $legacy === false ? [] : Deprecation::headers();

        $basemap = null;
        if ($provider !== false || $style !== false) {
            if ('custom' === $provider) {
                $basemap = $this->customBasemap($request, $is_authenticated);
            } else {
                $p = is_string($provider) ? $provider : $config['defaults']['provider'];
                $s = is_string($style) ? $style : Config::defaultStyle($config, $p);
                $basemap = $s === null ? null : Config::resolve($config, $p, $s, $is_authenticated);
            }
        } elseif ('custom' === $legacy) {
            $basemap = $this->customBasemap($request, $is_authenticated);
        } elseif (is_string($legacy) && ($alias = Config::alias($config, $legacy)) !== null) {
            $basemap = Config::resolve($config, $alias[0], $alias[1], $is_authenticated);
        }

        if ($basemap === null) {
            // Config::validate() guarantees the default resolves without a token.
            $basemap = (array) Config::resolve(
                $config,
                $config['defaults']['provider'],
                $config['defaults']['style'],
                $is_authenticated
            );
        }

        return [$basemap, $headers];
    }

    /**
     * A one-layer map from the request's `tileurl`, for requests with a valid token.
     *
     * @param Request $request          The request.
     * @param bool    $is_authenticated Whether the request has a valid token.
     *
     * @return array<string, mixed>|null Map settings, or null without a token or `tileurl`.
     */
    private function customBasemap(Request $request, bool $is_authenticated): ?array
    {
        if (!$is_authenticated || !is_string($request->get('tileurl'))) {
            return null;
        }

        return array(
            'layers'  => array($request->get('tileurl')),
            'minzoom' => $this->config['zoom']['min'],
            'maxzoom' => $this->config['zoom']['max'],
        );
    }

    /**
     * Encode the image as the requested `format`.
     *
     * @param GdImage $im      The finished map.
     * @param Request $request The request (`format`, `quality`).
     *
     * @return array{list<string>, string} Content-type header (none for an unknown format)
     *                                     and the encoded image.
     */
    private function encode(GdImage $im, Request $request): array
    {
        $headers = array();
        ob_start();
        $fmt = $request->get('format', $this->config['defaults']['format']);
        switch ($fmt) {
            case "jpg":
            case "jpeg":
                $headers[] = 'Content-type: image/jpg';
                $quality = $request->get('quality', $this->config['defaults']['jpeg_quality']);
                imagejpeg($im, null, $quality);
                break;
            case "png":
                $headers[] = 'Content-type: image/png';
                imagepng($im);
                break;
        }
        imagedestroy($im);

        return [$headers, (string) ob_get_clean()];
    }
}

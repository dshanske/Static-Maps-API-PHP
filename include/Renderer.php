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
 * A request must locate the map: `latitude` and `longitude`, or at least one
 * valid marker or path. Otherwise it gets a 400 and no tiles are downloaded
 * (#47). The `location` parameter (geocoding) was removed (#16): it is
 * ignored, and a request that uses it gets `Deprecation` and `Link` headers.
 */
final class Renderer
{
    /** @var list<string> Accepted values of `format` (`jpeg` is the same as `jpg`). */
    public const FORMATS = ['png', 'jpg', 'jpeg'];

    /** @var WebMercator Projection maths. */
    private WebMercator $webmercator;

    /**
     * @param array<string, mixed> $config Configuration from Config::load().
     * @param Tokens               $tokens Valid access tokens.
     */
    public function __construct(private array $config, private Tokens $tokens)
    {
        $this->webmercator = new WebMercator();
    }

    /**
     * Render the map for a request.
     *
     * @param Request $request The request parameters.
     *
     * @return Response Headers and the encoded image; a 400 with a plain-text reason
     *                  if a numeric parameter is invalid (see Parameters), the
     *                  request doesn't locate the map, or it asks for an unknown
     *                  `format`.
     */
    public function render(Request $request): Response
    {
        try {
            $params = Parameters::parse($request, $this->config);
        } catch (InvalidRequest $e) {
            return Response::error(400, $e->getMessage());
        }
        $format = $request->get('format', $this->config['defaults']['format']);
        if (!in_array($format, self::FORMATS, true)) {
            return Response::error(400, 'format must be png or jpg');
        }
        $position = $request->get('attribution_position', 'bottomright');
        if ($position === '') {
            $position = 'bottomright';
        }
        if (!in_array($position, Attribution::POSITIONS, true)) {
            return Response::error(400, 'attribution_position must be ' . implode(', ', Attribution::POSITIONS));
        }

        $config = $this->config;
        $headers = array();
        $bounds = new Bounds();
        $is_authenticated = $this->tokens->isValid($request->get('token'));

        // Markers and paths; with no centre given, the map is centred on them
        $remote = RemoteFetcher::fromConfig($config);
        $parsed = (new MarkerParser($remote))->parse($request->get('marker'), $is_authenticated, $bounds);
        $markers = $parsed['markers'];
        $headers = array_merge($headers, $parsed['headers']);

        // Geocoding was removed (#16): `location` is ignored, with a deprecation notice.
        $usesLocation = $request->get('location') !== false;
        if ($usesLocation || $parsed['deprecated']) {
            $headers = array_merge($headers, Deprecation::headers());
        }

        $parsedPaths = (new PathParser($config['defaults']['path_color'], $config['defaults']['path_weight']))
            ->parse($request->get('path'), $request->get('polyline'), $bounds);
        $paths = $parsedPaths['paths'];
        $headers = array_merge($headers, $parsedPaths['headers']);

        $viewport = new Viewport($this->webmercator);
        $center = $viewport->center($params->latitude, $params->longitude, $bounds);
        $latitude = $center['latitude'];
        $longitude = $center['longitude'];
        if (!$center['located']) {
            return Response::error(
                400,
                $usesLocation
                    ? 'The location parameter (geocoding) is no longer supported: use latitude and longitude'
                    : 'A map needs a location: latitude and longitude, or at least one marker or path',
                $headers
            );
        }

        // Within the install's limits (#9); X-Map-Size says if the size was reduced.
        $width = $params->width;
        $height = $params->height;
        $headers = array_merge($headers, $params->sizeHeaders);

        [$basemap, $basemapHeaders] = (new MapChooser($config))->choose($request, $is_authenticated);
        $headers = array_merge($headers, $basemapHeaders);
        $tileURL = $basemap['layers'][0];
        $overlayURL = $basemap['layers'][1] ?? false;
        $tileKey = $basemap['key'] ?? null;

        $zoom = $viewport->zoom($params->zoom, $latitude, $longitude, $width, $height, $bounds);
        $zoom = $viewport->clampZoom($zoom, $params->maxzoom, $basemap['minzoom'], $basemap['maxzoom']);

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

        $fetched = (new TileFetcher($remote))
            ->fetch($tileURL, $overlayURL, $tileKey, $swTile, $neTile, $zoom, $im);

        $compositor = new Compositor($this->webmercator);
        $compositor->drawTiles($im, $fetched['tiles'], $tilePos, $pos, $width, $height);
        if ($overlayURL) {
            $compositor->drawTiles($im, $fetched['overlays'], $tilePos, $pos, $width, $height);
        }
        $compositor->drawPaths($im, $paths, $zoom, $leftEdge, $topEdge, $width, $height, $params->bezier);
        $compositor->drawMarkers($im, $markers, $zoom, $leftEdge, $topEdge, $width, $height);

        // The request's attribution, else the basemap's default, else the global
        // default. An unknown value counts as no value.
        $attribution = $request->get('attribution');
        // Like icon URLs and custom tiles, attribution image URLs need a valid token.
        if (Attribution::isUrl($attribution) && !$is_authenticated) {
            $headers[] = 'X-Map-Attribution: attribution image URLs need a valid token';
            $attribution = false;
        }
        if (!Attribution::isKnown($attribution)) {
            $attribution = $basemap['attribution'] ?? $config['defaults']['attribution'];
        }
        $text = isset($basemap['attribution_text']) ? (string) $basemap['attribution_text'] : null;
        (new Attribution($remote))->draw($im, $attribution, $text, $position, $width, $height);

        [$formatHeaders, $body] = $this->encode($im, $request, $params->quality);

        return new Response(array_merge($headers, $formatHeaders), $body);
    }

    /**
     * Encode the image as the requested `format` (already checked to be one of FORMATS).
     *
     * @param GdImage $im      The finished map.
     * @param Request $request The request (`format`).
     * @param int     $quality JPEG quality, 1-100.
     *
     * @return array{list<string>, string} Content-type header and the encoded image.
     */
    private function encode(GdImage $im, Request $request, int $quality): array
    {
        $headers = array();
        ob_start();
        $fmt = $request->get('format', $this->config['defaults']['format']);
        if ($fmt === 'jpg' || $fmt === 'jpeg') {
            $headers[] = 'Content-type: image/jpg';
            imagejpeg($im, null, $quality);
        } else {
            $headers[] = 'Content-type: image/png';
            imagepng($im);
        }

        return [$headers, (string) ob_get_clean()];
    }
}

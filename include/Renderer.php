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
    /**
     * @var list<string> Accepted values of `format`: `jpeg` is the same as `jpg`, `png8` is a
     *      PNG with a 256-colour palette, and `webp` needs GD built with WebP support (see
     *      formats()).
     */
    public const FORMATS = ['png', 'png8', 'jpg', 'jpeg', 'webp'];

    /**
     * The formats this server can produce.
     *
     * @return list<string> FORMATS without `webp` when GD has no WebP support.
     */
    public static function formats(): array
    {
        $webp = function_exists('imagewebp') && (gd_info()['WebP Support'] ?? false) === true;

        return $webp ? self::FORMATS : ['png', 'png8', 'jpg', 'jpeg'];
    }

    /** @var WebMercator Projection maths. */
    private WebMercator $webmercator;

    /**
     * @param array<string, mixed> $config Configuration from Config::load().
     * @param Access               $access Who may draw which maps.
     */
    public function __construct(private array $config, private Access $access)
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
     *                  request doesn't locate the map, it asks for an unknown
     *                  `format`, or (with `errors.strict`) a marker or path can't be drawn.
     */
    public function render(Request $request): Response
    {
        // The key first: in restricted mode, a request without one learns nothing else.
        [$client, $keyHeaders, $keyError] = $this->access->client($request);
        if ($keyError !== null) {
            return Response::error(403, $keyError, $keyHeaders);
        }
        if ($this->access->restricted && $client === null) {
            return Response::error(403, 'A valid key is required to draw maps on this server', $keyHeaders);
        }

        try {
            $params = Parameters::parse($request, $this->config);
            // GeoJSON features become markers and paths after the request's own (#38).
            $geojson = GeoJson::toOverlays($request->get('geojson'));
        } catch (InvalidRequest $e) {
            return Response::error(400, $e->getMessage());
        }
        $format = $request->get('format', $this->config['defaults']['format']);
        if (!in_array($format, self::FORMATS, true)) {
            return Response::error(400, 'format must be ' . implode(', ', self::formats()));
        }
        $formatHeaders = [];
        if (!in_array($format, self::formats(), true)) {
            // Known but not available here (WebP without GD support): PNG, with a notice.
            $formatHeaders[] = "X-Map-Format: $format is not available on this server; sent png";
            $format = 'png';
        }
        $position = $request->get('attribution_position', 'bottomright');
        if ($position === '') {
            $position = 'bottomright';
        }
        if (!in_array($position, Attribution::POSITIONS, true)) {
            return Response::error(400, 'attribution_position must be ' . implode(', ', Attribution::POSITIONS));
        }

        $config = $this->config;
        $mayFetchUrls = $client !== null && $client->externalUrls;
        $remote = RemoteFetcher::fromConfig($config);

        // Markers, paths, viewport, map and overlays (#98).
        $plan = MapPlan::make(
            $config,
            $this->webmercator,
            $request,
            $client,
            $params,
            $geojson,
            $remote,
            array_merge($keyHeaders, $formatHeaders)
        );
        if ($plan instanceof Response) {
            return $plan;
        }
        $headers = $plan->headers;
        $markers = $plan->markers;
        $paths = $plan->paths;
        $basemap = $plan->basemap;
        $overlays = $plan->overlays;
        $zoom = $plan->zoom;
        $latitude = $plan->latitude;
        $longitude = $plan->longitude;
        $width = $params->width;
        $height = $params->height;

        // scale=2 (#10): the same area at twice the pixels. Everything is drawn at
        // twice the size and one pixel zoom level up; tiles come from the level
        // above, or as @2x tiles, or are enlarged at the map's highest zoom.
        $scale = $params->scale;
        $imageWidth = $width * $scale;
        $imageHeight = $height * $scale;
        $pixelZoom = $zoom + ($scale === 2 ? 1 : 0);

        $im = imagecreatetruecolor($imageWidth, $imageHeight);

        // Find the pixel coordinate of the center of the map
        $center = $this->webmercator->latLngToPixels($latitude, $longitude, $pixelZoom);

        $leftEdge = $center['x'] - $imageWidth / 2;
        $topEdge = $center['y'] - $imageHeight / 2;

        // Every layer: the map's own (gray where a tile fails), then the overlays (transparent).
        $sources = [[$basemap, 'gray', 1.0]];
        foreach ($overlays as $overlay) {
            $sources[] = [$overlay, 'transparent', (float) $overlay['opacity']];
        }
        // Providers whose terms forbid caching proxies (cache => 'off') never use the tile cache.
        $tileCache = TileCache::fromConfig($config);
        $specs = [];
        foreach ($sources as $n => [$source, $blank, $opacity]) {
            foreach ((array) $source['layers'] as $template) {
                [$tileZoom, $tileSize, $retina] = self::tileGrid(
                    ['layers' => [$template], 'maxzoom' => $source['maxzoom']],
                    (int) $zoom,
                    $scale
                );
                if ($n === 0 && $specs === [] && $scale > 1 && $tileZoom === (int) $zoom && $retina === '') {
                    $headers[] = "X-Map-Scale: enlarged; the map has no tiles above zoom $zoom";
                }
                $specs[] = self::grid($center, $imageWidth, $imageHeight, $tileSize) + [
                    'template' => str_replace('{r}', $retina, (string) $template),
                    'key'      => $source['key'] ?? null,
                    'headers'  => Config::headerLines($source),
                    'zoom'     => $tileZoom,
                    'cache'    => ($source['cache'] ?? 'headers') !== 'off',
                    'blank'    => $blank,
                    'size'     => $tileSize,
                    'opacity'  => $opacity,
                ];
            }
        }
        $fetched = (new TileFetcher($remote, $tileCache))->fetchLayers($specs, $im);

        $compositor = new Compositor($this->webmercator);
        foreach ($specs as $i => $spec) {
            $compositor->drawTiles(
                $im,
                $fetched[$i],
                $spec['tilePos'],
                $spec['pos'],
                $imageWidth,
                $imageHeight,
                $spec['size'],
                $spec['opacity']
            );
        }
        $compositor->drawPaths(
            $im,
            $paths,
            $pixelZoom,
            $leftEdge,
            $topEdge,
            $imageWidth,
            $imageHeight,
            $params->bezier,
            $scale
        );
        $compositor->drawMarkers($im, $markers, $pixelZoom, $leftEdge, $topEdge, $imageWidth, $imageHeight, $scale);

        // The request's attribution, else the basemap's default, else the global
        // default. An unknown value counts as no value.
        $attribution = $request->get('attribution');
        // Like icon URLs and custom tiles, attribution image URLs need a valid token.
        if (Attribution::isUrl($attribution) && !$mayFetchUrls) {
            $headers[] = 'X-Map-Attribution: attribution image URLs need a valid key';
            $attribution = false;
        }
        if (!Attribution::isKnown($attribution)) {
            $attribution = $basemap['attribution'] ?? $config['defaults']['attribution'];
        }
        // The map's credit, then each overlay's (#30).
        $credits = array_filter(
            array_map(
                static fn (array $m): string => (string) ($m['attribution_text'] ?? ''),
                [$basemap, ...$overlays]
            ),
            static fn (string $t): bool => $t !== ''
        );
        $text = $credits === [] ? null : implode(' | ', $credits);
        (new Attribution($remote))->draw($im, $attribution, $text, $position, $imageWidth, $imageHeight, $scale);

        [$typeHeaders, $body] = $this->encode($im, $format, $params->quality);
        $cacheHeaders = $this->cacheHeaders($headers, $basemap, $client !== null && !$client->signed, $body);
        $headers = array_merge($headers, $typeHeaders, $cacheHeaders);

        return new Response($headers, $body);
    }

    /**
     * The tiles a layer needs to cover the image.
     *
     * @param array{x: int|float, y: int|float} $center   The map centre in world pixels.
     * @param int                               $width    Image width in pixels.
     * @param int                               $height   Image height in pixels.
     * @param int                               $tileSize Size each tile is drawn at, in pixels.
     *
     * @return array{tilePos: array{x: float, y: float}, pos: array{x: float, y: float},
     *               sw: array{x: float, y: float}, ne: array{x: float, y: float}} The tile under the
     *         centre, where in it the centre is, and the top-left and bottom-right tiles.
     */
    private static function grid(array $center, int $width, int $height, int $tileSize): array
    {
        $tilePos = ['x' => floor($center['x'] / $tileSize), 'y' => floor($center['y'] / $tileSize)];

        return [
            'tilePos' => $tilePos,
            'pos'     => [
                'x' => round($tileSize * (($center['x'] / $tileSize) - $tilePos['x'])),
                'y' => round($tileSize * (($center['y'] / $tileSize) - $tilePos['y'])),
            ],
            'sw'      => [
                'x' => floor(($center['x'] - $width / 2) / $tileSize),
                'y' => floor(($center['y'] - $height / 2) / $tileSize),
            ],
            'ne'      => [
                'x' => floor(($center['x'] + $width / 2) / $tileSize),
                'y' => floor(($center['y'] + $height / 2) / $tileSize),
            ],
        ];
    }

    /**
     * Which tiles to draw a map with, for its pixel density.
     *
     * At scale 1, the tiles of the map's zoom. At scale 2 the image is drawn one
     * pixel zoom level up, so: 512 px `@2x` tiles of the same zoom if the map's
     * URLs have the `{r}` placeholder; else the 256 px tiles one level up; else
     * (at the map's highest zoom) the same tiles enlarged to 512 px.
     *
     * @param array<string, mixed> $basemap The map drawn (`layers`, `maxzoom`).
     * @param int                  $zoom    The map's zoom (after clampZoom()).
     * @param int                  $scale   Pixel density, 1 or 2.
     *
     * @return array{int, int, string} Zoom of the tiles fetched, the size each tile is drawn at
     *         in pixels, and what replaces `{r}` in tile URLs ('' or '@2x').
     */
    private static function tileGrid(array $basemap, int $zoom, int $scale): array
    {
        if ($scale === 1) {
            return [$zoom, WebMercator::TILE_SIZE, ''];
        }
        if (str_contains(implode(' ', (array) $basemap['layers']), '{r}')) {
            return [$zoom, 2 * WebMercator::TILE_SIZE, '@2x'];
        }
        if ($zoom + 1 <= (int) $basemap['maxzoom']) {
            return [$zoom + 1, WebMercator::TILE_SIZE, ''];
        }

        return [$zoom, 2 * WebMercator::TILE_SIZE, ''];
    }

    /**
     * Cache headers for a map image (#11).
     *
     * Shared caches may keep the image for `cache.max_age` seconds. A map drawn
     * instead of the requested one gets `cache.fallback_max_age`. Images for
     * requests with a plain client key, or from a provider with `cache => 'off'`,
     * are private; signed URLs are cacheable like free maps. The ETag is a hash of the image.
     *
     * @param list<string>         $headers          Headers collected so far (for X-Map-Fallback).
     * @param array<string, mixed> $basemap          The map drawn (for its `cache` policy).
     * @param bool                 $is_authenticated Whether the request used a plain key (a signed
     *                                              URL is meant to be published, so it may be
     *                                              cached like any other).
     * @param string               $body             The encoded image.
     *
     * @return list<string> Cache-Control and ETag header lines.
     */
    private function cacheHeaders(array $headers, array $basemap, bool $is_authenticated, string $body): array
    {
        $fallback = false;
        foreach ($headers as $header) {
            if (stripos($header, 'X-Map-Fallback:') === 0) {
                $fallback = true;
            }
        }
        $private = $is_authenticated || ($basemap['cache'] ?? 'headers') === 'off';
        $maxAge = (int) $this->config['cache'][$fallback ? 'fallback_max_age' : 'max_age'];

        return [
            'Cache-Control: ' . ($private ? 'private' : 'public') . ', max-age=' . $maxAge,
            'ETag: "' . sha1($body) . '"',
        ];
    }

    /**
     * Encode the image.
     *
     * @param GdImage $im      The finished map.
     * @param string  $format  One of formats().
     * @param int     $quality JPEG and WebP quality, 1-100.
     *
     * @return array{list<string>, string} Content-Type header and the encoded image.
     */
    private function encode(GdImage $im, string $format, int $quality): array
    {
        ob_start();
        switch ($format) {
            case 'jpg':
            case 'jpeg':
                $type = 'image/jpeg';
                imagejpeg($im, null, $quality);
                break;
            case 'webp':
                $type = 'image/webp';
                imagewebp($im, null, $quality);
                break;
            case 'png8':
                $type = 'image/png';
                // Reduce to a 256-colour palette: much smaller files for maps with few colours.
                imagetruecolortopalette($im, false, 256);
                imagepng($im, null, 9);
                break;
            default:
                $type = 'image/png';
                imagepng($im);
        }

        return [['Content-Type: ' . $type], (string) ob_get_clean()];
    }
}

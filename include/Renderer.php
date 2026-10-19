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
     *                  if the request doesn't locate the map or asks for an
     *                  unknown `format`.
     */
    public function render(Request $request): Response
    {
        if (($request->get('latitude') === false) !== ($request->get('longitude') === false)) {
            return Response::error(400, 'latitude and longitude must be given together');
        }
        $format = $request->get('format', $this->config['defaults']['format']);
        if (!in_array($format, self::FORMATS, true)) {
            return Response::error(400, 'format must be png or jpg');
        }

        $config = $this->config;
        $headers = array();
        $bounds = new Bounds();
        $is_authenticated = $this->tokens->isValid($request->get('token'));

        // Markers and paths; with no centre given, the map is centred on them
        $parsed = (new MarkerParser())->parse($request->get('marker'), $is_authenticated, $bounds);
        $markers = $parsed['markers'];
        $headers = array_merge($headers, $parsed['headers']);

        // Geocoding was removed (#16): `location` is ignored, with a deprecation notice.
        $usesLocation = $request->get('location') !== false;
        if ($usesLocation || $parsed['deprecated']) {
            $headers = array_merge($headers, Deprecation::headers());
        }

        $paths = (new PathParser($config['defaults']['path_color'], $config['defaults']['path_weight']))
            ->parse($request->get('path'), $request->get('polyline'), $bounds);

        $viewport = new Viewport($this->webmercator);
        $center = $viewport->center($request, $bounds);
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

        // The request's attribution, else the basemap's default, else the global
        // default. An unknown value counts as no value.
        $attribution = $request->get('attribution');
        if (!Attribution::isKnown($attribution)) {
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
     * - Anything unknown, disabled, removed or token-only (without a token) gets
     *   the default map, `defaults.provider` / `defaults.style`, and an
     *   `X-Map-Fallback` header saying so (#52).
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
        // What was asked for, for the fallback notice: the id as shown to the
        // client, and the `removed` keys that would explain a failure.
        $requested = null;
        $removedKeys = [];
        if ($provider !== false || $style !== false) {
            if ('custom' === $provider) {
                $requested = 'custom';
                $basemap = $this->customBasemap($request, $is_authenticated);
            } else {
                $p = is_string($provider) ? $provider : $config['defaults']['provider'];
                $s = is_string($style) ? $style : Config::defaultStyle($config, $p);
                $requested = $s === null ? $p : "$p/$s";
                $removedKeys = $s === null ? [$p] : ["$p/$s", $p];
                $basemap = $s === null ? null : Config::resolve($config, $p, $s, $is_authenticated);
                if ((!is_string($provider) && $provider !== false) || (!is_string($style) && $style !== false)) {
                    // e.g. provider[]=x: not a usable id, so it's a fallback like an unknown one.
                    $requested = '?';
                    $basemap = null;
                }
            }
        } elseif ($legacy !== false) {
            $requested = is_string($legacy) ? $legacy : '?';
            $removedKeys = is_string($legacy) ? ["basemap:$legacy"] : [];
            if ('custom' === $legacy) {
                $basemap = $this->customBasemap($request, $is_authenticated);
            } elseif (is_string($legacy) && ($alias = Config::alias($config, $legacy)) !== null) {
                $basemap = Config::resolve($config, $alias[0], $alias[1], $is_authenticated);
                $removedKeys = array_merge($removedKeys, ["$alias[0]/$alias[1]", $alias[0]]);
            }
        }

        if ($basemap === null) {
            // Config::validate() guarantees the default resolves without a token.
            $default = $config['defaults']['provider'] . '/' . $config['defaults']['style'];
            $basemap = (array) Config::resolve(
                $config,
                $config['defaults']['provider'],
                $config['defaults']['style'],
                $is_authenticated
            );
            if ($requested !== null) {
                $headers = array_merge($headers, self::fallbackHeaders($config, $requested, $removedKeys, $default));
            }
        }

        return [$basemap, $headers];
    }

    /**
     * Headers telling the client that the default map was drawn instead of the requested one.
     *
     * `removed` means the request names a provider, style or basemap id that was
     * taken out because it stopped working (listed in the `removed` setting);
     * `unavailable` covers everything else (unknown, switched off, token-only,
     * `custom` without a token), so the header doesn't reveal which of those it was.
     *
     * @param array<string, mixed> $config      The configuration.
     * @param string               $requested   The requested id as received.
     * @param list<string>         $removedKeys Keys of `removed` that would explain the failure.
     * @param string               $drawn       The `provider/style` drawn instead.
     *
     * @return list<string> Header lines.
     */
    private static function fallbackHeaders(array $config, string $requested, array $removedKeys, string $drawn): array
    {
        $removed = false;
        foreach ($removedKeys as $key) {
            if (isset($config['removed'][$key])) {
                $removed = true;
                break;
            }
        }

        // Only echo ids made of the characters ids use.
        if (!preg_match('#^[a-z0-9-]+(/[a-z0-9-]+)?$#', $requested)) {
            $requested = '?';
        }

        $reason = $removed ? 'removed' : 'unavailable';
        $headers = ["X-Map-Fallback: $reason; requested=$requested; drawn=$drawn"];
        if ($removed) {
            $headers[] = 'Link: <' . Deprecation::REMOVED_URL . '>; rel="help"';
        }

        return $headers;
    }

    /**
     * A one-layer map from the request's `tileurl`, for requests with a valid token.
     *
     * It uses the `zoom.min` / `zoom.max` range, capped by `zoom.max_limit`.
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

        $basemap = array(
            'layers'  => array($request->get('tileurl')),
            'minzoom' => $this->config['zoom']['min'],
            'maxzoom' => $this->config['zoom']['max'],
        );
        // zoom.max_limit caps custom tile URLs like every other map.
        $limit = $this->config['zoom']['max_limit'] ?? null;
        if ($limit !== null) {
            $basemap['maxzoom'] = min((int) $basemap['maxzoom'], (int) $limit);
            $basemap['minzoom'] = min((int) $basemap['minzoom'], $basemap['maxzoom']);
        }

        return $basemap;
    }

    /**
     * Encode the image as the requested `format` (already checked to be one of FORMATS).
     *
     * @param GdImage $im      The finished map.
     * @param Request $request The request (`format`, `quality`).
     *
     * @return array{list<string>, string} Content-type header and the encoded image.
     */
    private function encode(GdImage $im, Request $request): array
    {
        $headers = array();
        ob_start();
        $fmt = $request->get('format', $this->config['defaults']['format']);
        if ($fmt === 'jpg' || $fmt === 'jpeg') {
            $headers[] = 'Content-type: image/jpg';
            $quality = $request->get('quality', $this->config['defaults']['jpeg_quality']);
            imagejpeg($im, null, $quality);
        } else {
            $headers[] = 'Content-type: image/png';
            imagepng($im);
        }

        return [$headers, (string) ob_get_clean()];
    }
}

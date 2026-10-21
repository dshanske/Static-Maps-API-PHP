<?php

/**
 * The read-only query API under /api/v1/.
 */

namespace StaticMaps;

/**
 * Answers requests under `/api/` with JSON describing what this install supports.
 *
 * Endpoints (GET or HEAD):
 *
 * - `/api/v1/`          Service version, defaults, size and zoom limits, formats, links.
 * - `/api/v1/providers` Providers and styles that can be drawn, removed ids and
 *                       deprecated basemap ids.
 * - `/api/v1/icons`     Built-in marker icons.
 * - `/api/v1/overlays`  Overlays a request can add with `overlay=` (#30).
 * - `/api/v1/attribution` The credit for one map, chosen by the same
 *                       `provider` / `style` / `basemap` parameters as the image.
 *
 * It never returns tile URLs or API keys. Providers and styles that need a key
 * are only listed for requests with a valid `key`, as `X-Map-Fallback` doesn't
 * reveal them either (#52).
 */
final class Api
{
    /** @var int Seconds clients and caches may keep a response without a token. */
    private const MAX_AGE = 300;

    /**
     * @param array<string, mixed> $config        Configuration from Config::load().
     * @param Client|null          $client        The request's client, or null without a valid key.
     * @param string               $base          URL path the service is installed under,
     *                                            without a trailing slash ('' at the site root).
     */
    public function __construct(private array $config, private ?Client $client, private string $base)
    {
    }

    /**
     * Whether a request path belongs to the API.
     *
     * @param string $path The request path relative to the install, e.g. `/api/v1/icons`.
     *
     * @return bool True for `/api` and anything under `/api/`.
     */
    public static function handles(string $path): bool
    {
        return $path === '/api' || str_starts_with($path, '/api/');
    }

    /**
     * Answer one API request.
     *
     * @param string  $method  HTTP method.
     * @param string  $path    Request path relative to the install, e.g. `/api/v1/icons`.
     * @param Request $request The request parameters (used by /attribution).
     *
     * @return Response JSON (no body for HEAD), or a JSON error: 404 for an unknown
     *                  endpoint, 405 for a method other than GET, HEAD or OPTIONS.
     */
    public function handle(string $method, string $path, Request $request): Response
    {
        $endpoint = rtrim($path, '/');
        $extraHeaders = [];
        $status = 200;
        $data = match ($endpoint) {
            '/api/v1'             => $this->root(),
            '/api/v1/providers'   => $this->providers(),
            '/api/v1/icons'       => $this->icons(),
            '/api/v1/overlays'    => $this->overlays(),
            '/api/v1/attribution' => $this->attribution($request, $extraHeaders),
            '/api/v1/plan'        => $this->planData($request, $extraHeaders, $status),
            default               => null,
        };

        if ($data === null) {
            return $this->json(404, ['error' => 'Unknown endpoint', 'endpoints' => $this->links()], $method);
        }
        if ($method === 'OPTIONS') {
            return new Response(array_merge($this->headers(), ['Allow: GET, HEAD, OPTIONS']), '', 204);
        }
        if ($method !== 'GET' && $method !== 'HEAD') {
            $response = $this->json(405, ['error' => 'Only GET and HEAD are supported'], 'GET');

            return $response->withHeaders(['Allow: GET, HEAD, OPTIONS']);
        }

        return $this->json($status, $data, $method)->withHeaders($extraHeaders);
    }

    /**
     * `/api/v1/`: what the service is and its general settings.
     *
     * @return array<string, mixed> The response data.
     */
    private function root(): array
    {
        $defaults = $this->config['defaults'];

        return [
            'name'         => 'Static Maps API',
            'version'      => Version::VERSION,
            'api_version'  => 1,
            'defaults'     => [
                'provider'    => $defaults['provider'],
                'style'       => $defaults['style'],
                'width'       => $defaults['width'],
                'height'      => $defaults['height'],
                'format'      => $defaults['format'],
                'attribution' => $defaults['attribution'],
            ],
            'limits'       => [
                'max_width'  => $this->config['limits']['max_width'],
                'max_height' => $this->config['limits']['max_height'],
            ],
            'zoom'         => [
                'min'       => $this->config['zoom']['min'],
                'max'       => $this->config['zoom']['max'],
                'max_limit' => $this->config['zoom']['max_limit'] ?? null,
            ],
            'formats'      => Renderer::formats(),
            'scales'       => range(1, Parameters::MAX_SCALE),
            'attributions' => Attribution::NAMES,
            'attribution_positions' => Attribution::POSITIONS,
            'access'       => [
                'mode'         => $this->config['access']['mode'],
                'credentials'  => $this->config['access']['credentials'],
                'key_required' => $this->config['access']['mode'] === 'restricted',
                'key_valid'    => $this->client !== null,
            ],
            'custom_tiles' => $this->client !== null && $this->client->externalUrls,
            'links'        => $this->links(),
        ];
    }

    /**
     * `/api/v1/providers`: every provider and style that can be drawn for this request.
     *
     * @return array<string, mixed> The response data.
     */
    private function providers(): array
    {
        $providers = [];
        foreach ($this->config['providers'] as $id => $provider) {
            $styles = [];
            foreach (array_keys($provider['styles']) as $styleId) {
                $style = Config::resolve($this->config, (string) $id, (string) $styleId, $this->client);
                if ($style === null) {
                    continue;
                }
                $styles[] = [
                    'id'            => (string) $styleId,
                    'name'          => (string) ($style['name'] ?? $styleId),
                    'minzoom'       => $style['minzoom'],
                    'maxzoom'       => $style['maxzoom'],
                    'attribution'   => $style['attribution'] ?? $this->config['defaults']['attribution'],
                    'attribution_text' => $style['attribution_text'] ?? null,
                    'attribution_html' => $style['attribution_html'] ?? null,
                    'labels_layer'  => count($style['layers']) > 1,
                    'requires_auth' => (bool) ($style['requires_auth'] ?? false),
                    'needs_key'     => (bool) ($style['needs_client'] ?? false),
                    'bounds'        => $style['bounds'] ?? null,
                ];
            }
            if ($styles === []) {
                continue;
            }
            $default = Config::defaultStyle($this->config, (string) $id);
            $providers[] = [
                'id'            => (string) $id,
                'name'          => (string) ($provider['name'] ?? $id),
                'default_style' => in_array($default, array_column($styles, 'id'), true) ? $default : $styles[0]['id'],
                'styles'        => $styles,
            ];
        }

        $deprecated = [];
        foreach (array_keys($this->config['basemap_aliases']) as $old) {
            $alias = Config::alias($this->config, (string) $old);
            if ($alias === null) {
                continue;
            }
            if (Config::resolve($this->config, $alias[0], $alias[1], $this->client) !== null) {
                $deprecated[(string) $old] = ['provider' => $alias[0], 'style' => $alias[1]];
            }
        }

        $removed = [];
        foreach ($this->config['removed'] as $key => $reason) {
            $removed[] = ['id' => (string) $key, 'reason' => (string) $reason];
        }

        return [
            'default'             => [
                'provider' => $this->config['defaults']['provider'],
                'style'    => $this->config['defaults']['style'],
            ],
            'providers'           => $providers,
            'deprecated_basemaps' => (object) $deprecated,
            'removed'             => $removed,
        ];
    }

    /**
     * `/api/v1/overlays`: the overlays this client may add with `overlay=` (#30).
     *
     * @return array<string, mixed> The response data.
     */
    private function overlays(): array
    {
        $overlays = [];
        foreach (array_keys($this->config['overlays']) as $id) {
            $overlay = Config::resolveOverlay($this->config, (string) $id, $this->client);
            if ($overlay === null) {
                continue;
            }
            $overlays[] = [
                'id'               => (string) $id,
                'name'             => (string) ($overlay['name'] ?? $id),
                'minzoom'          => $overlay['minzoom'],
                'maxzoom'          => $overlay['maxzoom'],
                'opacity'          => $overlay['opacity'],
                'bounds'           => $overlay['bounds'] ?? null,
                'attribution_text' => $overlay['attribution_text'] ?? null,
                'attribution_html' => $overlay['attribution_html'] ?? null,
                'needs_key'        => (bool) ($overlay['needs_client'] ?? false),
            ];
        }

        return ['overlays' => $overlays, 'max_overlays' => (int) $this->config['limits']['max_overlays']];
    }

    /**
     * `/api/v1/attribution`: the credit for the map a request would draw.
     *
     * Takes the same `provider`, `style`, `basemap`, `tileurl`, `key`, `overlay` and
     * `attribution` parameters as a map request, so a client can pass the
     * image's query string and show the result next to the image. When the
     * parameters locate the map, it is planned as the image would be (#95): a
     * regional map entirely outside its area is reported as the default map
     * (`fallback` `outside...`), and overlays outside their zoom range or area
     * are left out. Without a location, the map is chosen from the
     * provider and style alone.
     *
     * @param Request      $request The request.
     * @param list<string> $headers Receives headers to send (fallback and deprecation notices).
     *
     * @return array<string, mixed> The response data.
     */
    private function attribution(Request $request, array &$headers): array
    {
        $chooser = new MapChooser($this->config);
        [$map, $headers] = $chooser->choose($request, $this->client);
        [$overlays, $overlayHeaders] = $chooser->overlays($request, $this->client);
        $headers = array_merge($headers, $overlayHeaders);
        $plan = $this->plan($request);
        if ($plan instanceof MapPlan) {
            // The map and overlays the image would really use, and the notices about them.
            $map = $plan->basemap;
            $overlays = $plan->overlays;
            $headers = self::notices($plan);
        }

        $logo = $request->get('attribution');
        if (Attribution::isUrl($logo) && ($this->client === null || !$this->client->externalUrls)) {
            $logo = false;
        }
        if (!Attribution::isKnown($logo)) {
            $logo = $map['attribution'] ?? $this->config['defaults']['attribution'];
        }
        // The map's credit, then each overlay's (#30).
        $texts = [];
        $htmls = [];
        foreach ([$map, ...$overlays] as $layer) {
            $layerText = isset($layer['attribution_text']) ? (string) $layer['attribution_text'] : null;
            if ($layerText !== null && $layerText !== '') {
                $texts[] = $layerText;
            }
            $layerHtml = isset($layer['attribution_html'])
                ? (string) $layer['attribution_html']
                : ($layerText === null ? null : htmlspecialchars($layerText, ENT_QUOTES | ENT_HTML5));
            if ($layerHtml !== null && $layerHtml !== '') {
                $htmls[] = $layerHtml;
            }
        }
        $text = $texts === [] ? null : implode(' | ', $texts);
        $html = $htmls === [] ? null : implode(' | ', $htmls);

        $notice = null;
        foreach ($headers as $header) {
            if (stripos($header, 'X-Map-Fallback:') === 0) {
                $notice = trim(substr($header, strlen('X-Map-Fallback:')));
            }
        }

        return [
            'provider'    => $map['provider'] ?? null,
            'style'       => $map['style'] ?? null,
            'overlays'    => array_column($overlays, 'overlay'),
            'fallback'    => $notice,
            'attribution' => $logo,
            'text'        => $text,
            'html'        => $html,
            'plain'       => $html === null
                ? null
                : html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ];
    }

    /**
     * `/api/v1/plan`: what the image for the same parameters would show (#101).
     *
     * @param Request      $request The request (the image's parameters).
     * @param list<string> $headers Receives headers to send (fallback, overlay and deprecation notices).
     * @param int          $status  Receives the HTTP status: 400 when the image would get one.
     *
     * @return array<string, mixed> The response data, or `error` with the reason.
     */
    private function planData(Request $request, array &$headers, int &$status): array
    {
        $plan = $this->plan($request);
        if (!$plan instanceof MapPlan) {
            $status = 400;

            return ['error' => $plan];
        }
        $headers = self::notices($plan);
        $mercator = new WebMercator();
        $params = Parameters::parse($request, $this->config);
        [$west, $south, $east, $north] = $plan->bounds($mercator, $params->width, $params->height);
        $fallback = null;
        foreach ($headers as $header) {
            if (stripos($header, 'X-Map-Fallback:') === 0) {
                $fallback = trim(substr($header, strlen('X-Map-Fallback:')));
            }
        }

        return [
            'provider' => $plan->basemap['provider'] ?? null,
            'style'    => $plan->basemap['style'] ?? null,
            'fallback' => $fallback,
            'overlays' => array_column($plan->overlays, 'overlay'),
            'width'    => $params->width,
            'height'   => $params->height,
            'scale'    => $params->scale,
            'zoom'     => (int) $plan->zoom,
            'center'   => ['latitude' => round($plan->latitude, 6), 'longitude' => round($plan->longitude, 6)],
            'bounds'   => [round($west, 6), round($south, 6), round($east, 6), round($north, 6)],
            'markers'  => $plan->markerPositions($mercator, $params->width, $params->height),
        ];
    }

    /**
     * A plan's notices that describe the map rather than its drawing.
     *
     * @param MapPlan $plan The plan.
     *
     * @return list<string> Its X-Map-Fallback, X-Map-Overlay, X-Map-Coverage, Deprecation and Link headers.
     */
    private static function notices(MapPlan $plan): array
    {
        $pattern = '/^(X-Map-(Fallback|Overlay|Coverage)|Deprecation|Link):/i';

        return array_values(array_filter(
            $plan->headers,
            static fn (string $h): bool => preg_match($pattern, $h) === 1
        ));
    }

    /**
     * Plan a request's map as the renderer would, without fetching tiles or icon URLs.
     *
     * @param Request $request The request.
     *
     * @return MapPlan|string The plan; or, when the image request would get a 400 (it
     *                        doesn't locate a map, or its parameters are invalid), the reason.
     */
    private function plan(Request $request): MapPlan|string
    {
        try {
            $params = Parameters::parse($request, $this->config);
            $geojson = GeoJson::toOverlays($request->get('geojson'));
        } catch (InvalidRequest $e) {
            return $e->getMessage();
        }
        $plan = MapPlan::make(
            $this->config,
            new WebMercator(),
            $request,
            $this->client,
            $params,
            $geojson,
            RemoteFetcher::fromConfig($this->config),
            [],
            false
        );

        return $plan instanceof MapPlan ? $plan : $plan->body;
    }

    /**
     * `/api/v1/icons`: the built-in marker icons.
     *
     * @return array<string, mixed> The response data.
     */
    private function icons(): array
    {
        $icons = [];
        $files = glob(MarkerParser::ICON_DIR . '/*.png') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file, '.png');
            $size = getimagesize($file);
            $icons[] = [
                'name'   => $name,
                'width'  => $size[0] ?? null,
                'height' => $size[1] ?? null,
                'anchor' => str_starts_with($name, 'dot-') ? 'center' : 'bottom-center',
                'url'    => $this->base . '/images/' . rawurlencode($name) . '.png',
            ];
        }

        return [
            'icons'     => $icons,
            // Icons drawn on request (#37): icon:pin or icon:dot with these options, or icon:none for a label.
            'generated' => [
                'types'           => MarkerIcon::TYPES,
                'sizes'           => array_keys(MarkerIcon::PIN_SIZES),
                'pin_heights'     => MarkerIcon::PIN_SIZES,
                'dot_diameters'   => MarkerIcon::DOT_SIZES,
                'default_color'   => MarkerIcon::DEFAULT_COLOR,
                'max_label'       => ['pin' => MarkerIcon::MAX_PIN_LABEL, 'dot' => MarkerIcon::MAX_PIN_LABEL,
                    'none' => MarkerIcon::MAX_TEXT_LABEL],
            ],
            'anchors'   => array_keys(MarkerIcon::ANCHORS),
            'url_limits' => [
                'icons'  => (int) $this->config['limits']['icon_urls'],
                'bytes'  => (int) $this->config['limits']['icon_bytes'],
                'pixels' => (int) $this->config['limits']['icon_pixels'],
            ],
        ];
    }

    /**
     * Paths of the endpoints.
     *
     * @return array<string, string> Endpoint name => path.
     */
    private function links(): array
    {
        return [
            'self'      => $this->base . '/api/v1/',
            'providers' => $this->base . '/api/v1/providers',
            'icons'     => $this->base . '/api/v1/icons',
            'overlays'  => $this->base . '/api/v1/overlays',
            'attribution' => $this->base . '/api/v1/attribution',
            'plan'      => $this->base . '/api/v1/plan',
        ];
    }

    /**
     * Headers sent with every API response.
     *
     * Responses to requests with a valid token can list token-only maps, so they
     * are private; the others may be cached by anyone for MAX_AGE seconds.
     *
     * @return list<string> Header lines.
     */
    private function headers(): array
    {
        return [
            'Access-Control-Allow-Origin: *',
            'Access-Control-Allow-Methods: GET, HEAD, OPTIONS',
            'Cache-Control: ' . ($this->client !== null ? 'private, no-store' : 'public, max-age=' . self::MAX_AGE),
        ];
    }

    /**
     * A JSON response.
     *
     * @param int                  $status HTTP status code.
     * @param array<string, mixed> $data   Data to encode.
     * @param string               $method Request method; HEAD gets no body.
     *
     * @return Response The response.
     */
    private function json(int $status, array $data, string $method): Response
    {
        $body = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

        return new Response(
            array_merge($this->headers(), ['Content-Type: application/json; charset=utf-8']),
            $method === 'HEAD' ? '' : $body,
            $status
        );
    }
}

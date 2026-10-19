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
 * - `/api/v1/`          Service version, defaults, zoom limits, formats, links.
 * - `/api/v1/providers` Providers and styles that can be drawn, removed ids and
 *                       deprecated basemap ids.
 * - `/api/v1/icons`     Built-in marker icons.
 *
 * It never returns tile URLs or API keys. Token-only providers and styles are
 * only listed for requests with a valid `token`, as `X-Map-Fallback` doesn't
 * reveal them either (#52).
 */
final class Api
{
    /** @var int Seconds clients and caches may keep a response without a token. */
    private const MAX_AGE = 300;

    /**
     * @param array<string, mixed> $config        Configuration from Config::load().
     * @param bool                 $authenticated Whether the request has a valid token.
     * @param string               $base          URL path the service is installed under,
     *                                            without a trailing slash ('' at the site root).
     */
    public function __construct(private array $config, private bool $authenticated, private string $base)
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
     * @param string $method HTTP method.
     * @param string $path   Request path relative to the install, e.g. `/api/v1/icons`.
     *
     * @return Response JSON (no body for HEAD), or a JSON error: 404 for an unknown
     *                  endpoint, 405 for a method other than GET, HEAD or OPTIONS.
     */
    public function handle(string $method, string $path): Response
    {
        $endpoint = rtrim($path, '/');
        $data = match ($endpoint) {
            '/api/v1'           => $this->root(),
            '/api/v1/providers' => $this->providers(),
            '/api/v1/icons'     => $this->icons(),
            default             => null,
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

        return $this->json(200, $data, $method);
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
            'zoom'         => [
                'min'       => $this->config['zoom']['min'],
                'max'       => $this->config['zoom']['max'],
                'max_limit' => $this->config['zoom']['max_limit'] ?? null,
            ],
            'formats'      => ['png', 'jpg'],
            'attributions' => ['osm', 'esri', 'mapbox', 'none'],
            'custom_tiles' => $this->authenticated,
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
                $style = Config::resolve($this->config, (string) $id, (string) $styleId, $this->authenticated);
                if ($style === null) {
                    continue;
                }
                $styles[] = [
                    'id'            => (string) $styleId,
                    'name'          => (string) ($style['name'] ?? $styleId),
                    'minzoom'       => $style['minzoom'],
                    'maxzoom'       => $style['maxzoom'],
                    'attribution'   => $style['attribution'] ?? $this->config['defaults']['attribution'],
                    'labels_layer'  => count($style['layers']) > 1,
                    'requires_auth' => (bool) ($style['requires_auth'] ?? false),
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
            if (Config::resolve($this->config, $alias[0], $alias[1], $this->authenticated) !== null) {
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

        return ['icons' => $icons];
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
            'Cache-Control: ' . ($this->authenticated ? 'private, no-store' : 'public, max-age=' . self::MAX_AGE),
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

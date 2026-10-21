<?php

/**
 * Choosing which map (provider and style) a request draws.
 */

namespace StaticMaps;

/**
 * Turns a request's `provider`, `style`, deprecated `basemap` and
 * `tileurl` into the settings of the map to draw, falling back to the
 * default map with a notice. Shared by the renderer and the query API's
 * /attribution endpoint, so both always agree.
 */
final class MapChooser
{
    /**
     * @param array<string, mixed> $config Configuration from Config::load().
     */
    public function __construct(private array $config)
    {
    }

    /**
     * The map to draw.
     *
     * - `provider` and/or `style`: that provider (default: `defaults.provider`)
     *   with that style (default: the provider's `default_style`).
     *   `provider=custom` uses `tileurl` and needs a valid key.
     * - Otherwise the deprecated `basemap`: the provider/style its id maps to in
     *   `basemap_aliases`, or `basemap=custom` with `tileurl` and a token. Any use
     *   of `basemap` adds `Deprecation` and `Link` headers.
     * - Anything unknown, disabled, removed or token-only (without a token) gets
     *   the default map, `defaults.provider` / `defaults.style`, and an
     *   `X-Map-Fallback` header saying so (#52).
     *
     * @param Request $request          The request.
     * @param Client|null $client The request's client, or null without a valid key.
     *
     * @return array{array<string, mixed>, list<string>} Map settings (with `layers`,
     *         `minzoom` and `maxzoom`), and headers to send.
     */
    public function choose(Request $request, ?Client $client): array
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
                $basemap = $this->customBasemap($request, $client);
            } else {
                $p = is_string($provider) ? $provider : $config['defaults']['provider'];
                $s = is_string($style) ? $style : Config::defaultStyle($config, $p);
                $requested = $s === null ? $p : "$p/$s";
                $removedKeys = $s === null ? [$p] : ["$p/$s", $p];
                $basemap = $s === null ? null : Config::resolve($config, $p, $s, $client);
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
                $basemap = $this->customBasemap($request, $client);
            } elseif (is_string($legacy) && ($alias = Config::alias($config, $legacy)) !== null) {
                $basemap = Config::resolve($config, $alias[0], $alias[1], $client);
                $removedKeys = array_merge($removedKeys, ["$alias[0]/$alias[1]", $alias[0]]);
            }
        }

        if ($basemap === null) {
            // Config::validate() guarantees the default resolves without a key in open
            // mode; in restricted mode the renderer only gets here with a client.
            $default = $config['defaults']['provider'] . '/' . $config['defaults']['style'];
            $basemap = (array) Config::resolve(
                $config,
                $config['defaults']['provider'],
                $config['defaults']['style'],
                $client,
                true
            );
            if ($requested !== null) {
                $headers = array_merge($headers, self::fallbackHeaders($config, $requested, $removedKeys, $default));
            }
        }

        return [$basemap, $headers];
    }

    /**
     * The default map, for a regional map whose coverage (`bounds`) is entirely
     * outside the map's area (#30).
     *
     * @param array<string, mixed> $basemap The map that was chosen (`provider`, `style`).
     * @param Client|null          $client  The request's client.
     *
     * @return array{array<string, mixed>, list<string>}|null The default map and the
     *         `X-Map-Fallback: outside` header, or null if $basemap is the default map.
     */
    public function outside(array $basemap, ?Client $client): ?array
    {
        $config = $this->config;
        [$provider, $style] = [$config['defaults']['provider'], $config['defaults']['style']];
        if (($basemap['provider'] ?? null) === $provider && ($basemap['style'] ?? null) === $style) {
            return null;
        }
        $default = (array) Config::resolve($config, $provider, $style, $client, true);
        $requested = isset($basemap['style']) ? "{$basemap['provider']}/{$basemap['style']}" : 'custom';

        return [$default, ["X-Map-Fallback: outside; requested=$requested; drawn=$provider/$style"]];
    }

    /**
     * The overlays a request asks for (#30), in order.
     *
     * `overlay` is a comma-separated list of ids, or `overlay[]` values. Unknown or
     * unavailable ones, and those over `limits.max_overlays`, are left out and
     * listed in one `X-Map-Overlay` header, e.g. `unavailable=a,b; over-limit=c; max=3`.
     * Repeated ids count once.
     *
     * @param Request     $request The request.
     * @param Client|null $client  The request's client.
     *
     * @return array{list<array<string, mixed>>, list<string>} The overlays (from
     *         Config::resolveOverlay()) and headers to send.
     */
    public function overlays(Request $request, ?Client $client): array
    {
        $value = $request->get('overlay');
        if ($value === false || $value === '') {
            return [[], []];
        }
        $ids = [];
        foreach (is_array($value) ? $value : [$value] as $part) {
            foreach (is_string($part) ? explode(',', $part) : ['?'] as $id) {
                $id = trim($id);
                if ($id !== '' && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        $max = (int) $this->config['limits']['max_overlays'];
        $overlays = [];
        $unavailable = [];
        $over = [];
        foreach ($ids as $id) {
            $overlay = preg_match('/^[a-z0-9-]+$/', $id) ? Config::resolveOverlay($this->config, $id, $client) : null;
            if ($overlay === null) {
                $unavailable[] = preg_match('/^[a-z0-9-]+$/', $id) ? $id : '?';
            } elseif (count($overlays) >= $max) {
                $over[] = $id;
            } else {
                $overlays[] = $overlay;
            }
        }

        // One header (a response can't repeat it): what was left out, and why.
        $parts = [];
        if ($unavailable !== []) {
            $parts[] = 'unavailable=' . implode(',', $unavailable);
        }
        if ($over !== []) {
            $parts[] = 'over-limit=' . implode(',', $over) . "; max=$max";
        }

        return [$overlays, $parts === [] ? [] : ['X-Map-Overlay: ' . implode('; ', $parts)]];
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
     * @param Client|null $client The request's client, or null without a valid key.
     *
     * @return array<string, mixed>|null Map settings, or null without a token or `tileurl`.
     */
    private function customBasemap(Request $request, ?Client $client): ?array
    {
        if ($client === null || !$client->externalUrls || !is_string($request->get('tileurl'))) {
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
}

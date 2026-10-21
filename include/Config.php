<?php

/**
 * Loading, merging and querying of the service configuration.
 *
 * @see config/defaults.php for every setting and how to override it.
 */

namespace StaticMaps;

use RuntimeException;

/**
 * Service configuration: shipped defaults merged with the per-install file.
 */
class Config
{
    /** @var list<string> Settings that every style inherits from its provider and can override. */
    private const INHERITED = [
        'attribution',
        'attribution_text',
        'attribution_html',
        'cache',
        'minzoom',
        'maxzoom',
        'requires_auth',
        'enabled',
        'key',
        'headers',
        'bounds',
    ];

    /** @var int Most tile layers in one style or overlay. */
    public const MAX_LAYERS = 5;

    /**
     * Load the default settings and merge the per-install settings over them.
     *
     * The install file is optional. Its path comes from the STATIC_MAPS_CONFIG
     * environment variable if set, otherwise `data/config.php`. Deprecated
     * install settings (`defaults.basemap`, `basemaps`) are translated to the
     * current ones; a message for each is listed in the result's
     * `deprecations` key, for the caller to log.
     *
     * @param string|null $defaultsFile Path to the defaults file; null for config/defaults.php.
     * @param string|null $installFile  Path to the install file; null to use STATIC_MAPS_CONFIG
     *                                  or data/config.php.
     *
     * @return array<string, mixed> The merged and validated configuration.
     *
     * @throws RuntimeException If a file does not return an array or the result is invalid.
     */
    public static function load(?string $defaultsFile = null, ?string $installFile = null): array
    {
        $defaultsFile ??= __DIR__ . '/../config/defaults.php';
        if ($installFile === null) {
            $env = getenv('STATIC_MAPS_CONFIG');
            $installFile = ($env !== false && $env !== '') ? $env : __DIR__ . '/../data/config.php';
        }

        $config = self::readFile($defaultsFile);
        $deprecations = [];
        if (is_file($installFile)) {
            $install = self::migrate(self::readFile($installFile), $config, $deprecations);
            $config = self::merge($config, $install);
        }
        $config['deprecations'] = $deprecations;

        self::validate($config);

        return $config;
    }

    /**
     * Merge an override array over a base array.
     *
     * Arrays with named keys are merged key by key, recursively. Lists (such as
     * a style's `layers`) and scalar values in the override replace the base
     * value as a whole.
     *
     * @param array<mixed> $base     The array to merge into.
     * @param array<mixed> $override The values that take precedence.
     *
     * @return array<mixed> The merged array.
     */
    public static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (
                is_array($value) && !array_is_list($value)
                && isset($base[$key]) && is_array($base[$key]) && !array_is_list($base[$key])
            ) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Find the settings of one provider's style.
     *
     * The style inherits `attribution`, `attribution_text`, `attribution_html`, `minzoom`, `maxzoom`,
     * `requires_auth`, `enabled` and `key` from its provider unless it sets them itself. Without
     * either, `zoom.min` / `zoom.max` apply. `zoom.max_limit`, if set, caps the
     * result (and the minimum, if that is higher).
     *
     * Who may use it (#28): a style that uses a server-held provider key (its
     * tile URLs contain {key} and a key is set) or has `requires_auth` needs a
     * client, and one allowed to use the provider; with `access.mode` =
     * `restricted`, every style does. A style whose {key} isn't set is
     * unavailable to everyone.
     *
     * @param array<string, mixed> $config      The loaded configuration.
     * @param string               $provider    Provider id.
     * @param string               $style       Style id.
     * @param Client|null          $client      The request's client, or null without a valid key.
     * @param bool                 $isDefault   True when resolving the default map: a client's
     *                                          provider list doesn't apply to it.
     *
     * @return array<string, mixed>|null The style's settings (`name`, `layers`, the
     *         inherited keys, with `minzoom` and `maxzoom` always filled in, plus
     *         `provider`, `style` and `needs_client`), or null if the provider or
     *         style is unknown, disabled, needs an API key that isn't set, or
     *         needs a client the request doesn't have.
     */
    public static function resolve(
        array $config,
        string $provider,
        string $style,
        ?Client $client,
        bool $isDefault = false
    ): ?array {
        $p = $config['providers'][$provider] ?? null;
        $s = is_array($p) ? ($p['styles'][$style] ?? null) : null;
        if (!is_array($p) || !is_array($s) || ($p['enabled'] ?? true) === false) {
            return null;
        }

        $resolved = $s;
        foreach (self::INHERITED as $key) {
            if (!array_key_exists($key, $resolved) && array_key_exists($key, $p)) {
                $resolved[$key] = $p[$key];
            }
        }

        if (($resolved['enabled'] ?? true) === false) {
            return null;
        }
        $resolved = self::available($config, $resolved, $provider, $client, $isDefault);
        if ($resolved === null) {
            return null;
        }
        $resolved['provider'] = $provider;
        $resolved['style'] = $style;

        return $resolved;
    }

    /**
     * Resolve an overlay (#30): layers drawn on top of any map with `overlay=<id>`.
     *
     * The same rules as resolve() apply: it is unavailable when disabled, when it
     * needs an API key that isn't set, or when it needs a client the request
     * doesn't have (a client's `providers` list names overlays too).
     *
     * @param array<string, mixed> $config The loaded configuration.
     * @param string               $id     Overlay id.
     * @param Client|null          $client The request's client, or null without a valid key.
     *
     * @return array<string, mixed>|null The overlay's settings (with `minzoom`, `maxzoom` and
     *         `opacity` filled in, plus `overlay` and `needs_client`), or null if unknown or
     *         unavailable.
     */
    public static function resolveOverlay(array $config, string $id, ?Client $client): ?array
    {
        $overlay = $config['overlays'][$id] ?? null;
        if (!is_array($overlay) || ($overlay['enabled'] ?? true) === false) {
            return null;
        }
        $resolved = self::available($config, $overlay, $id, $client, false);
        if ($resolved === null) {
            return null;
        }
        $resolved['opacity'] = (float) ($resolved['opacity'] ?? 1);
        $resolved['overlay'] = $id;

        return $resolved;
    }

    /**
     * Apply the key and client rules to a style or overlay, and fill in its zoom range.
     *
     * @param array<string, mixed> $config    The loaded configuration.
     * @param array<string, mixed> $resolved  The style's or overlay's settings.
     * @param string               $id        Provider or overlay id (for a client's `providers` list).
     * @param Client|null          $client    The request's client, or null.
     * @param bool                 $isDefault True for the default map (a client's list doesn't apply).
     *
     * @return array<string, mixed>|null The settings with `needs_client`, `minzoom` and `maxzoom`,
     *         or null if unavailable.
     */
    private static function available(
        array $config,
        array $resolved,
        string $id,
        ?Client $client,
        bool $isDefault
    ): ?array {
        // A style that needs an API key is unavailable until one is set.
        if (self::needsKey($resolved) && !(is_string($resolved['key'] ?? null) && $resolved['key'] !== '')) {
            return null;
        }
        $needsClient = ($resolved['requires_auth'] ?? false)
            || self::needsKey($resolved)
            || ($config['access']['mode'] ?? 'open') === 'restricted';
        if ($needsClient && ($client === null || (!$isDefault && !$client->mayUse($id)))) {
            return null;
        }
        $resolved['needs_client'] = $needsClient;

        $resolved['minzoom'] = (int) ($resolved['minzoom'] ?? $config['zoom']['min']);
        $resolved['maxzoom'] = (int) ($resolved['maxzoom'] ?? $config['zoom']['max']);
        $limit = $config['zoom']['max_limit'] ?? null;
        if ($limit !== null) {
            $resolved['maxzoom'] = min($resolved['maxzoom'], (int) $limit);
            $resolved['minzoom'] = min($resolved['minzoom'], $resolved['maxzoom']);
        }

        return $resolved;
    }

    /**
     * Check a style's or overlay's `layers`.
     *
     * @param mixed  $layers The value.
     * @param string $what   What it belongs to, for the message, e.g. "Style 'osm/streets'".
     *
     * @return void
     *
     * @throws RuntimeException If it isn't a list of 1 to MAX_LAYERS tile URL templates.
     */
    private static function validateLayers(mixed $layers, string $what): void
    {
        $valid = is_array($layers) && array_is_list($layers) && count($layers) >= 1
            && count($layers) <= self::MAX_LAYERS
            && count(array_filter($layers, 'is_string')) === count($layers);
        if (!$valid) {
            throw new RuntimeException("$what needs 'layers': a list of 1 to " . self::MAX_LAYERS . ' tile URLs');
        }
    }

    /**
     * Check `headers` settings.
     *
     * @param mixed  $headers The value.
     * @param string $what    What it belongs to, for the message, e.g. "Provider 'x'".
     *
     * @return void
     *
     * @throws RuntimeException If it isn't a map of valid header names to values.
     */
    private static function validateHeaders(mixed $headers, string $what): void
    {
        if (!is_array($headers)) {
            throw new RuntimeException("$what headers must map header names to values");
        }
        foreach ($headers as $name => $value) {
            // Header names are tokens; values must not break the header (no CR, LF or NUL).
            if (
                !is_string($name) || !preg_match('/^[A-Za-z0-9-]+$/', $name)
                || !is_string($value) || preg_match('/[\r\n\0]/', $value)
            ) {
                throw new RuntimeException("$what has an invalid request header '$name'");
            }
        }
    }

    /**
     * Whether a `bounds` setting is valid.
     *
     * @param mixed $bounds The value.
     *
     * @return bool True for [minLng, minLat, maxLng, maxLat] in range with minLat <= maxLat
     *              (minLng > maxLng crosses the antimeridian).
     */
    public static function validBounds(mixed $bounds): bool
    {
        return is_array($bounds) && array_is_list($bounds) && count($bounds) === 4
            && Parameters::isValidPoint($bounds[1], $bounds[0])
            && Parameters::isValidPoint($bounds[3], $bounds[2])
            && $bounds[1] <= $bounds[3];
    }

    /**
     * Whether any of a style's tile URLs or request headers has a {key} placeholder.
     *
     * @param array<string, mixed> $style Style settings with `layers`.
     *
     * @return bool True if the style needs an API key.
     */
    private static function needsKey(array $style): bool
    {
        $templates = array_merge((array) ($style['layers'] ?? []), array_values((array) ($style['headers'] ?? [])));
        foreach ($templates as $template) {
            if (is_string($template) && str_contains($template, '{key}')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The request header lines a style sends with each tile request.
     *
     * @param array<string, mixed> $style A resolved style (its `headers` and `key`).
     *
     * @return list<string> Lines like `Authorization: Bearer abc`, with {key} filled in.
     */
    public static function headerLines(array $style): array
    {
        $lines = [];
        foreach ((array) ($style['headers'] ?? []) as $name => $value) {
            $lines[] = $name . ': ' . str_replace('{key}', (string) ($style['key'] ?? ''), (string) $value);
        }

        return $lines;
    }

    /**
     * The default style of a provider.
     *
     * @param array<string, mixed> $config   The loaded configuration.
     * @param string               $provider Provider id.
     *
     * @return string|null The style id (`default_style`, else the first style), or null
     *                     for an unknown provider.
     */
    public static function defaultStyle(array $config, string $provider): ?string
    {
        $p = $config['providers'][$provider] ?? null;
        if (!is_array($p) || !is_array($p['styles'] ?? null) || $p['styles'] === []) {
            return null;
        }

        return is_string($p['default_style'] ?? null) ? $p['default_style'] : (string) array_key_first($p['styles']);
    }

    /**
     * The provider and style a deprecated `basemap` id stands for.
     *
     * @param array<string, mixed> $config The loaded configuration.
     * @param string               $id     Old basemap id, e.g. `carto-dark`.
     *
     * @return array{string, string}|null Provider and style ids, or null for an unknown
     *                                    or switched-off id.
     */
    public static function alias(array $config, string $id): ?array
    {
        $target = $config['basemap_aliases'][$id] ?? null;
        if (!is_string($target) || substr_count($target, '/') !== 1) {
            return null;
        }

        [$provider, $style] = explode('/', $target);

        return [$provider, $style];
    }

    /**
     * Turn a tile URL template into the URL of one tile.
     *
     * @param string      $template Tile URL template with {z}, {x}, {y} and optionally {key}
     *                              (upper-case {Z}, {X}, {Y} also work).
     * @param int         $x        Tile column.
     * @param int         $y        Tile row.
     * @param int         $z        Zoom level.
     * @param string|null $key      API key for {key}, or null if the style has none.
     *
     * @return string The tile URL.
     */
    public static function tileUrl(string $template, int $x, int $y, int $z, ?string $key = null): string
    {
        $x = (string) $x;
        $y = (string) $y;
        $z = (string) $z;

        return str_replace(
            ['{x}', '{y}', '{z}', '{X}', '{Y}', '{Z}', '{key}'],
            [$x, $y, $z, $x, $y, $z, rawurlencode($key ?? '')],
            $template
        );
    }

    /**
     * Translate deprecated install settings to the current ones (#40).
     *
     * - `basemaps`: an entry whose id is in `basemap_aliases` becomes an
     *   override of that provider's style; an `alias_of` entry becomes a
     *   `basemap_aliases` entry (null, i.e. switched off, if it has
     *   `enabled => false`); any other id becomes a provider of that id with one
     *   style, `default`, plus an alias so `basemap=<id>` keeps working.
     * - `defaults.basemap`: becomes `defaults.provider` and `defaults.style`,
     *   unless the install file already sets one of those.
     *
     * @param array<mixed> $install      The install file's settings.
     * @param array<mixed> $shipped      The shipped defaults (for the alias table and provider ids).
     * @param list<string> $deprecations Receives one message per deprecated setting found.
     *
     * @return array<mixed> The install settings in the current format.
     *
     * @throws RuntimeException If an old basemap id can't be translated.
     */
    private static function migrate(array $install, array $shipped, array &$deprecations): array
    {
        $aliases = self::merge($shipped['basemap_aliases'] ?? [], $install['basemap_aliases'] ?? []);

        if (array_key_exists('basemaps', $install)) {
            $deprecations[] = "Setting 'basemaps' is deprecated; use 'providers' (see config/defaults.php)";
            $legacy = is_array($install['basemaps']) ? $install['basemaps'] : [];
            unset($install['basemaps']);

            $aliasEntries = [];
            foreach ($legacy as $id => $basemap) {
                if (!is_array($basemap)) {
                    throw new RuntimeException("Basemap '$id' must be an array");
                }
                if (isset($basemap['alias_of'])) {
                    $aliasEntries[$id] = $basemap;
                    continue;
                }
                if (is_string($aliases[$id] ?? null)) {
                    [$provider, $style] = explode('/', $aliases[$id]);
                } else {
                    if (isset($shipped['providers'][$id])) {
                        throw new RuntimeException(
                            "Basemap '$id' has the same id as a provider; define it under 'providers' instead"
                        );
                    }
                    [$provider, $style] = [(string) $id, 'default'];
                    $install['providers'][$provider]['name'] ??= $basemap['name'] ?? (string) $id;
                    $install['providers'][$provider]['default_style'] = 'default';
                    $aliases[$id] = $install['basemap_aliases'][$id] = "$provider/default";
                }
                $existing = $install['providers'][$provider]['styles'][$style] ?? [];
                $install['providers'][$provider]['styles'][$style] = self::merge($existing, $basemap);
            }

            foreach ($aliasEntries as $id => $basemap) {
                $target = $aliases[$basemap['alias_of']] ?? null;
                if (!is_string($target)) {
                    throw new RuntimeException(
                        "Basemap '$id' is an alias of unknown basemap '{$basemap['alias_of']}'"
                    );
                }
                $install['basemap_aliases'][$id] = ($basemap['enabled'] ?? true) === false ? null : $target;
                $aliases[$id] = $install['basemap_aliases'][$id];
            }
        }

        $defaults = $install['defaults'] ?? null;
        if (is_array($defaults) && array_key_exists('basemap', $defaults)) {
            $deprecations[] = "Setting 'defaults.basemap' is deprecated; use 'defaults.provider' and 'defaults.style'";
            $old = $defaults['basemap'];
            unset($install['defaults']['basemap']);
            if (!isset($defaults['provider']) && !isset($defaults['style'])) {
                $target = is_string($old) ? ($aliases[$old] ?? null) : null;
                if (!is_string($target) || substr_count($target, '/') !== 1) {
                    throw new RuntimeException(
                        "Default basemap '" . (is_string($old) ? $old : '?') . "' is unknown"
                    );
                }
                [$install['defaults']['provider'], $install['defaults']['style']] = explode('/', $target);
            }
        }

        return $install;
    }

    /**
     * Read one configuration file.
     *
     * @param string $file Path to a PHP file that returns an array.
     *
     * @return array<mixed> The array the file returns.
     *
     * @throws RuntimeException If the file is missing or does not return an array.
     */
    private static function readFile(string $file): array
    {
        if (!is_file($file)) {
            throw new RuntimeException("Configuration file not found: $file");
        }

        $data = include $file;
        if (!is_array($data)) {
            throw new RuntimeException("Configuration file does not return an array: $file");
        }

        return $data;
    }

    /**
     * Check that the merged configuration has the settings the renderer needs.
     *
     * @param array<mixed> $config The merged configuration.
     *
     * @return void
     *
     * @throws RuntimeException Describing the first problem found.
     */
    private static function validate(array $config): void
    {
        $sections = [
            'defaults', 'access', 'clients', 'limits', 'errors', 'cache', 'remote', 'zoom',
            'providers', 'overlays', 'basemap_aliases', 'removed',
        ];
        foreach ($sections as $section) {
            if (!isset($config[$section]) || !is_array($config[$section])) {
                throw new RuntimeException("Configuration setting '$section' must be an array");
            }
        }

        foreach ($config['providers'] as $id => $provider) {
            if ($id === 'custom') {
                throw new RuntimeException("Provider id 'custom' is reserved for provider=custom with tileurl");
            }
            if (!is_array($provider)) {
                throw new RuntimeException("Provider '$id' must be an array");
            }
            $styles = $provider['styles'] ?? null;
            if (!is_array($styles) || $styles === [] || array_is_list($styles)) {
                throw new RuntimeException("Provider '$id' needs 'styles': style ids with their settings");
            }
            if (isset($provider['default_style']) && !isset($styles[$provider['default_style']])) {
                throw new RuntimeException("Provider '$id' has an unknown default_style");
            }
            foreach (['attribution_text', 'attribution_html'] as $key) {
                if (isset($provider[$key]) && !is_string($provider[$key])) {
                    throw new RuntimeException("Provider '$id' $key must be a string");
                }
            }
            foreach (array_merge([$provider], array_values($styles)) as $settings) {
                self::validateHeaders(is_array($settings) ? ($settings['headers'] ?? []) : [], "Provider '$id'");
                $policy = is_array($settings) ? ($settings['cache'] ?? 'headers') : 'headers';
                if (!in_array($policy, ['headers', 'off'], true)) {
                    throw new RuntimeException("Provider '$id' has a cache setting that isn't 'headers' or 'off'");
                }
            }
            foreach ($styles as $styleId => $style) {
                if (!is_array($style)) {
                    throw new RuntimeException("Style '$id/$styleId' must be an array");
                }
                foreach (['attribution_text', 'attribution_html'] as $key) {
                    if (isset($style[$key]) && !is_string($style[$key])) {
                        throw new RuntimeException("Style '$id/$styleId' $key must be a string");
                    }
                }
                $enabled = ($provider['enabled'] ?? true) !== false
                    && ($style['enabled'] ?? $provider['enabled'] ?? true) !== false;
                if (!$enabled) {
                    continue;
                }
                self::validateLayers($style['layers'] ?? null, "Style '$id/$styleId'");
                $bounds = $style['bounds'] ?? $provider['bounds'] ?? null;
                if ($bounds !== null && !self::validBounds($bounds)) {
                    throw new RuntimeException(
                        "Style '$id/$styleId' bounds must be [minLng, minLat, maxLng, maxLat] in range"
                    );
                }
            }
        }

        foreach ($config['overlays'] as $id => $overlay) {
            if (!is_array($overlay)) {
                throw new RuntimeException("Overlay '$id' must be an array");
            }
            if (isset($config['providers'][$id])) {
                throw new RuntimeException("Overlay '$id' has the same id as a provider");
            }
            if (($overlay['enabled'] ?? true) === false) {
                continue;
            }
            self::validateLayers($overlay['layers'] ?? null, "Overlay '$id'");
            if (isset($overlay['bounds']) && !self::validBounds($overlay['bounds'])) {
                throw new RuntimeException("Overlay '$id' bounds must be [minLng, minLat, maxLng, maxLat] in range");
            }
            $opacity = $overlay['opacity'] ?? 1;
            if (!is_int($opacity) && !is_float($opacity) || $opacity < 0 || $opacity > 1) {
                throw new RuntimeException("Overlay '$id' opacity must be a number from 0 to 1");
            }
            foreach (['attribution_text', 'attribution_html', 'name'] as $key) {
                if (isset($overlay[$key]) && !is_string($overlay[$key])) {
                    throw new RuntimeException("Overlay '$id' $key must be a string");
                }
            }
            self::validateHeaders($overlay['headers'] ?? [], "Overlay '$id'");
            if (!in_array($overlay['cache'] ?? 'headers', ['headers', 'off'], true)) {
                throw new RuntimeException("Overlay '$id' has a cache setting that isn't 'headers' or 'off'");
            }
        }

        foreach ($config['basemap_aliases'] as $old => $target) {
            if ($target === null) {
                continue;
            }
            $parts = is_string($target) ? explode('/', $target) : [];
            if (count($parts) !== 2 || !isset($config['providers'][$parts[0]]['styles'][$parts[1]])) {
                throw new RuntimeException("Basemap alias '$old' must point to an existing 'provider/style'");
            }
        }

        if (!in_array($config['access']['mode'] ?? null, Access::MODES, true)) {
            throw new RuntimeException("Setting 'access.mode' must be 'open' or 'restricted'");
        }
        if (!in_array($config['access']['credentials'] ?? null, Access::CREDENTIALS, true)) {
            throw new RuntimeException("Setting 'access.credentials' must be 'key', 'signed' or 'either'");
        }
        if (!is_string($config['access']['clients_file'] ?? null)) {
            throw new RuntimeException("Setting 'access.clients_file' must be a path");
        }
        foreach ($config['clients'] as $id => $client) {
            $hasKey = is_array($client)
                && (is_string($client['key'] ?? null) || is_string($client['key_sha256'] ?? null));
            if (!$hasKey || !preg_match('/^[a-z0-9-]{1,64}$/', (string) $id)) {
                throw new RuntimeException("Client '$id' needs an id of a-z, 0-9 and -, and a 'key' or 'key_sha256'");
            }
            if (isset($client['providers']) && !is_array($client['providers'])) {
                throw new RuntimeException("Client '$id' providers must be a list of provider ids");
            }
            if (isset($client['secret']) && (!is_string($client['secret']) || strlen($client['secret']) < 16)) {
                throw new RuntimeException("Client '$id' secret must be a string of at least 16 characters");
            }
            if (isset($client['must_sign']) && !is_bool($client['must_sign'])) {
                throw new RuntimeException("Client '$id' must_sign must be true or false");
            }
        }

        foreach (['max_age', 'fallback_max_age'] as $setting) {
            if (!is_int($config['cache'][$setting] ?? null) || $config['cache'][$setting] < 0) {
                throw new RuntimeException("Setting 'cache.$setting' must be a whole number of 0 or more");
            }
        }
        $tiles = $config['cache']['tiles'] ?? null;
        if (!is_array($tiles) || !is_bool($tiles['enabled'] ?? null) || !is_string($tiles['dir'] ?? null)) {
            throw new RuntimeException("Setting 'cache.tiles' needs 'enabled' (true or false) and 'dir' (a path)");
        }
        foreach (['max_size', 'default_ttl', 'stale_if_error'] as $setting) {
            if (!is_int($tiles[$setting] ?? null) || $tiles[$setting] < 0) {
                throw new RuntimeException("Setting 'cache.tiles.$setting' must be a whole number of 0 or more");
            }
        }

        foreach (['timeout', 'connect_timeout', 'max_bytes'] as $limit) {
            if (!is_int($config['remote'][$limit] ?? null) || $config['remote'][$limit] < 1) {
                throw new RuntimeException("Setting 'remote.$limit' must be a whole number of 1 or more");
            }
        }
        if (!is_int($config['remote']['max_redirects'] ?? null) || $config['remote']['max_redirects'] < 0) {
            throw new RuntimeException("Setting 'remote.max_redirects' must be a whole number of 0 or more");
        }

        try {
            if (Parameters::padding($config['defaults']['padding'] ?? null) === null) {
                throw new InvalidRequest('');
            }
        } catch (InvalidRequest $e) {
            throw new RuntimeException(
                "Setting 'defaults.padding' must be 1 to 4 comma-separated whole numbers 0-1000"
            );
        }
        if (!is_bool($config['errors']['strict'] ?? null)) {
            throw new RuntimeException("Setting 'errors.strict' must be true or false");
        }
        if (!is_bool($config['viewport_headers'] ?? null)) {
            throw new RuntimeException("Setting 'viewport_headers' must be true or false");
        }
        $limits = [
            'max_width', 'max_height', 'max_markers', 'max_points', 'max_overlays', 'max_body_bytes',
            'icon_urls', 'icon_bytes', 'icon_pixels',
        ];
        foreach ($limits as $limit) {
            if (!is_int($config['limits'][$limit] ?? null) || $config['limits'][$limit] < 1) {
                throw new RuntimeException("Setting 'limits.$limit' must be a whole number of 1 or more");
            }
        }

        if (!in_array($config['defaults']['format'] ?? null, Renderer::FORMATS, true)) {
            throw new RuntimeException("Setting 'defaults.format' must be one of " . implode(', ', Renderer::FORMATS));
        }

        $limit = $config['zoom']['max_limit'] ?? null;
        if ($limit !== null && (!is_int($limit) || $limit < 0)) {
            throw new RuntimeException("Setting 'zoom.max_limit' must be null or a whole number of 0 or more");
        }

        foreach ($config['removed'] as $key => $reason) {
            if (!is_string($key) || !is_string($reason)) {
                throw new RuntimeException("Setting 'removed' must map ids to reasons (strings)");
            }
        }

        foreach (['token_file', 'home_page'] as $setting) {
            if (!isset($config[$setting]) || !is_string($config[$setting])) {
                throw new RuntimeException("Configuration setting '$setting' must be a string");
            }
        }
        if (!is_file($config['home_page']) || !is_readable($config['home_page'])) {
            throw new RuntimeException("Configuration setting 'home_page' must name a readable file");
        }

        $provider = $config['defaults']['provider'] ?? null;
        $style = $config['defaults']['style'] ?? null;
        // In open mode the default map must be free to everyone; in restricted
        // mode every map needs a client anyway.
        $probe = $config['access']['mode'] === 'restricted' ? new Client('default-check') : null;
        $usable = is_string($provider) && is_string($style)
            && self::resolve($config, $provider, $style, $probe, true) !== null;
        if (!$usable) {
            throw new RuntimeException(
                "Default provider and style must be an enabled style that doesn't need a key"
            );
        }
    }
}

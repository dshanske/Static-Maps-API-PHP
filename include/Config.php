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
    ];

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
     * @param array<string, mixed> $config        The loaded configuration.
     * @param string               $provider      Provider id.
     * @param string               $style         Style id.
     * @param bool                 $authenticated Whether the request has a valid token.
     *
     * @return array<string, mixed>|null The style's settings (`name`, `layers`, the
     *         inherited keys, with `minzoom` and `maxzoom` always filled in, plus
     *         `provider` and `style` ids), or null if the provider or style is
     *         unknown, disabled, needs an API key that isn't set, or needs a
     *         token the request doesn't have.
     */
    public static function resolve(array $config, string $provider, string $style, bool $authenticated): ?array
    {
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
        // A style that needs an API key is unavailable until one is set.
        if (self::needsKey($resolved) && !(is_string($resolved['key'] ?? null) && $resolved['key'] !== '')) {
            return null;
        }
        if (($resolved['requires_auth'] ?? false) && !$authenticated) {
            return null;
        }

        $resolved['minzoom'] = (int) ($resolved['minzoom'] ?? $config['zoom']['min']);
        $resolved['maxzoom'] = (int) ($resolved['maxzoom'] ?? $config['zoom']['max']);
        $limit = $config['zoom']['max_limit'] ?? null;
        if ($limit !== null) {
            $resolved['maxzoom'] = min($resolved['maxzoom'], (int) $limit);
            $resolved['minzoom'] = min($resolved['minzoom'], $resolved['maxzoom']);
        }
        $resolved['provider'] = $provider;
        $resolved['style'] = $style;

        return $resolved;
    }

    /**
     * Whether any of a style's tile URLs has a {key} placeholder.
     *
     * @param array<string, mixed> $style Style settings with `layers`.
     *
     * @return bool True if the style needs an API key.
     */
    private static function needsKey(array $style): bool
    {
        foreach ((array) ($style['layers'] ?? []) as $layer) {
            if (is_string($layer) && str_contains($layer, '{key}')) {
                return true;
            }
        }

        return false;
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
        $sections = ['defaults', 'limits', 'cache', 'remote', 'zoom', 'providers', 'basemap_aliases', 'removed'];
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
                $layers = $style['layers'] ?? null;
                if (!is_array($layers) || !array_is_list($layers) || count($layers) < 1 || count($layers) > 2) {
                    throw new RuntimeException("Style '$id/$styleId' needs 'layers': a list of one or two tile URLs");
                }
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

        foreach (['max_age', 'fallback_max_age'] as $setting) {
            if (!is_int($config['cache'][$setting] ?? null) || $config['cache'][$setting] < 0) {
                throw new RuntimeException("Setting 'cache.$setting' must be a whole number of 0 or more");
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

        foreach (['max_width', 'max_height'] as $limit) {
            if (!is_int($config['limits'][$limit] ?? null) || $config['limits'][$limit] < 1) {
                throw new RuntimeException("Setting 'limits.$limit' must be a whole number of 1 or more");
            }
        }

        if (!in_array($config['defaults']['format'] ?? null, Renderer::FORMATS, true)) {
            throw new RuntimeException("Setting 'defaults.format' must be 'png' or 'jpg'");
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
        if (!is_string($provider) || !is_string($style) || self::resolve($config, $provider, $style, false) === null) {
            throw new RuntimeException(
                "Default provider and style must be an enabled style that doesn't require a token"
            );
        }
    }
}

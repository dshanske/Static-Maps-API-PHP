<?php

/**
 * Loading, merging and querying of the service configuration.
 *
 * @see config/defaults.php for every setting and how to override it.
 */

/**
 * Service configuration: shipped defaults merged with the per-install file.
 */
class Config
{
    /**
     * Load the default settings and merge the per-install settings over them.
     *
     * The install file is optional. Its path comes from the STATIC_MAPS_CONFIG
     * environment variable if set, otherwise `data/config.php`.
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
        if (is_file($installFile)) {
            $config = self::merge($config, self::readFile($installFile));
        }

        self::validate($config);

        return $config;
    }

    /**
     * Merge an override array over a base array.
     *
     * Arrays with named keys are merged key by key, recursively. Lists (such as
     * a basemap's `layers`) and scalar values in the override replace the base
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
     * Find the basemap to use for a requested basemap id.
     *
     * Follows `alias_of` (one level), and skips basemaps that are disabled or
     * that need a token the request does not have.
     *
     * @param array<string, mixed> $config        The loaded configuration.
     * @param string               $id            The requested basemap id.
     * @param bool                 $authenticated Whether the request has a valid token.
     *
     * @return array<string, mixed>|null The basemap settings with `minzoom` and `maxzoom`
     *                                   filled in, or null if the id can't be used.
     */
    public static function basemap(array $config, string $id, bool $authenticated): ?array
    {
        $basemaps = $config['basemaps'];
        if (!isset($basemaps[$id]) || ($basemaps[$id]['enabled'] ?? true) === false) {
            return null;
        }

        $basemap = $basemaps[$id];
        if (isset($basemap['alias_of'])) {
            $target = $basemap['alias_of'];
            if (!isset($basemaps[$target]) || ($basemaps[$target]['enabled'] ?? true) === false) {
                return null;
            }
            $basemap = $basemaps[$target];
        }

        if (($basemap['requires_auth'] ?? false) && !$authenticated) {
            return null;
        }

        $basemap['minzoom'] = (int) ($basemap['minzoom'] ?? $config['zoom']['min']);
        $basemap['maxzoom'] = (int) ($basemap['maxzoom'] ?? $config['zoom']['max']);

        return $basemap;
    }

    /**
     * Turn a tile URL template into the URL of one tile.
     *
     * @param string      $template Tile URL template with {z}, {x}, {y} and optionally {key}
     *                              (upper-case {Z}, {X}, {Y} also work).
     * @param int         $x        Tile column.
     * @param int         $y        Tile row.
     * @param int         $z        Zoom level.
     * @param string|null $key      API key for {key}, or null if the basemap has none.
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
        foreach (['defaults', 'zoom', 'basemaps'] as $section) {
            if (!isset($config[$section]) || !is_array($config[$section])) {
                throw new RuntimeException("Configuration setting '$section' must be an array");
            }
        }

        foreach ($config['basemaps'] as $id => $basemap) {
            if (!is_array($basemap)) {
                throw new RuntimeException("Basemap '$id' must be an array");
            }
            if (isset($basemap['alias_of'])) {
                $target = $config['basemaps'][$basemap['alias_of']] ?? null;
                if (!is_array($target)) {
                    throw new RuntimeException(
                        "Basemap '$id' is an alias of unknown basemap '{$basemap['alias_of']}'"
                    );
                }
                if (isset($target['alias_of'])) {
                    throw new RuntimeException(
                        "Basemap '$id' is an alias of another alias; point it at a real basemap"
                    );
                }
                continue;
            }
            if (($basemap['enabled'] ?? true) === false) {
                continue;
            }
            $layers = $basemap['layers'] ?? null;
            if (!is_array($layers) || !array_is_list($layers) || count($layers) < 1 || count($layers) > 2) {
                throw new RuntimeException("Basemap '$id' needs 'layers': a list of one or two tile URLs");
            }
        }

        $default = $config['defaults']['basemap'] ?? null;
        if (!is_string($default) || self::basemap($config, $default, false) === null) {
            throw new RuntimeException(
                "Default basemap must be an enabled basemap that doesn't require a token"
            );
        }
    }
}

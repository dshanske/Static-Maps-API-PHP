<?php

/**
 * A cache of downloaded tiles on disk.
 */

namespace StaticMaps;

/**
 * Keeps downloaded tiles on disk so maps of the same area share them (#11).
 *
 * Each tile is stored as two files under the cache directory:
 * `<aa>/<hash>/<z>/<x>/<y>.tile` (the image as downloaded) and `<y>.json`
 * (when it was stored, when it expires, and its ETag / Last-Modified for
 * conditional requests). `<hash>` is the SHA-256 of the layer's URL template
 * and API key, so the key never appears in a path and tiles fetched with
 * different keys are kept apart.
 *
 * Lifetimes follow the provider's response headers: `Cache-Control` (`s-maxage`,
 * else `max-age`; `no-store` and `private` mean "don't keep") or `Expires`, and
 * a default when the provider sends neither, as OpenStreetMap's tile policy
 * asks. An expired tile is checked again with a conditional request; if the
 * provider then fails, it may still be used for `stale-if-error` seconds.
 *
 * Files are written to a temporary name and renamed, so a reader never sees
 * half a tile. bin/prune-cache.php removes old tiles and keeps the cache under
 * its size limit.
 */
final class TileCache
{
    /**
     * @param string $dir          Directory for the cache.
     * @param int    $defaultTtl   Seconds to keep a tile when the provider sends no lifetime.
     * @param int    $staleIfError Seconds an expired tile may be used when the provider fails,
     *                             unless the provider says otherwise (`stale-if-error`).
     */
    public function __construct(private string $dir, private int $defaultTtl, private int $staleIfError)
    {
    }

    /**
     * The cache from the configuration, if it is switched on and usable.
     *
     * @param array<string, mixed> $config Configuration from Config::load() (`cache.tiles`).
     *
     * @return self|null The cache, or null if it is off or its directory can't be written
     *                   (that is logged, and maps are drawn without the cache).
     */
    public static function fromConfig(array $config): ?self
    {
        $tiles = $config['cache']['tiles'];
        if (($tiles['enabled'] ?? false) !== true) {
            return null;
        }

        $dir = rtrim((string) $tiles['dir'], '/');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            error_log("Static Maps API: tile cache directory is not writable, drawing without it: $dir");
            return null;
        }

        return new self($dir, (int) $tiles['default_ttl'], (int) $tiles['stale_if_error']);
    }

    /**
     * Look up a tile.
     *
     * @param string      $template Layer URL template.
     * @param string|null $key      The layer's API key, or null.
     * @param int         $x        Tile column.
     * @param int         $y        Tile row.
     * @param int         $z        Zoom level.
     *
     * @return array{body: string, fresh: bool, usable_on_error: bool, etag: string|null,
     *               last_modified: string|null}|null The tile and whether it is still fresh,
     *               or null if it isn't cached.
     */
    public function get(string $template, ?string $key, int $x, int $y, int $z): ?array
    {
        [$tile, $meta] = $this->paths($template, $key, $x, $y, $z);
        $info = is_file($meta) ? json_decode((string) @file_get_contents($meta), true) : null;
        $body = is_array($info) && is_file($tile) ? @file_get_contents($tile) : false;
        if (!is_array($info) || !is_string($body) || $body === '') {
            return null;
        }

        $now = time();
        $expires = (int) ($info['expires'] ?? 0);

        return [
            'body'            => $body,
            'fresh'           => $now < $expires,
            'usable_on_error' => $now < $expires + (int) ($info['stale_if_error'] ?? 0),
            'etag'            => isset($info['etag']) ? (string) $info['etag'] : null,
            'last_modified'   => isset($info['last_modified']) ? (string) $info['last_modified'] : null,
        ];
    }

    /**
     * Store a downloaded tile, unless its headers say not to.
     *
     * @param string               $template Layer URL template.
     * @param string|null          $key      The layer's API key, or null.
     * @param int                  $x        Tile column.
     * @param int                  $y        Tile row.
     * @param int                  $z        Zoom level.
     * @param string               $body     The tile as downloaded (a decodable image).
     * @param array<string, string> $headers Response headers, names lower-cased.
     *
     * @return void
     */
    public function put(string $template, ?string $key, int $x, int $y, int $z, string $body, array $headers): void
    {
        $lifetime = $this->lifetime($headers);
        if ($lifetime === null) {
            return;
        }
        [$tile, $meta] = $this->paths($template, $key, $x, $y, $z);
        if (!is_dir(dirname($tile)) && !@mkdir(dirname($tile), 0775, true) && !is_dir(dirname($tile))) {
            return;
        }

        if ($this->write($tile, $body)) {
            $this->write($meta, (string) json_encode($this->metadata($headers, $lifetime)));
        }
    }

    /**
     * Mark a cached tile as checked: the provider answered 304 Not Modified.
     *
     * @param string               $template Layer URL template.
     * @param string|null          $key      The layer's API key, or null.
     * @param int                  $x        Tile column.
     * @param int                  $y        Tile row.
     * @param int                  $z        Zoom level.
     * @param array<string, string> $headers Headers of the 304 response, names lower-cased.
     *
     * @return void
     */
    public function refresh(string $template, ?string $key, int $x, int $y, int $z, array $headers): void
    {
        [, $meta] = $this->paths($template, $key, $x, $y, $z);
        $old = is_file($meta) ? json_decode((string) @file_get_contents($meta), true) : null;
        $lifetime = $this->lifetime($headers);
        if (!is_array($old) || $lifetime === null) {
            return;
        }

        // A 304 may leave out validators; keep the ones we had.
        $headers += array_filter([
            'etag'          => $old['etag'] ?? null,
            'last-modified' => $old['last_modified'] ?? null,
        ]);
        $this->write($meta, (string) json_encode($this->metadata($headers, $lifetime)));
    }

    /**
     * How long a response may be kept, from its headers.
     *
     * @param array<string, string> $headers Response headers, names lower-cased.
     *
     * @return int|null Seconds (0 = keep, but check again before every use), or null for
     *                  "don't store" (`no-store` or `private`).
     */
    public function lifetime(array $headers): ?int
    {
        $directives = self::directives($headers['cache-control'] ?? '');
        if (isset($directives['no-store']) || isset($directives['private'])) {
            return null;
        }
        if (isset($directives['no-cache'])) {
            return 0;
        }
        foreach (['s-maxage', 'max-age'] as $name) {
            if (isset($directives[$name]) && is_numeric($directives[$name])) {
                return max(0, (int) $directives[$name]);
            }
        }
        if (isset($headers['expires'])) {
            $expires = strtotime($headers['expires']);
            $date = isset($headers['date']) ? strtotime($headers['date']) : false;

            return $expires === false ? 0 : max(0, $expires - ($date === false ? time() : $date));
        }

        return $this->defaultTtl;
    }

    /**
     * The metadata stored next to a tile.
     *
     * @param array<string, string> $headers  Response headers, names lower-cased.
     * @param int                   $lifetime Seconds the tile is fresh.
     *
     * @return array<string, mixed> Stored time, expiry, stale-if-error window and validators.
     */
    private function metadata(array $headers, int $lifetime): array
    {
        $directives = self::directives($headers['cache-control'] ?? '');
        $staleIfError = isset($directives['stale-if-error']) && is_numeric($directives['stale-if-error'])
            ? (int) $directives['stale-if-error']
            : $this->staleIfError;

        return [
            'stored'         => time(),
            'expires'        => time() + $lifetime,
            'stale_if_error' => max(0, $staleIfError),
            'etag'           => $headers['etag'] ?? null,
            'last_modified'  => $headers['last-modified'] ?? null,
        ];
    }

    /**
     * Parse a Cache-Control header.
     *
     * @param string $header The header value, e.g. `public, max-age=3600`.
     *
     * @return array<string, string> Directive names (lower-cased) => values ('' when none).
     */
    private static function directives(string $header): array
    {
        $directives = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if ($pair[0] !== '') {
                $directives[strtolower($pair[0])] = trim($pair[1] ?? '', " \"");
            }
        }

        return $directives;
    }

    /**
     * The tile and metadata file paths for a tile.
     *
     * @param string      $template Layer URL template.
     * @param string|null $key      The layer's API key, or null.
     * @param int         $x        Tile column.
     * @param int         $y        Tile row.
     * @param int         $z        Zoom level.
     *
     * @return array{string, string} Tile file and metadata file.
     */
    private function paths(string $template, ?string $key, int $x, int $y, int $z): array
    {
        $hash = hash('sha256', $template . "\0" . ($key ?? ''));
        $base = "$this->dir/" . substr($hash, 0, 2) . "/$hash/$z/$x/$y";

        return ["$base.tile", "$base.json"];
    }

    /**
     * Write a file atomically: to a temporary name in the same directory, then rename.
     *
     * @param string $file     Destination path.
     * @param string $contents File contents.
     *
     * @return bool True on success.
     */
    private function write(string $file, string $contents): bool
    {
        $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temp, $contents) === false) {
            return false;
        }
        if (!@rename($temp, $file)) {
            @unlink($temp);
            return false;
        }

        return true;
    }
}

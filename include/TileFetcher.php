<?php

/**
 * Downloading of map tiles.
 */

namespace StaticMaps;

use GdImage;

/**
 * Downloads blocks of tiles for any number of layers (a map's own layers and
 * overlays, #30), each with its own zoom, area, key and headers, all in
 * parallel with curl_multi.
 *
 * Uses the tile cache when one is given (#11). A tile that fails to download
 * or decode, with no usable cached copy, is replaced by a gray tile.
 */
final class TileFetcher
{
    /**
     * @param RemoteFetcher  $remote Makes the downloads (limits, User-Agent).
     * @param TileCache|null $cache  Tiles kept on disk, or null to download every tile.
     */
    public function __construct(private RemoteFetcher $remote, private ?TileCache $cache = null)
    {
    }

    /**
     * Get the tiles of each layer, from its `sw` to its `ne` tile (inclusive).
     *
     * Fresh tiles come from the cache without a request; expired ones are
     * checked again with a conditional request (a 304 keeps the cached copy);
     * the rest are downloaded and, if they are images, stored. If a download
     * fails, a cached copy still within its stale-if-error window is used,
     * else a gray tile (or a transparent one, for layers with `blank` =>
     * 'transparent'). Columns past the world's edge wrap round (#35).
     *
     * Each layer has `template` (URL template), `key` (API key for {key}), `headers`
     * (request header lines; tile requests with headers don't follow redirects),
     * `zoom`, `sw` and `ne` (top-left and bottom-right tiles), `cache` (whether the
     * tile cache may be used, default true) and `blank` (`gray` or `transparent`
     * for failed tiles, default gray).
     *
     * @param list<array<string, mixed>> $layers The layers, drawn in this order.
     * @param GdImage                    $im     The map image; the gray fallback colour is allocated on it.
     *
     * @return list<array<int, array<int, GdImage>>> For each layer, tiles indexed by column, then row.
     */
    public function fetchLayers(array $layers, GdImage $im): array
    {
        $result = [];

        /** @var list<array{layer: int, template: string, key: string|null, z: int, cache: bool, x: int,
         *       tx: int, y: int, cached: array<string, mixed>|null, handle: \CurlHandle|null,
         *       headers: array<string, string>, body: string|null}> $jobs */
        $jobs = [];
        $mh = curl_multi_init();
        foreach ($layers as $index => $layer) {
            $result[$index] = [];
            $template = $layer['template'];
            $key = $layer['key'] ?? null;
            $headers = $layer['headers'] ?? [];
            $z = (int) $layer['zoom'];
            $cache = ($layer['cache'] ?? true) ? $this->cache : null;
            for ($x = (int) $layer['sw']['x']; $x <= $layer['ne']['x']; $x++) {
                $result[$index][$x] ??= [];
                for ($y = (int) $layer['sw']['y']; $y <= $layer['ne']['y']; $y++) {
                    // Columns past the world's edge (maps across the antimeridian) wrap round (#35).
                    $tx = (($x % 2 ** $z) + 2 ** $z) % 2 ** $z;
                    $cached = $cache?->get($template, $key, $tx, $y, $z);
                    $job = ['layer' => $index, 'template' => $template, 'key' => $key, 'z' => $z,
                        'cache' => $cache !== null, 'x' => $x, 'tx' => $tx, 'y' => $y,
                        'cached' => $cached, 'handle' => null, 'headers' => [], 'body' => null];
                    if ($cached !== null && $cached['fresh']) {
                        $job['body'] = $cached['body'];
                        $jobs[] = $job;
                        continue;
                    }
                    $jobs[] = $job;
                    $i = count($jobs) - 1;
                    $jobs[$i]['handle'] = $this->remote->handle(
                        Config::tileUrl($template, $tx, $y, $z, $key),
                        array_merge($headers, self::conditionalHeaders($cached)),
                        static function (string $line) use (&$jobs, $i): void {
                            // A new status line (e.g. after a redirect) starts a new set of headers.
                            if (str_starts_with($line, 'HTTP/')) {
                                $jobs[$i]['headers'] = [];
                                return;
                            }
                            $parts = explode(':', $line, 2);
                            if (count($parts) === 2) {
                                $jobs[$i]['headers'][strtolower(trim($parts[0]))] = trim($parts[1]);
                            }
                        },
                        // A provider's headers may hold its key: never send them to another server.
                        $headers === []
                    );
                    curl_multi_add_handle($mh, $jobs[$i]['handle']);
                }
            }
        }

        $running = null;
        // Execute the handles. Blocks until all are finished.
        do {
            curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running > 0);

        // In case any of the tiles fail, they will be grey instead of throwing an error
        $blank = imagecreatetruecolor(256, 256);
        $grey = imagecolorallocate($im, 224, 224, 224);
        imagefill($blank, 0, 0, $grey);

        $clear = imagecreatetruecolor(256, 256);
        imagealphablending($clear, false);
        imagesavealpha($clear, true);
        imagefill($clear, 0, 0, (int) imagecolorallocatealpha($clear, 0, 0, 0, 127));

        foreach ($jobs as $job) {
            $image = $this->resolve($job);
            $fallback = ($layers[$job['layer']]['blank'] ?? 'gray') === 'transparent' ? $clear : $blank;
            $result[$job['layer']][$job['x']][$job['y']] = $image === false ? $fallback : $image;
        }

        return $result;
    }

    /**
     * The image for one tile job, updating the cache on the way.
     *
     * @param array<string, mixed> $job One tile job from fetchLayers(): layer, template, key, z, cache
     *                                  (whether the cache is used), x (where drawn), tx (x wrapped
     *                                  round the world, as fetched), y, the cached copy (or null),
     *                                  the curl handle (null if fresh from the cache), response
     *                                  headers and the cached body.
     *
     * @return GdImage|false The tile, or false if there is none to draw.
     */
    private function resolve(array $job): GdImage|false
    {
        [$key, $z] = [$job['key'], $job['z']];
        $cache = $job['cache'] ? $this->cache : null;
        if ($job['handle'] === null) {
            // Fresh from the cache.
            return @imagecreatefromstring((string) $job['body']);
        }

        $ch = $job['handle'];
        $content = (string) curl_multi_getcontent($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $cached = $job['cached'];
        [$template, $x, $y] = [$job['template'], $job['tx'], $job['y']];

        if ($status === 304 && $cached !== null) {
            $cache?->refresh($template, $key, $x, $y, $z, $job['headers']);
            return @imagecreatefromstring((string) $cached['body']);
        }

        $image = $content === '' ? false : @imagecreatefromstring($content);
        if ($image !== false && $status >= 200 && $status <= 299) {
            $cache?->put($template, $key, $x, $y, $z, $content, $job['headers']);
            return $image;
        }
        // The download failed (or wasn't an image): a recent enough cached copy beats a gray tile.
        if ($cached !== null && $cached['usable_on_error']) {
            return @imagecreatefromstring((string) $cached['body']);
        }

        // As before: whatever the provider sent if it is an image (some send a
        // "no tile" image with an error status), else nothing.
        return $image;
    }

    /**
     * Conditional request headers for re-checking a cached tile.
     *
     * @param array<string, mixed>|null $cached The cached tile, or null.
     *
     * @return list<string> If-None-Match / If-Modified-Since lines.
     */
    private static function conditionalHeaders(?array $cached): array
    {
        $headers = [];
        if ($cached !== null && is_string($cached['etag'] ?? null) && $cached['etag'] !== '') {
            $headers[] = 'If-None-Match: ' . $cached['etag'];
        }
        if ($cached !== null && is_string($cached['last_modified'] ?? null) && $cached['last_modified'] !== '') {
            $headers[] = 'If-Modified-Since: ' . $cached['last_modified'];
        }

        return $headers;
    }
}

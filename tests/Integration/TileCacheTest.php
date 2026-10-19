<?php

/**
 * The tile cache on disk (#11).
 */

namespace StaticMaps\Tests\Integration;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use StaticMaps\Tests\Support\RenderTestCase;

/**
 * Renders with tests/fixtures/config-tile-cache.php, whose cache lives in a
 * temporary directory, against the fake tile server's cache-test layers.
 */
final class TileCacheTest extends RenderTestCase
{
    /** @var string The cache directory for this test class. */
    private static string $cacheDir = '';

    /** @var string While this file exists, the tile server's `flaky` layer fails. */
    private static string $failFile = '';

    /**
     * Fresh temporary paths for the cache and the failure switch.
     *
     * @return void
     */
    private static function paths(): void
    {
        if (self::$cacheDir === '') {
            self::$cacheDir = sys_get_temp_dir() . '/static-maps-tile-cache-' . bin2hex(random_bytes(4));
            self::$failFile = self::$cacheDir . '.fail';
        }
    }

    /**
     * The tile server needs the failure switch.
     *
     * @return array<string, string> Environment variables.
     */
    protected static function tileServerEnvironment(): array
    {
        self::paths();

        return ['TILE_FAIL_FILE' => self::$failFile];
    }

    /**
     * Use the configuration with the tile cache on.
     *
     * @param string $tileServerUrl Base URL of the fake tile server.
     *
     * @return array<string, string> Environment variables.
     */
    protected static function appEnvironment(string $tileServerUrl): array
    {
        self::paths();
        $cache = [
            'STATIC_MAPS_CONFIG' => dirname(__DIR__) . '/fixtures/config-tile-cache.php',
            'TILE_CACHE_DIR'     => self::$cacheDir,
        ];

        return $cache + parent::appEnvironment($tileServerUrl);
    }

    /**
     * Remove the cache directory.
     *
     * @return void
     */
    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        if (is_dir(self::$cacheDir)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::$cacheDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir(self::$cacheDir);
        }
        @unlink(self::$failFile);
    }

    /**
     * Render one style at a zoom no other test uses, twice.
     *
     * @param string $style Style of the `cachetest` (or other) provider.
     * @param int    $zoom  Zoom level, distinct per test so their tiles don't mix.
     * @param string $provider Provider id.
     *
     * @return array{string, string, list<array<string, mixed>>} First and second image, and
     *         the tile requests made by the second render.
     */
    private function renderTwice(string $style, int $zoom, string $provider = 'cachetest'): array
    {
        $query = ['latitude' => 45.5, 'longitude' => -122.6, 'zoom' => $zoom] + compact('provider', 'style');
        $first = $this->render($query)->body;
        $this->setUp();
        $second = $this->render($query)->body;

        return [$first, $second, $this->tileRequests()];
    }

    /**
     * Cached files under the cache directory.
     *
     * @return list<string> Paths relative to the cache directory.
     */
    private static function cachedFiles(): array
    {
        if (!is_dir(self::$cacheDir)) {
            return [];
        }
        $paths = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::$cacheDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            $paths[] = substr($file->getPathname(), strlen(self::$cacheDir) + 1);
        }

        return $paths;
    }

    /**
     * A fresh cached tile is drawn without asking the provider again.
     *
     * @return void
     */
    public function testFreshTilesComeFromTheCache(): void
    {
        [$first, $second, $requests] = $this->renderTwice('fresh', 10);

        $this->assertSame($first, $second);
        $this->assertSame([], $requests);
    }

    /**
     * Tiles from a provider that sends no cache headers are kept for the default lifetime.
     *
     * @return void
     */
    public function testTilesWithoutHeadersUseTheDefaultLifetime(): void
    {
        [$first, $second, $requests] = $this->renderTwice('noheaders', 11);

        $this->assertSame($first, $second);
        $this->assertSame([], $requests);
    }

    /**
     * An expired tile is checked with If-None-Match; a 304 keeps the cached copy.
     *
     * @return void
     */
    public function testExpiredTilesAreRevalidated(): void
    {
        [$first, $second, $requests] = $this->renderTwice('revalidate', 12);

        $this->assertSame($first, $second);
        $this->assertNotSame([], $requests);
        foreach ($requests as $request) {
            $this->assertMatchesRegularExpression('/^"12-\d+-\d+"$/', (string) $request['inm'], $request['path']);
        }
    }

    /**
     * no-store responses are not kept.
     *
     * @return void
     */
    public function testNoStoreIsNotCached(): void
    {
        [$first, $second, $requests] = $this->renderTwice('nostore', 13);

        $this->assertSame($first, $second);
        $this->assertNotSame([], $requests);
        foreach ($requests as $request) {
            $this->assertNull($request['inm']);
        }
    }

    /**
     * When the provider fails, a cached tile within its stale-if-error window is drawn instead of gray.
     *
     * @return void
     */
    public function testStaleTilesStandInForFailures(): void
    {
        $query = ['latitude' => 45.5, 'longitude' => -122.6, 'zoom' => 14];
        $query += ['provider' => 'cachetest', 'style' => 'flaky'];
        $good = $this->render($query)->body;

        touch(self::$failFile);
        try {
            $this->setUp();
            $failing = $this->render($query);
        } finally {
            unlink(self::$failFile);
        }

        $this->assertNotSame([], $this->tileRequests(), 'the provider was asked');
        $this->assertSame($good, $failing->body, 'the cached tiles were drawn, not gray ones');
    }

    /**
     * Providers with cache => 'off' never use the tile cache.
     *
     * @return void
     */
    public function testProvidersWithCacheOffAreNotCached(): void
    {
        $before = self::cachedFiles();
        [, , $requests] = $this->renderTwice('base', 15, 'cacheoff');

        $this->assertNotSame([], $requests, 'downloaded again');
        $this->assertSame($before, self::cachedFiles(), 'nothing stored');
    }

    /**
     * Cache paths never contain the API key, and each tile has a metadata file.
     *
     * @return void
     */
    public function testCacheLayout(): void
    {
        $this->renderTwice('fresh', 16);
        $files = self::cachedFiles();

        $this->assertNotSame([], $files);
        foreach ($files as $file) {
            $this->assertStringNotContainsString('secret', $file);
            $this->assertMatchesRegularExpression('#^[0-9a-f]{2}/[0-9a-f]{64}/\d+/\d+/\d+\.(tile|json)$#', $file);
        }
        $this->assertSame(
            count(array_filter($files, static fn (string $f): bool => str_ends_with($f, '.tile'))),
            count(array_filter($files, static fn (string $f): bool => str_ends_with($f, '.json')))
        );
    }
}

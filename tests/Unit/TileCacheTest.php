<?php

/**
 * Tests for include/TileCache.php.
 */

namespace StaticMaps\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StaticMaps\TileCache;

/**
 * Lifetimes from headers, storing, refreshing and the unusable-directory case.
 */
final class TileCacheTest extends TestCase
{
    /** @var string An HTTP date. */
    private const NEW_YEAR = 'Thu, 01 Jan 2026 00:00:00 GMT';

    /** @var string Temporary cache directory. */
    private string $dir;

    /**
     * Make a temporary directory.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/static-maps-unit-cache-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    /**
     * Remove the temporary directory.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /**
     * Response headers and the lifetime they give.
     *
     * @return array<string, array{array<string, string>, int|null}> Headers and expected seconds (null = don't store).
     */
    public static function lifetimeCases(): array
    {
        return [
            'max-age'               => [['cache-control' => 'public, max-age=3600'], 3600],
            's-maxage wins'         => [['cache-control' => 'max-age=60, s-maxage=600'], 600],
            'OSM style'             => [['cache-control' => 'max-age=86908, stale-while-revalidate=604800'], 86908],
            'immutable CARTO'       => [['cache-control' => 'public, max-age=2592000, immutable'], 2592000],
            'no-cache'              => [['cache-control' => 'no-cache'], 0],
            'no-store'              => [['cache-control' => 'no-store'], null],
            'private'               => [['cache-control' => 'private, max-age=60'], null],
            'Expires minus Date'    => [['expires' => 'Thu, 01 Jan 2026 01:00:00 GMT', 'date' => self::NEW_YEAR], 3600],
            'Expires in the past'   => [['expires' => self::NEW_YEAR, 'date' => 'Thu, 01 Jan 2026 01:00:00 GMT'], 0],
            'nothing: default'      => [[], 604800],
        ];
    }

    /**
     * lifetime() reads Cache-Control, then Expires, then uses the default.
     *
     * @param array<string, string> $headers  Response headers, names lower-cased.
     * @param int|null              $expected Expected lifetime.
     *
     * @return void
     */
    #[DataProvider('lifetimeCases')]
    public function testLifetime(array $headers, ?int $expected): void
    {
        $this->assertSame($expected, (new TileCache($this->dir, 604800, 0))->lifetime($headers));
    }

    /**
     * A stored tile comes back with its validators; a 304 refresh keeps them and makes it fresh.
     *
     * @return void
     */
    public function testPutGetRefresh(): void
    {
        $cache = new TileCache($this->dir, 604800, 100);
        $this->assertNull($cache->get('t/{z}/{x}/{y}', 'k', 1, 2, 3));

        $cache->put('t/{z}/{x}/{y}', 'k', 1, 2, 3, 'image bytes', ['cache-control' => 'max-age=0', 'etag' => '"a"']);
        $tile = $cache->get('t/{z}/{x}/{y}', 'k', 1, 2, 3);
        $this->assertSame('image bytes', $tile['body'] ?? null);
        $this->assertFalse($tile['fresh']);
        $this->assertTrue($tile['usable_on_error'], 'within the 100s stale-if-error window');
        $this->assertSame('"a"', $tile['etag']);
        $this->assertNull($cache->get('t/{z}/{x}/{y}', 'other key', 1, 2, 3), 'keys are kept apart');

        $cache->refresh('t/{z}/{x}/{y}', 'k', 1, 2, 3, ['cache-control' => 'max-age=600']);
        $refreshed = $cache->get('t/{z}/{x}/{y}', 'k', 1, 2, 3);
        $this->assertTrue($refreshed['fresh'] ?? false);
        $this->assertSame('"a"', $refreshed['etag']);
    }

    /**
     * A provider's stale-if-error replaces the configured window.
     *
     * @return void
     */
    public function testProviderStaleIfError(): void
    {
        $cache = new TileCache($this->dir, 604800, 100);
        $cache->put('t', null, 0, 0, 0, 'x', ['cache-control' => 'max-age=0, stale-if-error=0']);

        $this->assertFalse($cache->get('t', null, 0, 0, 0)['usable_on_error'] ?? true);
    }

    /**
     * no-store responses aren't written.
     *
     * @return void
     */
    public function testNoStoreIsNotWritten(): void
    {
        $cache = new TileCache($this->dir, 604800, 0);
        $cache->put('t', null, 0, 0, 0, 'x', ['cache-control' => 'no-store']);

        $this->assertNull($cache->get('t', null, 0, 0, 0));
        $this->assertSame(['.', '..'], scandir($this->dir));
    }

    /**
     * fromConfig(): off gives null; an unusable directory gives null and logs why.
     *
     * @return void
     */
    public function testFromConfig(): void
    {
        $settings = [
            'enabled'        => true,
            'dir'            => $this->dir . '/new',
            'max_size'       => 0,
            'default_ttl'    => 60,
            'stale_if_error' => 0,
        ];
        $this->assertInstanceOf(TileCache::class, TileCache::fromConfig(['cache' => ['tiles' => $settings]]));
        $this->assertDirectoryExists($this->dir . '/new', 'created on first use');

        $this->assertNull(TileCache::fromConfig(['cache' => ['tiles' => ['enabled' => false] + $settings]]));

        $log = $this->dir . '/php-errors.log';
        $previous = ini_set('error_log', $log);
        try {
            $file = $this->dir . '/a-file';
            touch($file);
            $this->assertNull(TileCache::fromConfig(['cache' => ['tiles' => ['dir' => $file . '/tiles'] + $settings]]));
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $this->assertStringContainsString('tile cache directory is not writable', (string) file_get_contents($log));
    }
}

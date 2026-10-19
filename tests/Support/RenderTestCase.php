<?php

/**
 * Base class for tests that render maps through the real entry point.
 */

namespace StaticMaps\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Runs the renderer (`public/index.php`) under `php -S` against the fake tile server.
 *
 * One tile server and one renderer are started per test class. The renderer
 * loads tests/fixtures/config.php (via STATIC_MAPS_CONFIG), so every basemap
 * points at the tile server and no request leaves the machine.
 *
 * Every render fails the test if PHP logged an error, warning, notice or
 * deprecation that is not listed in knownErrors().
 */
abstract class RenderTestCase extends TestCase
{
    use ImageAssertions;

    /** @var PhpServer|null Serves tests/fixtures/tile-server.php. */
    protected static ?PhpServer $tiles = null;

    /** @var PhpServer|null Serves public/ with the test configuration. */
    protected static ?PhpServer $app = null;

    /** @var string File the tile server logs requests to, one JSON object per line. */
    protected static string $tileLog = '';

    /**
     * Start the tile server and the renderer.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        self::$tileLog = (string) tempnam(sys_get_temp_dir(), 'static-maps-tiles-');

        self::$tiles = new PhpServer(
            $root . '/tests/fixtures',
            $root . '/tests/fixtures/tile-server.php',
            ['TILE_LOG' => self::$tileLog] + static::tileServerEnvironment()
        );
        self::$app = new PhpServer($root . '/public', null, static::appEnvironment(self::$tiles->url));
    }

    /**
     * Stop both servers.
     *
     * @return void
     */
    public static function tearDownAfterClass(): void
    {
        self::$app?->stop();
        self::$tiles?->stop();
        self::$app = self::$tiles = null;
        if (is_file(self::$tileLog)) {
            unlink(self::$tileLog);
        }
    }

    /**
     * Extra environment for the fake tile server process.
     *
     * @return array<string, string> Environment variables.
     */
    protected static function tileServerEnvironment(): array
    {
        return [];
    }

    /**
     * Environment for the renderer process.
     *
     * @param string $tileServerUrl Base URL of the fake tile server.
     *
     * @return array<string, string> Environment variables.
     */
    protected static function appEnvironment(string $tileServerUrl): array
    {
        return [
            'STATIC_MAPS_CONFIG' => dirname(__DIR__) . '/fixtures/config.php',
            'TILE_SERVER_URL'    => $tileServerUrl,
        ];
    }

    /**
     * Forget tile requests made by earlier tests.
     *
     * @return void
     */
    protected function setUp(): void
    {
        file_put_contents(self::$tileLog, '');
        self::$app?->errors();
    }

    /**
     * Render a map and check that PHP logged nothing unexpected.
     *
     * @param array<string, mixed> $query Request parameters (arrays become `name[]=...`).
     * @param string               $path  Path to request: `/` (the renderer), `/index.php`
     *                                    or the deprecated `/img.php`.
     *
     * @return Response The renderer's response.
     */
    protected function render(array $query, string $path = '/'): Response
    {
        $qs = preg_replace('/%5B\d+%5D/', '%5B%5D', http_build_query($query));
        $response = self::$app->get($path . '?' . $qs);

        $unexpected = array_values(array_filter(
            self::$app->errors(),
            static function (string $line): bool {
                foreach (static::knownErrors() as $pattern) {
                    if (preg_match($pattern, $line)) {
                        return false;
                    }
                }
                return true;
            }
        ));
        $this->assertSame([], $unexpected, 'PHP logged unexpected errors while rendering');

        return $response;
    }

    /**
     * Problems the renderer is known to log today, as regular expressions.
     *
     * Each one is tracked in an issue; remove its pattern when it is fixed,
     * so that it can't come back unnoticed.
     *
     * @return list<string> Patterns matched against each logged line.
     */
    protected static function knownErrors(): array
    {
        return [];
    }

    /**
     * Tile requests the tile server received since the test started.
     *
     * @return list<array{path: string, query: string, ua: string|null}> One entry per request.
     */
    protected function tileRequests(): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents(self::$tileLog)));

        return array_values(array_map(
            static fn (string $l): array => json_decode($l, true),
            $lines
        ));
    }

    /**
     * Paths of the tile requests since the test started.
     *
     * @return list<string> Paths such as `/base/14/2613/5853.png`, sorted.
     */
    protected function tilePaths(): array
    {
        $paths = array_column($this->tileRequests(), 'path');
        sort($paths);

        return $paths;
    }

    /**
     * The colour the fake tile server fills a base tile with.
     *
     * Same formula as in tests/fixtures/tile-server.php.
     *
     * @param int $x Tile column.
     * @param int $y Tile row.
     *
     * @return array{int, int, int} Red, green and blue, 0-255.
     */
    protected static function tileColor(int $x, int $y): array
    {
        return [($x * 37) % 200 + 40, ($y * 53) % 200 + 40, (($x + $y) * 11) % 200 + 40];
    }
}

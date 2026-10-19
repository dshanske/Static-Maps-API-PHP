<?php

/**
 * Tests for include/TileCachePruner.php and bin/prune-cache.php.
 */

namespace StaticMaps\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StaticMaps\TileCachePruner;

/**
 * Removing expired and surplus tiles.
 */
final class TileCachePrunerTest extends TestCase
{
    /** @var string Temporary cache directory. */
    private string $dir;

    /**
     * Make a temporary directory.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/static-maps-prune-' . bin2hex(random_bytes(4));
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
     * Write one cached tile.
     *
     * @param string $name       Path below the cache directory, without extension.
     * @param int    $bytes      Size of the tile file.
     * @param int    $stored     When it was stored (Unix time).
     * @param int    $usableFor  Seconds from now until it can no longer be used (negative = past).
     *
     * @return void
     */
    private function tile(string $name, int $bytes, int $stored, int $usableFor): void
    {
        $base = "$this->dir/$name";
        @mkdir(dirname($base), 0777, true);
        file_put_contents("$base.tile", str_repeat('x', $bytes));
        file_put_contents("$base.json", json_encode([
            'stored' => $stored, 'expires' => time() + $usableFor, 'stale_if_error' => 0,
        ]));
    }

    /**
     * Expired tiles, leftover temporary files and unpaired files go; usable tiles stay.
     *
     * @return void
     */
    public function testRemovesWhatCanNoLongerBeUsed(): void
    {
        $this->tile('aa/one/1/1/1', 100, time(), 3600);
        $this->tile('aa/two/1/1/1', 100, time(), -10);
        file_put_contents("$this->dir/aa/one/1/1/2.tile", 'lonely');
        file_put_contents("$this->dir/aa/one/1/1/old.tmp", 'tmp');
        touch("$this->dir/aa/one/1/1/old.tmp", time() - 7200);
        file_put_contents("$this->dir/aa/one/1/1/new.tmp", 'tmp');

        $report = (new TileCachePruner($this->dir, 0))->prune();

        $this->assertSame(1, $report['expired']);
        $this->assertSame(2, $report['other'], 'unpaired tile and old temporary file');
        $this->assertSame(1, $report['tiles']);
        $this->assertFileExists("$this->dir/aa/one/1/1/1.tile");
        $this->assertFileExists("$this->dir/aa/one/1/1/new.tmp", 'a write may still be in progress');
        $this->assertDirectoryDoesNotExist("$this->dir/aa/two", 'empty directories removed');
    }

    /**
     * Over the size limit, the tiles stored longest ago go first, down to 90% of the limit.
     *
     * @return void
     */
    public function testSizeLimitRemovesOldestFirst(): void
    {
        foreach (range(1, 10) as $i) {
            $this->tile("bb/h/1/1/$i", 1000, time() - 1000 * (11 - $i), 3600);
        }
        $metaSize = (int) filesize("$this->dir/bb/h/1/1/1.json");
        $limit = 6 * (1000 + $metaSize);

        $report = (new TileCachePruner($this->dir, $limit))->prune();

        $this->assertSame(5, $report['oversize'], 'down to 90% of the limit: 5 left');
        $this->assertLessThanOrEqual($limit * 0.9, $report['size']);
        foreach (range(1, 5) as $i) {
            $this->assertFileDoesNotExist("$this->dir/bb/h/1/1/$i.tile", "oldest $i removed");
        }
        foreach (range(6, 10) as $i) {
            $this->assertFileExists("$this->dir/bb/h/1/1/$i.tile", "newer $i kept");
        }
    }

    /**
     * A dry run counts but removes nothing.
     *
     * @return void
     */
    public function testDryRun(): void
    {
        $this->tile('cc/h/1/1/1', 100, time(), -10);

        $report = (new TileCachePruner($this->dir, 0))->prune(true);

        $this->assertSame(1, $report['expired']);
        $this->assertFileExists("$this->dir/cc/h/1/1/1.tile");
    }

    /**
     * The command-line script prunes the configured cache and reports what it did, and
     * exits with 1 on a configuration error.
     *
     * @return void
     */
    public function testScript(): void
    {
        $this->tile('dd/h/1/1/1', 100, time(), -10);
        $config = "$this->dir/config.php";
        file_put_contents($config, '<?php return ' . var_export([
            'cache' => ['tiles' => ['dir' => "$this->dir", 'max_size' => 0]],
        ], true) . ';');

        $script = escapeshellarg(dirname(__DIR__, 2) . '/bin/prune-cache.php');
        $php = escapeshellarg(PHP_BINARY);
        exec('STATIC_MAPS_CONFIG=' . escapeshellarg($config) . " $php $script 2>&1", $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertStringContainsString('removed 1 tiles (1 expired, 0 for size)', implode("\n", $output));
        $this->assertFileDoesNotExist("$this->dir/dd/h/1/1/1.tile");

        $broken = escapeshellarg(dirname(__DIR__) . '/fixtures/config-broken.php');
        exec("STATIC_MAPS_CONFIG=$broken " . escapeshellarg(PHP_BINARY) . " $script --quiet 2>&1", $error, $failed);
        $this->assertSame(1, $failed, 'a configuration error exits with 1');
        $this->assertStringContainsString('prune-cache: configuration error', implode("\n", $error));
    }
}

<?php

/**
 * Removing old tiles from the tile cache.
 */

namespace StaticMaps;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Keeps the tile cache tidy and under its size limit (used by bin/prune-cache.php).
 *
 * See TileCache for the file layout: `<y>.tile` with `<y>.json` metadata.
 */
final class TileCachePruner
{
    /** @var int Temporary files older than this many seconds are left over from failed writes. */
    private const TEMP_AGE = 3600;

    /** @var float Prune down to this share of the size limit. */
    private const TARGET = 0.9;

    /**
     * @param string $dir     The cache directory.
     * @param int    $maxSize Size limit in bytes; 0 for none.
     */
    public function __construct(private string $dir, private int $maxSize)
    {
    }

    /**
     * Clean up the cache.
     *
     * @param bool $dryRun Only count what would be removed.
     *
     * @return array{expired: int, oversize: int, other: int, freed: int, size: int, tiles: int}
     *         Tiles removed because they expired or for the size limit, other files removed,
     *         bytes freed, bytes and tiles left.
     */
    public function prune(bool $dryRun = false): array
    {
        $report = ['expired' => 0, 'oversize' => 0, 'other' => 0, 'freed' => 0, 'size' => 0, 'tiles' => 0];
        if (!is_dir($this->dir)) {
            return $report;
        }

        $now = time();
        $entries = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $path = $file->getPathname();
            if ($file->isDir()) {
                continue;
            }
            if (str_ends_with($path, '.tmp')) {
                if ($now - $file->getMTime() > self::TEMP_AGE) {
                    $report['other']++;
                    $report['freed'] += $this->remove($path, $dryRun);
                }
                continue;
            }
            if (!str_ends_with($path, '.tile') && !str_ends_with($path, '.json')) {
                continue;
            }
            $base = substr($path, 0, -5);
            $entries[$base][substr($path, -4)] = $file->getSize();
        }

        $keep = [];
        foreach ($entries as $base => $parts) {
            if (!isset($parts['tile'], $parts['json'])) {
                // One half of a pair: unusable.
                foreach ($parts as $ext => $size) {
                    $report['other']++;
                    $report['freed'] += $this->remove("$base.$ext", $dryRun);
                }
                continue;
            }
            $meta = json_decode((string) @file_get_contents("$base.json"), true);
            $usableUntil = is_array($meta) ? (int) ($meta['expires'] ?? 0) + (int) ($meta['stale_if_error'] ?? 0) : 0;
            if ($usableUntil < $now) {
                $report['expired']++;
                $report['freed'] += $this->remove("$base.tile", $dryRun) + $this->remove("$base.json", $dryRun);
                continue;
            }
            $keep[$base] = ['size' => $parts['tile'] + $parts['json'], 'stored' => (int) ($meta['stored'] ?? 0)];
        }

        $size = array_sum(array_column($keep, 'size'));
        if ($this->maxSize > 0 && $size > $this->maxSize) {
            uasort($keep, static fn (array $a, array $b): int => $a['stored'] <=> $b['stored']);
            foreach ($keep as $base => $entry) {
                if ($size <= $this->maxSize * self::TARGET) {
                    break;
                }
                $report['oversize']++;
                $report['freed'] += $this->remove("$base.tile", $dryRun) + $this->remove("$base.json", $dryRun);
                $size -= $entry['size'];
                unset($keep[$base]);
            }
        }

        if (!$dryRun) {
            $this->removeEmptyDirectories();
        }
        $report['size'] = $size;
        $report['tiles'] = count($keep);

        return $report;
    }

    /**
     * A byte count for people, e.g. `1.2 MB`.
     *
     * @param int $bytes Bytes.
     *
     * @return string The size with a unit.
     */
    public static function bytes(int $bytes): string
    {
        $units = ['bytes', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1000 && $unit < count($units) - 1) {
            $value /= 1000;
            $unit++;
        }

        return $unit === 0 ? "$bytes bytes" : sprintf('%.1f %s', $value, $units[$unit]);
    }

    /**
     * Remove one file.
     *
     * @param string $path   The file.
     * @param bool   $dryRun Only report its size.
     *
     * @return int Bytes freed (or that would be).
     */
    private function remove(string $path, bool $dryRun): int
    {
        $size = (int) @filesize($path);
        if (!$dryRun) {
            @unlink($path);
        }

        return $size;
    }

    /**
     * Remove directories left empty under the cache directory (not the directory itself).
     *
     * @return void
     */
    private function removeEmptyDirectories(): void
    {
        $dirs = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($dirs as $dir) {
            if ($dir->isDir()) {
                @rmdir($dir->getPathname());
            }
        }
    }
}

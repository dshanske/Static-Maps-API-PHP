<?php

/**
 * Clean up the tile cache: run from cron or a systemd timer (see README, "Tile cache").
 *
 * Usage: php bin/prune-cache.php [--dry-run] [--quiet]
 *
 * 1. Removes tiles that expired longer ago than their stale-if-error window
 *    (they can't be used any more), half-written temporary files older than an
 *    hour, and tile or metadata files that lost their partner.
 * 2. If the cache is still larger than `cache.tiles.max_size`, removes the
 *    tiles stored longest ago until it is below 90% of the limit, so it isn't
 *    pruned again on the next run straight away.
 * 3. Removes empty directories.
 *
 * Uses the same configuration as the service (STATIC_MAPS_CONFIG or
 * data/config.php). Exit status: 0 on success, 1 on a configuration error.
 */

use StaticMaps\Config;
use StaticMaps\TileCachePruner;

// Only from the command line; bin/ is not web-served, but be sure.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../include/autoload.php';

$arguments = (array) ($_SERVER['argv'] ?? []);
$dryRun = in_array('--dry-run', $arguments, true);
$quiet = in_array('--quiet', $arguments, true);

try {
    $config = Config::load();
} catch (RuntimeException $e) {
    fwrite(STDERR, 'prune-cache: configuration error: ' . $e->getMessage() . "\n");
    exit(1);
}

$tiles = $config['cache']['tiles'];
$report = (new TileCachePruner((string) $tiles['dir'], (int) $tiles['max_size']))->prune($dryRun);

if (!$quiet) {
    printf(
        "%s%s: removed %d tiles (%d expired, %d for size) and %d other files, freed %s; "
            . "%s in %d tiles remain (limit %s)\n",
        $dryRun ? '[dry run] ' : '',
        realpath((string) $tiles['dir']) ?: $tiles['dir'],
        $report['expired'] + $report['oversize'],
        $report['expired'],
        $report['oversize'],
        $report['other'],
        TileCachePruner::bytes($report['freed']),
        TileCachePruner::bytes($report['size']),
        $report['tiles'],
        $tiles['max_size'] > 0 ? TileCachePruner::bytes((int) $tiles['max_size']) : 'none'
    );
}

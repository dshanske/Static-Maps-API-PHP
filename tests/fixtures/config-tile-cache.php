<?php

/**
 * Test configuration with the tile cache on (TileCacheTest).
 *
 * Same as tests/fixtures/config.php, with the cache in TILE_CACHE_DIR and a
 * provider whose styles use the cache-test layers of the fake tile server.
 */

$tiles = (string) getenv('TILE_SERVER_URL');

$config = include __DIR__ . '/config.php';
$config['cache']['tiles'] = [
    'enabled'        => true,
    'dir'            => (string) getenv('TILE_CACHE_DIR'),
    'max_size'       => 0,
    'default_ttl'    => 604800,
    'stale_if_error' => 604800,
];
$config['providers']['cachetest'] = [
    'name'   => 'Tile cache tests',
    'key'    => 'secret key/1',
    'styles' => [
        'fresh'      => [
            'name'   => 'Fresh for an hour',
            'layers' => [$tiles . '/cached/{z}/{x}/{y}.png?max_age=3600&k={key}'],
        ],
        'revalidate' => ['name' => 'Expires at once', 'layers' => [$tiles . '/cached/{z}/{x}/{y}.png?max_age=0']],
        'nostore'    => ['name' => 'No store', 'layers' => [$tiles . '/nostore/{z}/{x}/{y}.png']],
        'flaky'      => ['name' => 'Fails on demand', 'layers' => [$tiles . '/flaky/{z}/{x}/{y}.png?max_age=0']],
        'noheaders'  => ['name' => 'No cache headers', 'layers' => [$tiles . '/base/{z}/{x}/{y}.png']],
    ],
];
$config['providers']['cacheoff'] = [
    'name'   => 'Cache off',
    'cache'  => 'off',
    'styles' => ['base' => ['name' => 'Base', 'layers' => [$tiles . '/cached/{z}/{x}/{y}.png?max_age=3600']]],
];

return $config;

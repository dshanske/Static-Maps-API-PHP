<?php

/**
 * Test configuration written in the deprecated pre-#40 format.
 *
 * Uses `basemaps` and `defaults.basemap` instead of `providers` and
 * `defaults.provider` / `defaults.style`, to check that such install files
 * keep working (and are reported as deprecated).
 */

$tiles = (string) getenv('TILE_SERVER_URL');

$config = include __DIR__ . '/config.php';
unset($config['defaults']['provider'], $config['defaults']['style']);
$config['defaults']['basemap'] = 'legacy-overlay';
$config['basemaps'] = [
    'legacy-overlay' => [
        'name'   => 'Legacy basemap',
        'layers' => [$tiles . '/base/{z}/{x}/{y}.png', $tiles . '/overlay/{z}/{x}/{y}.png'],
    ],
    'legacy-alias' => ['alias_of' => 'fixture'],
    'fixture-zoom' => ['maxzoom' => 8],
];

return $config;

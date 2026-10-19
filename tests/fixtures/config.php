<?php

/**
 * Install configuration used by the integration tests.
 *
 * Loaded by the renderer through the STATIC_MAPS_CONFIG environment variable.
 * Switches off every shipped basemap, so a test can never reach a real tile
 * host, and adds basemaps that point at the fake tile server
 * (tests/fixtures/tile-server.php), whose base URL comes from TILE_SERVER_URL.
 * The only valid access token is `test-token` (tests/fixtures/tokens.txt).
 */

$tiles = (string) getenv('TILE_SERVER_URL');

$basemaps = [];
foreach (array_keys((include __DIR__ . '/../../config/defaults.php')['basemaps']) as $id) {
    $basemaps[$id] = ['enabled' => false];
}

return [
    'defaults' => [
        'basemap' => 'fixture',
    ],
    'user_agent' => 'Static Maps API tests',
    'token_file' => __DIR__ . '/tokens.txt',
    'basemaps' => $basemaps + [
        'fixture' => [
            'name'   => 'Fixture base',
            'layers' => [$tiles . '/base/{z}/{x}/{y}.png'],
        ],
        'fixture-overlay' => [
            'name'   => 'Fixture base with overlay',
            'layers' => [$tiles . '/base/{z}/{x}/{y}.png', $tiles . '/overlay/{z}/{x}/{y}.png'],
        ],
        'fixture-broken' => [
            'name'   => 'Fixture whose tiles always fail',
            'layers' => [$tiles . '/broken/{z}/{x}/{y}.png'],
        ],
        'fixture-esri' => [
            'name'        => 'Fixture with Esri attribution',
            'layers'      => [$tiles . '/base/{z}/{x}/{y}.png'],
            'attribution' => 'esri',
        ],
        'fixture-keyed' => [
            'name'   => 'Fixture with an API key',
            'layers' => [$tiles . '/base/{z}/{x}/{y}.png?key={key}'],
            'key'    => 'a b&c',
        ],
        'fixture-zoom' => [
            'name'    => 'Fixture limited to zoom 5-10',
            'layers'  => [$tiles . '/base/{Z}/{X}/{Y}.png'],
            'minzoom' => 5,
            'maxzoom' => 10,
        ],
        'fixture-auth' => [
            'name'          => 'Fixture that needs a token',
            'layers'        => [$tiles . '/overlay/{z}/{x}/{y}.png'],
            'requires_auth' => true,
        ],
        'fixture-off' => [
            'name'    => 'Disabled fixture',
            'layers'  => [$tiles . '/overlay/{z}/{x}/{y}.png'],
            'enabled' => false,
        ],
        'fixture-alias' => [
            'alias_of' => 'fixture-overlay',
        ],
    ],
];

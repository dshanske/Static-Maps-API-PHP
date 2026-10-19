<?php

/**
 * Install configuration used by the integration tests.
 *
 * Loaded by the renderer through the STATIC_MAPS_CONFIG environment variable.
 * Switches off every shipped provider, so a test can never reach a real tile
 * host, and adds providers that point at the fake tile server
 * (tests/fixtures/tile-server.php), whose base URL comes from TILE_SERVER_URL.
 * The only valid access token is `test-token` (tests/fixtures/tokens.txt).
 *
 * The deprecated `basemap=` ids used by the older tests (`fixture`,
 * `fixture-overlay`, ...) are kept as aliases of the fixture styles.
 */

$tiles = (string) getenv('TILE_SERVER_URL');

$providers = [];
foreach (array_keys((include __DIR__ . '/../../config/defaults.php')['providers']) as $id) {
    $providers[$id] = ['enabled' => false];
}

return [
    'defaults' => [
        'provider' => 'fixture',
        'style'    => 'base',
    ],
    'user_agent' => 'Static Maps API tests',
    'token_file' => __DIR__ . '/tokens.txt',
    'providers' => $providers + [
        'fixture' => [
            'name'          => 'Fixture tiles',
            'default_style' => 'base',
            'styles' => [
                'base' => [
                    'name'   => 'Base',
                    'layers' => [$tiles . '/base/{z}/{x}/{y}.png'],
                ],
                'overlay' => [
                    'name'   => 'Base with overlay',
                    'layers' => [$tiles . '/base/{z}/{x}/{y}.png', $tiles . '/overlay/{z}/{x}/{y}.png'],
                ],
                'broken' => [
                    'name'   => 'Tiles that always fail',
                    'layers' => [$tiles . '/broken/{z}/{x}/{y}.png'],
                ],
                'esri' => [
                    'name'        => 'With Esri attribution',
                    'layers'      => [$tiles . '/base/{z}/{x}/{y}.png'],
                    'attribution' => 'esri',
                ],
                'credited' => [
                    'name'             => 'With credit text',
                    'layers'           => [$tiles . '/base/{z}/{x}/{y}.png'],
                    'attribution'      => 'text',
                    'attribution_text' => '© Fixture tiles contributors',
                ],
                'credited-logo' => [
                    'name'             => 'With a logo and long credit text',
                    'layers'           => [$tiles . '/base/{z}/{x}/{y}.png'],
                    'attribution'      => 'esri',
                    'attribution_text' => 'Sources: Fixture, Another Source, Yet Another Source, More Data Ltd, '
                        . 'Some Agency, A Community, and many other contributors around the world',
                    'attribution_html' => 'Powered by <a href="https://fixture.example/">Fixture</a> &amp; friends',
                ],
                'zoom' => [
                    'name'    => 'Limited to zoom 5-10',
                    'layers'  => [$tiles . '/base/{Z}/{X}/{Y}.png'],
                    'minzoom' => 5,
                    'maxzoom' => 10,
                ],
                'auth' => [
                    'name'          => 'Needs a token',
                    'layers'        => [$tiles . '/overlay/{z}/{x}/{y}.png'],
                    'requires_auth' => true,
                ],
                'off' => [
                    'name'    => 'Disabled',
                    'layers'  => [$tiles . '/overlay/{z}/{x}/{y}.png'],
                    'enabled' => false,
                ],
            ],
        ],
        // Provider-level settings that its styles inherit.
        'keyed' => [
            'name'          => 'Fixture with an API key and Esri attribution',
            'key'           => 'a b&c',
            'attribution'   => 'esri',
            'maxzoom'       => 12,
            'default_style' => 'main',
            'styles' => [
                'main' => [
                    'name'   => 'Main',
                    'layers' => [$tiles . '/base/{z}/{x}/{y}.png?key={key}'],
                ],
                'overridden' => [
                    'name'        => 'Overrides the provider',
                    'layers'      => [$tiles . '/overlay/{z}/{x}/{y}.png?key={key}'],
                    'key'         => 'own',
                    'attribution' => 'osm',
                    'maxzoom'     => 14,
                ],
            ],
        ],
        'secret' => [
            'name'          => 'Token-only provider',
            'requires_auth' => true,
            'styles' => [
                'only' => [
                    'name'   => 'Only style',
                    'layers' => [$tiles . '/overlay/{z}/{x}/{y}.png'],
                ],
            ],
        ],
        // Needs an API key that isn't set, like CARTO without one.
        'needs-key' => [
            'name'   => 'Keyed provider without a key',
            'styles' => [
                'base' => ['name' => 'Base', 'layers' => [$tiles . '/overlay/{z}/{x}/{y}.png?key={key}']],
            ],
        ],
        'switched-off' => [
            'name'    => 'Disabled provider',
            'enabled' => false,
            'styles'  => [
                'base' => ['name' => 'Base', 'layers' => [$tiles . '/overlay/{z}/{x}/{y}.png']],
            ],
        ],
    ],
    // Ids "removed" for the fallback-notice tests (#52), on top of the shipped ones.
    'removed' => [
        'retired'                 => 'Test provider that was shut down',
        'fixture/retired'         => 'Test style that was shut down',
        'basemap:fixture-retired' => 'Test basemap id that was shut down',
    ],
    'basemap_aliases' => [
        'fixture'         => 'fixture/base',
        'fixture-overlay' => 'fixture/overlay',
        'fixture-alias'   => 'fixture/overlay',
        'fixture-broken'  => 'fixture/broken',
        'fixture-esri'    => 'fixture/esri',
        'fixture-keyed'   => 'keyed/main',
        'fixture-zoom'    => 'fixture/zoom',
        'fixture-auth'    => 'fixture/auth',
        'fixture-off'     => 'fixture/off',
    ],
];

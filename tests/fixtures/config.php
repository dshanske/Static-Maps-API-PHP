<?php

/**
 * Install configuration used by the integration tests.
 *
 * Loaded by the renderer through the STATIC_MAPS_CONFIG environment variable.
 * Switches off every shipped provider, so a test can never reach a real tile
 * host, and adds providers that point at the fake tile server
 * (tests/fixtures/tile-server.php), whose base URL comes from TILE_SERVER_URL.
 * The only client key is `test-token` (the `test` client).
 *
 * The deprecated `basemap=` ids used by the older tests (`fixture`,
 * `fixture-overlay`, ...) are kept as aliases of the fixture styles.
 */

$tiles = (string) getenv('TILE_SERVER_URL');

$shipped = include __DIR__ . '/../../config/defaults.php';
$providers = [];
foreach (array_keys($shipped['providers']) as $id) {
    $providers[$id] = ['enabled' => false];
}
// Shipped overlays too: tests never reach a real tile server.
$overlays = [];
foreach (array_keys($shipped['overlays']) as $id) {
    $overlays[$id] = ['enabled' => false];
}

return [
    'defaults' => [
        'provider' => 'fixture',
        'style'    => 'base',
    ],
    'user_agent' => 'Static Maps API tests',
    // The tile cache is off here: most tests count tile requests. TileCacheTest turns it on.
    'cache'      => [
        'tiles' => ['enabled' => false, 'dir' => (string) (getenv('TILE_CACHE_DIR') ?: sys_get_temp_dir())],
    ],
    // Small limits so the download-limit tests run quickly.
    'remote'     => ['timeout' => 2, 'connect_timeout' => 2, 'max_bytes' => 200000, 'max_redirects' => 2],
    // A small JSON body limit for GeoJsonTest (#38).
    'limits'     => ['max_body_bytes' => 20000],
    // The only client: key `test-token`. (tokens.txt is for LegacyTokenTest.)
    'token_file' => __DIR__ . '/no-such-tokens.txt',
    'access'     => ['clients_file' => __DIR__ . '/no-such-clients.json'],
    'clients'    => [
        'test'    => ['key' => 'test-token', 'secret' => 'test-signing-secret'],
        // Only signed URLs.
        'signer'  => ['key' => 'signer-key', 'secret' => 'signer-signing-secret', 'must_sign' => true],
        // Limited: only the `keyed` provider among those needing a key, no external URLs.
        'limited' => ['key' => 'limited-key', 'providers' => ['keyed'], 'external_urls' => false],
        'off'     => ['key' => 'disabled-key', 'enabled' => false],
    ],
    // Overlays for OverlayTest (#30).
    'overlays' => $overlays + [
        // Transparent tiles with a white square in the middle of each.
        'squares' => [
            'name'             => 'Squares',
            'layers'           => [$tiles . '/overlay/{z}/{x}/{y}.png'],
            'attribution_text' => 'Squares Inc.',
            'attribution_html' => '<a href="https://squares.example/">Squares</a>',
        ],
        'faint'   => ['name' => 'Faint squares', 'layers' => [$tiles . '/overlay/{z}/{x}/{y}.png'], 'opacity' => 0.5],
        'close'   => ['layers' => [$tiles . '/overlay/{z}/{x}/{y}.png'], 'minzoom' => 12],
        'tokyo'   => ['layers' => [$tiles . '/overlay/{z}/{x}/{y}.png'], 'bounds' => [139.0, 35.0, 140.0, 36.0]],
        'missing' => ['layers' => [$tiles . '/broken/{z}/{x}/{y}.png']],
        'private' => ['layers' => [$tiles . '/overlay/{z}/{x}/{y}.png'], 'requires_auth' => true],
        'retired' => ['layers' => [$tiles . '/overlay/{z}/{x}/{y}.png'], 'enabled' => false],
    ],
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
                // Covers only the Portland area (#30).
                'regional' => [
                    'name'   => 'Portland only',
                    'layers' => [$tiles . '/base/{z}/{x}/{y}.png'],
                    'bounds' => [-123.0, 45.0, -122.0, 46.0],
                ],
                // The same, with the Esri logo: its fallback's logo differs (#95).
                'regional-logo' => [
                    'name'        => 'Portland only, Esri logo',
                    'layers'      => [$tiles . '/base/{z}/{x}/{y}.png'],
                    'bounds'      => [-123.0, 45.0, -122.0, 46.0],
                    'attribution' => 'esri',
                ],
                // Offers 512 px @2x tiles for scale=2 (#10).
                'retina' => [
                    'name'   => 'With @2x tiles',
                    'layers' => [$tiles . '/base/{z}/{x}/{y}{r}.png'],
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
        // The key in a request header instead of the URL (#28).
        'headered' => [
            'name'          => 'Fixture with its key in a header',
            'key'           => 'fixture-header-key',
            'headers'       => ['X-Api-Key' => '{key}', 'X-Client' => 'static-maps-tests'],
            'default_style' => 'main',
            'styles' => [
                'main'   => ['name' => 'Main', 'layers' => [$tiles . '/header/{z}/{x}/{y}.png']],
                // Redirects; a request carrying the key must not follow it.
                'bounce' => ['name' => 'Bounce', 'layers' => [$tiles . '/bounce/{z}/{x}/{y}.png']],
                // Fixed headers only: no key needed.
                'plain'  => [
                    'name'    => 'Plain',
                    'headers' => ['X-Client' => 'static-maps-tests'],
                    'layers'  => [$tiles . '/base/{z}/{x}/{y}.png'],
                ],
            ],
        ],
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
        // Terms forbid caching proxies (like Thunderforest): its images are private.
        'no-proxy' => [
            'name'   => 'Provider with cache off',
            'cache'  => 'off',
            'styles' => ['base' => ['name' => 'Base', 'layers' => [$tiles . '/base/{z}/{x}/{y}.png']]],
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

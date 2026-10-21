<?php

/**
 * Per-install settings for the Static Maps API.
 *
 * HOW TO USE
 *
 * 1. Copy this file to `data/config.php` (in this same folder). That file is
 *    ignored by git, so your settings and API keys stay on your server and
 *    survive updates.
 * 2. Uncomment and change only the settings you need. Anything you leave out
 *    keeps its default from `config/defaults.php`, which documents every
 *    setting in detail. Don't edit `config/defaults.php` itself.
 * 3. If you use PHP-FPM with opcache and `opcache.validate_timestamps=0`,
 *    reload PHP-FPM after changing this file.
 *
 * HOW SETTINGS ARE COMBINED
 *
 * Arrays with named keys are merged key by key with the defaults, so
 * `'defaults' => ['width' => 600]` changes the default width and keeps every
 * other value in `defaults`. Lists, such as a style's `layers`, replace the
 * default list completely.
 *
 * To use a file in another location, set the STATIC_MAPS_CONFIG environment
 * variable to its full path.
 *
 * If this file has a mistake (for example it doesn't return an array, or the
 * default provider or style doesn't exist), every request returns HTTP 500 and the
 * reason is written to the PHP error log.
 */

return [

    // Values used when a request doesn't specify them.
    // 'defaults' => [
    //     'width'        => 300,       // pixels
    //     'height'       => 300,       // pixels
    //     'path_color'   => '333333',  // hex RGB, no '#'
    //     'path_weight'  => 3,         // pixels
    //     'padding'      => 20,        // px around fitted markers and paths; or '40,20' (CSS order)
    //     'format'       => 'png',     // 'png', 'png8', 'jpg' or 'webp'
    //     'jpeg_quality' => 75,        // 0-100
    //     'provider'     => 'osm',     // id of an enabled provider
    //     'style'        => 'streets', // one of that provider's styles
    //     'attribution'  => 'osm',     // 'osm', 'esri' or 'mapbox'
    // ],

    // Largest image a request may ask for; bigger sizes are drawn at the limit.
    // 'limits' => [
    //     'max_width'  => 1280,   // pixels
    //     'max_height' => 1280,   // pixels
    //     'max_markers' => 100,   // markers drawn per map
    //     'max_points'  => 10000, // points in all paths together
    //     'icon_urls'   => 5,      // different marker icon URLs per map
    //     'icon_bytes'  => 262144, // largest icon download
    //     'icon_pixels' => 256,    // icons from URLs are drawn at most this big
    //     'max_overlays'   => 3,       // overlay= layers per map
    //     'max_body_bytes' => 1048576, // largest JSON request body
    // ],

    // Answer 400 for a marker or path that can't be drawn, instead of leaving
    // it out with an X-Marker-<n> / X-Path-<n> header.
    // 'errors' => ['strict' => true],

    // How long browsers and caches may keep map images.
    // 'cache' => [
    //     'max_age'          => 604800,   // seconds (7 days)
    //     'fallback_max_age' => 300,      // seconds, when the default map is drawn instead
    //     // Downloaded tiles kept on disk (on by default); see bin/prune-cache.php.
    //     'tiles' => [
    //         'enabled'        => true,
    //         'dir'            => '/var/cache/static-maps/tiles',
    //         'max_size'       => 1000000000,   // bytes, enforced by bin/prune-cache.php
    //         'default_ttl'    => 604800,       // seconds, when a provider sends no lifetime
    //         'stale_if_error' => 604800,       // seconds a stale tile may stand in for a failed one
    //     ],
    // ],

    // Limits on downloads from other servers (tiles, icon and attribution URLs).
    // 'remote' => [
    //     'timeout'         => 10,        // seconds
    //     'connect_timeout' => 5,         // seconds
    //     'max_bytes'       => 5000000,   // bytes
    //     'max_redirects'   => 3,
    // ],

    // Zoom range for providers and styles that don't set their own minzoom/maxzoom,
    // and an optional cap that no map goes above (not set by default).
    // 'zoom' => [
    //     'min'       => 2,
    //     'max'       => 18,
    //     'max_limit' => 17,
    // ],

    // Identify your install to tile providers (OpenStreetMap requires this).
    // 'user_agent' => 'Static Maps API/{version} (maps.example.com; admin@example.com)',

    // Who may draw which maps: 'open' (free maps for everyone; maps using your
    // provider keys need a client key) or 'restricted' (every map needs one).
    // Issue client keys with: php bin/clients.php add <id>
    // 'access' => [
    //     'mode'         => 'open',
    //     'credentials'  => 'either',   // 'key', 'signed' (signed URLs only) or 'either'
    //     'clients_file' => __DIR__ . '/clients.json',
    // ],

    // Deprecated: the old token file. Move it with: php bin/clients.php import
    // 'token_file' => __DIR__ . '/apikeys.txt',

    // Your own HTML page for requests without parameters (default: assets/home.html),
    // or the shipped demo page, which lets visitors try the API (#92).
    // 'home_page' => __DIR__ . '/home.html',
    // 'home_page' => __DIR__ . '/../assets/demo.html',

    // Change, add or switch off providers and styles. Only list what you change.
    // Settings on a provider apply to all its styles; a style can override them.
    // These are separate examples: merge entries for the same provider into one.
    // 'providers' => [
    //
    //     // Switch off a whole provider, or a single style.
    //     'carto'  => ['enabled' => false],
    //     'esri'   => ['styles' => ['natgeo' => ['enabled' => false]]],
    //
    //     // Turn on a provider that needs an API key (its styles are unavailable without one).
    //     'carto' => ['key' => 'your-carto-key'],
    //
    //     // Narrow the zoom range of a provider.
    //     'opentopomap' => ['maxzoom' => 17],
    //
    //     // Use a different default attribution for a provider.
    //     'esri' => ['attribution' => 'esri'],
    //
    //     // Only allow a style for requests with a client key.
    //     'esri' => ['styles' => ['satellite' => ['requires_auth' => true]]],
    //
    //     // Add a provider (check its terms first; see "Provider terms" in the README).
    //     // {key} in its URLs or request headers is replaced with 'key'.
    //     'example' => [
    //         'name'          => 'Example Tiles',
    //         'key'           => 'your-api-key',
    //         'headers'       => ['Authorization' => 'Bearer {key}'],   // or ?api_key={key} in the URL
    //         'default_style' => 'streets',
    //         'styles'        => [
    //             'streets' => [
    //                 'name'   => 'Streets',
    //                 'layers' => ['https://tiles.example.com/streets/{z}/{x}/{y}.png'],
    //             ],
    //         ],
    //     ],
    // ],

    // Overlays drawn on top of any map with overlay=<id> (see config/defaults.php).
    // 'overlays' => [
    //     'railways' => [
    //         'name'             => 'OpenRailwayMap',
    //         'layers'           => ['https://tiles.openrailwaymap.org/standard/{z}/{x}/{y}.png'],
    //         'opacity'          => 0.8,
    //         'attribution_text' => 'Style: CC-BY-SA 2.0 OpenRailwayMap',
    //     ],
    // ],

    // Maps your install took out of service: requests for them still get the
    // default map, and the X-Map-Fallback header says "removed".
    // 'removed' => ['my-provider' => 'Our tile server was shut down (2027-01)'],

    // Deprecated: point an old `basemap=` id at another provider/style.
    // 'basemap_aliases' => ['stamen-toner-lite' => 'carto/light'],
    // (The Stamen ids were removed; an alias like this one brings one back.)
];

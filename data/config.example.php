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
    //     'format'       => 'png',     // 'png' or 'jpg'
    //     'jpeg_quality' => 75,        // 0-100
    //     'provider'     => 'osm',     // id of an enabled provider
    //     'style'        => 'streets', // one of that provider's styles
    //     'attribution'  => 'osm',     // 'osm', 'esri' or 'mapbox'
    // ],

    // Largest image a request may ask for; bigger sizes are drawn at the limit.
    // 'limits' => [
    //     'max_width'  => 1280,   // pixels
    //     'max_height' => 1280,   // pixels
    // ],

    // How long browsers and caches may keep map images.
    // 'cache' => [
    //     'max_age'          => 604800,   // seconds (7 days)
    //     'fallback_max_age' => 300,      // seconds, when the default map is drawn instead
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
    // 'user_agent' => 'Static Maps API/ maps.example.com (admin@example.com)',

    // File with access tokens, one per line (default: data/apikeys.txt).
    // 'token_file' => '/etc/static-maps/tokens.txt',

    // Your own HTML page for requests without parameters (default: assets/home.html).
    // 'home_page' => __DIR__ . '/home.html',

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
    //     // Only allow a style for requests with a valid token.
    //     'esri' => ['styles' => ['satellite' => ['requires_auth' => true]]],
    //
    //     // Add a provider. {key} in its URLs is replaced with 'key'.
    //     'stadia' => [
    //         'name'          => 'Stadia Maps',
    //         'key'           => 'your-stadia-api-key',
    //         'default_style' => 'toner',
    //         'styles'        => [
    //             'toner' => [
    //                 'name'   => 'Stamen Toner',
    //                 'layers' => ['https://tiles.stadiamaps.com/tiles/stamen_toner/{z}/{x}/{y}.png?api_key={key}'],
    //             ],
    //         ],
    //     ],
    // ],

    // Maps your install took out of service: requests for them still get the
    // default map, and the X-Map-Fallback header says "removed".
    // 'removed' => ['my-provider' => 'Our tile server was shut down (2027-01)'],

    // Deprecated: point an old `basemap=` id at another provider/style.
    // 'basemap_aliases' => ['stamen-toner-lite' => 'carto/light'],
    // (The Stamen ids were removed; an alias like this one brings one back.)
];

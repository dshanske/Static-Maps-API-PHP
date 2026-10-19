<?php

/**
 * Entry point: renders a static map image for the request parameters.
 *
 * All the work happens in StaticMaps\Renderer; this file loads the
 * configuration and sends the result. See README.md for the parameters.
 * (public/img.php is the deprecated old name for this file.)
 */

require __DIR__ . '/../include/autoload.php';

use StaticMaps\Config;
use StaticMaps\Renderer;
use StaticMaps\Request;
use StaticMaps\Tokens;

try {
    $config = Config::load();
} catch (RuntimeException $e) {
    error_log('Static Maps API: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'Server configuration error';
    exit;
}

(new Renderer($config, new Tokens($config['token_file'])))
    ->render(Request::fromGlobals())
    ->send();

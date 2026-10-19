<?php

/**
 * Entry point: renders a static map image for the request parameters.
 *
 * All the work happens in StaticMaps\Renderer; this file loads the
 * configuration and sends the result. See README.md for the parameters.
 * (public/img.php is the deprecated old name for this file.)
 */

require_once __DIR__ . '/../include/autoload.php';

use StaticMaps\Config;
use StaticMaps\Deprecation;
use StaticMaps\HomePage;
use StaticMaps\Renderer;
use StaticMaps\Request;
use StaticMaps\Response;
use StaticMaps\Tokens;

// Requests that came in through the deprecated img.php say so on every response.
$entryHeaders = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'img.php' ? Deprecation::headers() : [];

try {
    $config = Config::load();
} catch (RuntimeException $e) {
    error_log('Static Maps API: ' . $e->getMessage());
    Response::error(500, 'Server configuration error')->withHeaders($entryHeaders)->send();
    exit;
}

foreach ($config['deprecations'] as $message) {
    error_log('Static Maps API: ' . $message);
}

$request = Request::fromGlobals();

// No parameters at all (someone opened the address in a browser): say what this is.
if ($request->isEmpty()) {
    try {
        $response = HomePage::response($config['home_page']);
    } catch (RuntimeException $e) {
        error_log('Static Maps API: ' . $e->getMessage());
        Response::error(500, 'Server configuration error')->withHeaders($entryHeaders)->send();
        exit;
    }
} else {
    $response = (new Renderer($config, new Tokens($config['token_file'])))->render($request);
}

$response->withHeaders($entryHeaders)->send();

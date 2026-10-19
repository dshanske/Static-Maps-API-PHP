<?php

/**
 * PHPUnit bootstrap: loads Composer's autoloader and the application's classes.
 *
 * The application has no autoloader of its own (public/img.php includes its
 * files directly), so the files under include/ are loaded here.
 */

require __DIR__ . '/../vendor/autoload.php';

// Set by public/img.php in production; WebMercator depends on it.
if (!defined('TILE_SIZE')) {
    define('TILE_SIZE', 256);
}

require_once __DIR__ . '/../include/WebMercator.php';
require_once __DIR__ . '/../include/Polyline.php';
require_once __DIR__ . '/../include/helpers.php';
require_once __DIR__ . '/../include/Config.php';

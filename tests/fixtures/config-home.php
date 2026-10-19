<?php

/**
 * Test configuration with an install-specific home page.
 *
 * Same as tests/fixtures/config.php, plus `home_page` pointing at
 * tests/fixtures/home-custom.html.
 */

$config = include __DIR__ . '/config.php';
$config['home_page'] = __DIR__ . '/home-custom.html';

return $config;

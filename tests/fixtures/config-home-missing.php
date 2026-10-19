<?php

/**
 * Test configuration whose home page file does not exist.
 *
 * Used to check that this is reported as a configuration error (HTTP 500).
 */

$config = include __DIR__ . '/config.php';
$config['home_page'] = __DIR__ . '/no-such-home.html';

return $config;

<?php

/**
 * Test configuration with errors.strict and small overlay limits (StrictErrorsTest).
 */

$config = include __DIR__ . '/config.php';
$config['errors']['strict'] = true;
$config['limits']['max_markers'] = 3;
$config['limits']['max_points'] = 20;
$config['limits']['icon_urls'] = 2;
$config['limits']['icon_pixels'] = 64;

return $config;

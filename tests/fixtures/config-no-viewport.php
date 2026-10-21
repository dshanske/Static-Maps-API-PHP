<?php

/**
 * Test configuration with the viewport headers switched off (ViewportHeadersOffTest, #101).
 */

$config = include __DIR__ . '/config.php';
$config['viewport_headers'] = false;

return $config;

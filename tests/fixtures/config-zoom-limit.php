<?php

/**
 * Test configuration with a global zoom cap: tests/fixtures/config.php plus
 * `zoom.max_limit` 11.
 */

$config = include __DIR__ . '/config.php';
$config['zoom']['max_limit'] = 11;

return $config;

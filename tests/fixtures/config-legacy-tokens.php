<?php

/**
 * Test configuration that still uses the deprecated token file (tests/fixtures/tokens.txt).
 */

$config = include __DIR__ . '/config.php';
$config['token_file'] = __DIR__ . '/tokens.txt';

return $config;

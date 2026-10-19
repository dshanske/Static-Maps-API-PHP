<?php

/**
 * Test configuration in restricted mode: every map needs a client key (AccessTest).
 */

$config = include __DIR__ . '/config.php';
$config['access']['mode'] = 'restricted';

return $config;

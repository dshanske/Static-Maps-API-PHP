<?php

/**
 * PHPUnit bootstrap: loads Composer's autoloader, which maps the application's
 * `StaticMaps\` classes to include/ and the tests' `StaticMaps\Tests\` classes
 * to tests/.
 */

// No application classes are loaded by hand: autoloading covers them all.
require __DIR__ . '/../vendor/autoload.php';

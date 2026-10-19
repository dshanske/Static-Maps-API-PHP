<?php

/**
 * Autoloader for the application's classes.
 *
 * Maps `StaticMaps\Foo` to `include/Foo.php` (PSR-4). The service has no
 * runtime Composer dependencies, so it registers this itself; Composer's
 * autoloader does the same for the tests.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'StaticMaps\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

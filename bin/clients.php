<?php

/**
 * Issue and manage client keys (#28). Run `php bin/clients.php help`.
 *
 * Writes the clients file (`access.clients_file`, default data/clients.json),
 * so run it as the user PHP runs as, or make sure PHP can read the file.
 * Uses the same configuration as the service (STATIC_MAPS_CONFIG or data/config.php).
 */

use StaticMaps\ClientsCommand;
use StaticMaps\Config;

// Only from the command line; bin/ is not web-served, but be sure.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../include/autoload.php';

try {
    $config = Config::load();
} catch (RuntimeException $e) {
    fwrite(STDERR, 'clients: configuration error: ' . $e->getMessage() . "\n");
    exit(1);
}

exit((new ClientsCommand($config))->run(array_slice(array_map('strval', (array) ($_SERVER['argv'] ?? [])), 1)));

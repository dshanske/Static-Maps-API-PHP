<?php

/**
 * The clients an install has issued keys to.
 */

namespace StaticMaps;

use RuntimeException;

/**
 * Finds the client a key belongs to (#28).
 *
 * Clients come from three places, merged in this order (later ids win):
 *
 * 1. the deprecated token file (`token_file`, default data/apikeys.txt): each
 *    line is a client with every permission, named `token-<n>`;
 * 2. the clients file (`access.clients_file`, default data/clients.json),
 *    managed with bin/clients.php;
 * 3. the `clients` setting in the configuration.
 *
 * Keys are stored as SHA-256 hashes (a plain `key` is also accepted in the
 * configuration) and compared in constant time.
 */
final class Clients
{
    /** @var string Prefix of keys made by generateKey(). */
    public const KEY_PREFIX = 'sm_';

    /**
     * @param array<string, array<string, mixed>> $clients    Client id => settings with `key_sha256`,
     *                                                        and optionally `external_urls` and `providers`.
     * @param int                                 $legacy     How many clients came from the token file.
     */
    public function __construct(private array $clients, public readonly int $legacy = 0)
    {
    }

    /**
     * Load the clients of an install.
     *
     * @param array<string, mixed> $config Configuration from Config::load().
     *
     * @return self The clients.
     *
     * @throws RuntimeException If the clients file exists but can't be read.
     */
    public static function fromConfig(array $config): self
    {
        $clients = [];

        $legacy = 0;
        $tokenFile = (string) ($config['token_file'] ?? '');
        if ($tokenFile !== '' && is_file($tokenFile)) {
            foreach ((array) file($tokenFile, FILE_IGNORE_NEW_LINES) as $line) {
                $token = trim((string) $line);
                if ($token !== '') {
                    $legacy++;
                    $clients["token-$legacy"] = ['key_sha256' => self::hashKey($token)];
                }
            }
        }

        $file = (string) $config['access']['clients_file'];
        $clients = array_merge($clients, self::readFile($file));

        foreach ((array) ($config['clients'] ?? []) as $id => $settings) {
            if (isset($settings['key']) && !isset($settings['key_sha256'])) {
                $settings['key_sha256'] = self::hashKey((string) $settings['key']);
            }
            unset($settings['key']);
            $clients[(string) $id] = $settings;
        }

        return new self($clients, $legacy);
    }

    /**
     * The client a key belongs to.
     *
     * Every stored hash is compared, so the time taken doesn't reveal which (if any) matched.
     *
     * @param mixed $key The `key` request parameter (or the deprecated `token`).
     *
     * @return Client|null The client, or null for no, an empty or an unknown key.
     */
    public function find(mixed $key): ?Client
    {
        if (!is_string($key) || $key === '') {
            return null;
        }

        $hash = self::hashKey($key);
        $found = null;
        foreach ($this->clients as $id => $settings) {
            $matches = hash_equals((string) ($settings['key_sha256'] ?? ''), $hash);
            if ($matches && ($settings['enabled'] ?? true) !== false) {
                $providers = isset($settings['providers'])
                    ? array_values(array_map('strval', (array) $settings['providers']))
                    : null;
                $found ??= new Client((string) $id, (bool) ($settings['external_urls'] ?? true), $providers);
            }
        }

        return $found;
    }

    /**
     * Read the clients file.
     *
     * @param string $file Path to the JSON file.
     *
     * @return array<string, array<string, mixed>> Client id => settings; empty if the file doesn't exist.
     *
     * @throws RuntimeException If the file exists but isn't valid.
     */
    public static function readFile(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !is_array($data['clients'] ?? null)) {
            throw new RuntimeException("Clients file is not valid: $file");
        }

        return $data['clients'];
    }

    /**
     * Write the clients file atomically, readable only by its owner and group.
     *
     * @param string                              $file    Path to the JSON file.
     * @param array<string, array<string, mixed>> $clients Client id => settings.
     *
     * @return void
     *
     * @throws RuntimeException If the file can't be written.
     */
    public static function writeFile(string $file, array $clients): void
    {
        ksort($clients);
        $json = json_encode(['clients' => (object) $clients], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (!is_dir(dirname($file)) || @file_put_contents($temp, $json) === false) {
            throw new RuntimeException("Can't write the clients file: $file");
        }
        @chmod($temp, 0640);
        if (!@rename($temp, $file)) {
            @unlink($temp);
            throw new RuntimeException("Can't write the clients file: $file");
        }
    }

    /**
     * A new random key.
     *
     * @return string The key: KEY_PREFIX and 32 URL-safe characters (192 random bits).
     */
    public static function generateKey(): string
    {
        return self::KEY_PREFIX . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    /**
     * The stored form of a key.
     *
     * @param string $key The key.
     *
     * @return string Its SHA-256 hash, as hex.
     */
    public static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }
}

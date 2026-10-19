<?php

/**
 * The `bin/clients.php` command: issue and manage client keys.
 */

namespace StaticMaps;

use RuntimeException;

/**
 * Manages the clients file (`access.clients_file`, default data/clients.json) (#28).
 *
 * Commands:
 *
 *     add <id> [--providers=a,b] [--no-external-urls] [--must-sign]   issue a key and secret
 *     list                                              show the clients
 *     set <id> [--providers=a,b|all] [--external-urls=yes|no] [--must-sign=yes|no] [--enable|--disable]
 *     rotate <id> [--key|--secret]                      replace a client's key and/or secret
 *     revoke <id>                                       remove a client
 *     import [file]                                     move the deprecated token file's tokens in
 *
 * Keys are random, shown once, and stored only as SHA-256 hashes. Signing
 * secrets have to be stored as they are (the server needs them to check
 * signatures), so keep the clients file readable only by PHP.
 */
final class ClientsCommand
{
    /** @var resource Where normal output goes. */
    private $out;

    /** @var resource Where errors go. */
    private $err;

    /**
     * @param array<string, mixed> $config Configuration from Config::load().
     * @param resource|null        $out    Output stream; null for STDOUT.
     * @param resource|null        $err    Error stream; null for STDERR.
     */
    public function __construct(private array $config, $out = null, $err = null)
    {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
    }

    /**
     * Run a command.
     *
     * @param list<string> $args Command-line arguments after the script name.
     *
     * @return int Exit status: 0 success, 1 failure, 2 usage error.
     */
    public function run(array $args): int
    {
        $command = $args[0] ?? 'help';
        $positional = array_values(array_filter(
            array_slice($args, 1),
            static fn (string $a): bool => !str_starts_with($a, '--')
        ));
        $options = [];
        foreach (array_slice($args, 1) as $arg) {
            if (str_starts_with($arg, '--')) {
                $pair = explode('=', substr($arg, 2), 2);
                $options[$pair[0]] = $pair[1] ?? true;
            }
        }

        try {
            return match ($command) {
                'add'    => $this->add($positional[0] ?? '', $options),
                'list'   => $this->list(),
                'set'    => $this->set($positional[0] ?? '', $options),
                'rotate' => $this->rotate($positional[0] ?? '', $options),
                'revoke' => $this->revoke($positional[0] ?? ''),
                'import' => $this->import($positional[0] ?? (string) ($this->config['token_file'] ?? '')),
                'help', '--help', '-h' => $this->help(),
                default  => $this->usage("Unknown command '$command'"),
            };
        } catch (RuntimeException $e) {
            fwrite($this->err, 'clients: ' . $e->getMessage() . "\n");
            return 1;
        }
    }

    /**
     * Issue a key to a new client.
     *
     * @param string               $id      Client id.
     * @param array<string, mixed> $options Options: --providers, --no-external-urls, --must-sign.
     *
     * @return int Exit status.
     */
    private function add(string $id, array $options): int
    {
        if (!self::validId($id)) {
            return $this->usage('add needs a client id of a-z, 0-9 and - (up to 64 characters)');
        }
        $clients = $this->read();
        if (isset($clients[$id])) {
            throw new RuntimeException("Client '$id' already exists; use rotate to give it a new key");
        }

        $key = Clients::generateKey();
        $secret = Clients::generateSecret();
        $clients[$id] = [
            'key_sha256'    => Clients::hashKey($key),
            'secret'        => $secret,
            'must_sign'     => isset($options['must-sign']),
            'created'       => gmdate('c'),
            'external_urls' => !isset($options['no-external-urls']),
            'providers'     => $this->providers($options['providers'] ?? null),
        ];
        $this->write($clients);
        fwrite($this->out, "Added client '$id'. Its key (shown only now, keep it safe):\n\n    $key\n\n");
        fwrite($this->out, "Send it as the `key` parameter, e.g. /?key=$key&latitude=…\n\n");
        fwrite($this->out, "To sign URLs instead (key=$id plus &signature=…; see the README), its secret is:\n\n");
        fwrite($this->out, "    $secret\n\n");

        return 0;
    }

    /**
     * Show the clients in the clients file.
     *
     * @return int Exit status.
     */
    private function list(): int
    {
        $clients = $this->read();
        if ($clients === []) {
            fwrite($this->out, "No clients yet. Add one with: php bin/clients.php add <id>\n");
            return 0;
        }
        foreach ($clients as $id => $client) {
            fwrite($this->out, sprintf(
                "%-24s %-8s %-8s urls:%-3s providers:%s  created %s\n",
                $id,
                ($client['enabled'] ?? true) ? 'enabled' : 'DISABLED',
                ($client['must_sign'] ?? false) ? 'signed' : (isset($client['secret']) ? 'key|sign' : 'key'),
                ($client['external_urls'] ?? true) ? 'yes' : 'no',
                isset($client['providers']) ? implode(',', (array) $client['providers']) : 'all',
                $client['created'] ?? '?'
            ));
        }

        return 0;
    }

    /**
     * Change a client's permissions.
     *
     * @param string               $id      Client id.
     * @param array<string, mixed> $options Options: --providers=a,b|all, --external-urls=yes|no,
     *                                      --must-sign=yes|no, --enable, --disable.
     *
     * @return int Exit status.
     */
    private function set(string $id, array $options): int
    {
        $clients = $this->read();
        if (!isset($clients[$id])) {
            throw new RuntimeException("No client '$id'");
        }
        if (isset($options['providers'])) {
            $clients[$id]['providers'] = $this->providers($options['providers']);
        }
        if (isset($options['external-urls'])) {
            $clients[$id]['external_urls'] = in_array($options['external-urls'], ['yes', 'true', '1', true], true);
        }
        if (isset($options['must-sign'])) {
            $clients[$id]['must_sign'] = in_array($options['must-sign'], ['yes', 'true', '1', true], true);
        }
        if (isset($options['enable']) || isset($options['disable'])) {
            $clients[$id]['enabled'] = isset($options['enable']);
        }
        $this->write($clients);
        fwrite($this->out, "Updated client '$id'.\n");

        return 0;
    }

    /**
     * Give a client a new key and/or signing secret; the old ones stop working at once.
     *
     * @param string               $id      Client id.
     * @param array<string, mixed> $options Options: --key or --secret to replace only one (default both).
     *
     * @return int Exit status.
     */
    private function rotate(string $id, array $options): int
    {
        $clients = $this->read();
        if (!isset($clients[$id])) {
            throw new RuntimeException("No client '$id'");
        }
        $both = !isset($options['key']) && !isset($options['secret']);
        if ($both || isset($options['key'])) {
            $key = Clients::generateKey();
            $clients[$id]['key_sha256'] = Clients::hashKey($key);
            fwrite($this->out, "New key for '$id' (the old one no longer works):\n\n    $key\n\n");
        }
        if ($both || isset($options['secret'])) {
            $secret = Clients::generateSecret();
            $clients[$id]['secret'] = $secret;
            fwrite($this->out, "New signing secret for '$id' (old signatures no longer work):\n\n    $secret\n\n");
        }
        $clients[$id]['rotated'] = gmdate('c');
        $this->write($clients);

        return 0;
    }

    /**
     * Remove a client; its key stops working at once.
     *
     * @param string $id Client id.
     *
     * @return int Exit status.
     */
    private function revoke(string $id): int
    {
        $clients = $this->read();
        if (!isset($clients[$id])) {
            throw new RuntimeException("No client '$id'");
        }
        unset($clients[$id]);
        $this->write($clients);
        fwrite($this->out, "Revoked client '$id'.\n");

        return 0;
    }

    /**
     * Move the tokens of the deprecated token file into the clients file.
     *
     * Each token keeps working as a key (with every permission), as client
     * `token-<n>`. The token file itself is left alone; remove it afterwards.
     *
     * @param string $file The token file.
     *
     * @return int Exit status.
     */
    private function import(string $file): int
    {
        if ($file === '' || !is_file($file)) {
            throw new RuntimeException("No token file to import: $file");
        }
        $clients = $this->read();
        $existing = array_column($clients, 'key_sha256');
        $n = 0;
        $added = 0;
        foreach ((array) file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $token = trim((string) $line);
            if ($token === '') {
                continue;
            }
            $hash = Clients::hashKey($token);
            if (in_array($hash, $existing, true)) {
                continue;
            }
            do {
                $n++;
            } while (isset($clients["token-$n"]));
            $clients["token-$n"] = ['key_sha256' => $hash, 'created' => gmdate('c'), 'imported' => true];
            $existing[] = $hash;
            $added++;
        }
        $this->write($clients);
        fwrite($this->out, "Imported $added token(s) from $file as clients token-1, token-2, …\n");
        fwrite($this->out, "They keep working as `key` values. Now remove $file (and stop sending `token`).\n");

        return 0;
    }

    /**
     * Show the commands.
     *
     * @return int Exit status.
     */
    private function help(): int
    {
        fwrite($this->out, <<<'TXT'
            Usage: php bin/clients.php <command>

              add <id> [--providers=a,b] [--no-external-urls] [--must-sign]
                                                                issue a key and signing secret
              list                                              show the clients
              set <id> [--providers=a,b|all] [--external-urls=yes|no] [--must-sign=yes|no]
                       [--enable|--disable]
              rotate <id> [--key|--secret]                      new key and/or secret
              revoke <id>                                       remove a client
              import [file]                                     move the old token file (data/apikeys.txt) in

            --providers limits which providers that need a key the client may use.
            --no-external-urls stops it using icon, attribution and tile URLs.

            TXT);

        return 0;
    }

    /**
     * Report a usage error.
     *
     * @param string $message What was wrong.
     *
     * @return int Exit status 2.
     */
    private function usage(string $message): int
    {
        fwrite($this->err, "clients: $message (see: php bin/clients.php help)\n");

        return 2;
    }

    /**
     * Parse a --providers value.
     *
     * @param mixed $value The value: `a,b`, `all`, or null.
     *
     * @return list<string>|null Provider ids, or null for all.
     *
     * @throws RuntimeException If a provider isn't configured.
     */
    private function providers(mixed $value): ?array
    {
        if ($value === null || $value === true || $value === 'all') {
            return null;
        }
        $ids = array_values(array_filter(array_map('trim', explode(',', (string) $value))));
        foreach ($ids as $id) {
            if (!isset($this->config['providers'][$id])) {
                throw new RuntimeException("Unknown provider '$id'");
            }
        }

        return $ids;
    }

    /**
     * Whether a client id is valid.
     *
     * @param string $id The id.
     *
     * @return bool True for 1-64 characters of a-z, 0-9 and -.
     */
    private static function validId(string $id): bool
    {
        return preg_match('/^[a-z0-9-]{1,64}$/', $id) === 1;
    }

    /**
     * Read the clients file.
     *
     * @return array<string, array<string, mixed>> The clients.
     */
    private function read(): array
    {
        return Clients::readFile((string) $this->config['access']['clients_file']);
    }

    /**
     * Write the clients file.
     *
     * @param array<string, array<string, mixed>> $clients The clients.
     *
     * @return void
     */
    private function write(array $clients): void
    {
        Clients::writeFile((string) $this->config['access']['clients_file'], $clients);
    }
}

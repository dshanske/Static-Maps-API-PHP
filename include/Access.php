<?php

/**
 * Who may draw which maps.
 */

namespace StaticMaps;

/**
 * Applies the install's access mode to a request (#28).
 *
 * - `open` (default): anyone may draw free maps; maps whose tiles use a
 *   server-held provider key, maps with `requires_auth`, and URL features
 *   (icon, attribution and tile URLs) need a client key.
 * - `restricted`: every map needs a client key.
 *
 * The key comes in the `key` parameter; `token` is a deprecated alias.
 */
final class Access
{
    /** @var list<string> Values of `access.mode`. */
    public const MODES = ['open', 'restricted'];

    /**
     * @param Clients $clients    The install's clients.
     * @param bool    $restricted Whether every map needs a client key.
     */
    public function __construct(private Clients $clients, public readonly bool $restricted)
    {
    }

    /**
     * The access rules of an install.
     *
     * @param array<string, mixed> $config Configuration from Config::load().
     *
     * @return self The rules.
     *
     * @throws \RuntimeException If the clients file can't be read.
     */
    public static function fromConfig(array $config): self
    {
        $clients = Clients::fromConfig($config);
        if ($clients->legacy > 0) {
            error_log(
                'Static Maps API: the token file (token_file, ' . $config['token_file'] . ') is deprecated; '
                . 'move its tokens with "php bin/clients.php import"'
            );
        }

        return new self($clients, ($config['access']['mode'] ?? 'open') === 'restricted');
    }

    /**
     * The client a request comes from.
     *
     * @param Request $request The request.
     *
     * @return array{Client|null, list<string>} The client (null if none or the key is unknown),
     *         and headers to send (deprecation notice for `token`).
     */
    public function client(Request $request): array
    {
        $key = $request->get('key');
        $headers = [];
        if ($key === false && $request->get('token') !== false) {
            $key = $request->get('token');
            $headers = Deprecation::headers();
        }

        return [$this->clients->find($key), $headers];
    }
}

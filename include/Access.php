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
 * The key comes in the `key` parameter (`token` is a deprecated alias), or a
 * signed URL proves the request comes from a client (#12).
 */
final class Access
{
    /** @var list<string> Values of `access.mode`. */
    public const MODES = ['open', 'restricted'];

    /** @var list<string> Values of `access.credentials`: plain keys, signed URLs, or both. */
    public const CREDENTIALS = ['key', 'signed', 'either'];

    /**
     * @param Clients $clients     The install's clients.
     * @param bool    $restricted  Whether every map needs a client key.
     * @param string  $credentials Which credentials are accepted: one of CREDENTIALS.
     */
    public function __construct(
        private Clients $clients,
        public readonly bool $restricted,
        public readonly string $credentials = 'either'
    ) {
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

        return new self(
            $clients,
            ($config['access']['mode'] ?? 'open') === 'restricted',
            (string) ($config['access']['credentials'] ?? 'either')
        );
    }

    /**
     * The client a request comes from.
     *
     * - A **signed URL** has `key=<client id>` and ends in `&signature=<base64url
     *   HMAC-SHA256 of everything before it>`, made with the client's secret; an
     *   optional signed `expires=<Unix time>` limits how long it works. It must be
     *   a GET request.
     * - Otherwise `key` is the client's **plain key** (`token` is a deprecated alias).
     *
     * A signature that doesn't check out, an expired one, a credential type this
     * install doesn't accept (`access.credentials`), or a plain key of a client
     * with `must_sign` is an error (HTTP 403), so the caller learns why. An
     * unknown plain key simply means "no client".
     *
     * @param Request $request The request.
     *
     * @return array{Client|null, list<string>, string|null} The client (null if none), headers
     *         to send (deprecation notice for `token`), and an error for a 403, or null.
     */
    public function client(Request $request): array
    {
        if ($request->get('signature') !== false) {
            [$client, $error] = $this->signed($request);

            return [$client, [], $error];
        }

        $key = $request->get('key');
        $headers = [];
        if ($key === false && $request->get('token') !== false) {
            $key = $request->get('token');
            $headers = Deprecation::headers();
        }
        $client = $this->clients->find($key);
        if ($client !== null && ($this->credentials === 'signed' || $this->clients->mustSign($client->id))) {
            return [null, $headers, 'This key must be used in a signed URL'];
        }

        return [$client, $headers, null];
    }

    /**
     * Check a signed URL.
     *
     * @param Request $request The request.
     *
     * @return array{Client|null, string|null} The signed client, or null and an error.
     */
    private function signed(Request $request): array
    {
        if ($this->credentials === 'key') {
            return [null, 'Signed URLs are not accepted on this server; use the key'];
        }
        if ($request->method !== 'GET' && $request->method !== 'HEAD') {
            return [null, 'Signed URLs must be GET requests'];
        }
        // The signature signs everything before it, so it must come last.
        if (!preg_match('/^(.+)[?&]signature=([A-Za-z0-9_-]+)$/', $request->uri, $m)) {
            return [null, 'The signature must be the last parameter of a signed URL'];
        }

        $id = $request->get('key');
        $secret = $this->clients->secret($id);
        $valid = hash_equals(Clients::sign($m[1], $secret ?? random_bytes(32)), $m[2]);
        if ($secret === null || !$valid) {
            // The same answer for an unknown client and a wrong signature.
            return [null, 'The signature is not valid'];
        }

        $expires = $request->get('expires');
        if ($expires !== false) {
            if (!is_string($expires) || !ctype_digit($expires)) {
                return [null, 'expires must be a Unix time'];
            }
            if ((int) $expires < time()) {
                return [null, 'This signed URL has expired'];
            }
        }

        return [$this->clients->signedClient((string) $id), null];
    }
}

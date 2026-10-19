<?php

/**
 * A client of the service: someone who was issued a key.
 */

namespace StaticMaps;

/**
 * An authenticated caller and what it may do (#28).
 */
final class Client
{
    /**
     * @param string            $id           The client's id, e.g. `simple-location`.
     * @param bool              $externalUrls Whether it may use marker icon, attribution image and
     *                                        custom tile URLs (the server downloads those).
     * @param list<string>|null $providers    Providers it may use among those that need a key,
     *                                        or null for all of them.
     * @param bool              $signed       Whether this request came with a valid signature
     *                                        (rather than the plain key).
     */
    public function __construct(
        public readonly string $id,
        public readonly bool $externalUrls = true,
        public readonly ?array $providers = null,
        public readonly bool $signed = false
    ) {
    }

    /**
     * Whether the client may use a provider that needs a client key.
     *
     * @param string $provider Provider id.
     *
     * @return bool True if the client has no provider list or the provider is on it.
     */
    public function mayUse(string $provider): bool
    {
        return $this->providers === null || in_array($provider, $this->providers, true);
    }
}

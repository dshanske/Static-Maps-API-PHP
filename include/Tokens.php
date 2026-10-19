<?php

/**
 * Access tokens that unlock token-only features.
 */

namespace StaticMaps;

/**
 * Checks a request's `token` against the token file.
 *
 * The token file has one token per line. Only requests with a listed token
 * may use external marker icons, `basemap=custom` and basemaps with
 * `requires_auth`. Keep this gating for any feature that fetches a
 * user-supplied URL.
 */
final class Tokens
{
    /**
     * @param string $file Path to the token file; it may not exist (then no token is valid).
     */
    public function __construct(private string $file)
    {
    }

    /**
     * Whether a token is listed in the token file.
     *
     * @param mixed $token The request's `token` parameter (false when missing).
     *
     * @return bool True if the token is non-empty and listed.
     */
    public function isValid(mixed $token): bool
    {
        if (! $token) {
            return false;
        }

        if (!file_exists($this->file)) {
            return false;
        }

        $valid_tokens = array_filter(file($this->file));
        array_walk($valid_tokens, function (&$val) {
            $val = trim($val);
        });

        if (in_array($token, $valid_tokens)) {
            return true;
        }

        return false;
    }
}

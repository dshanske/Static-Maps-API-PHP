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
     * Tokens are compared exactly (as strings, in constant time); surrounding
     * whitespace on a line of the file is ignored.
     *
     * @param mixed $token The request's `token` parameter (false when missing).
     *
     * @return bool True if the token is a non-empty string listed in the file.
     */
    public function isValid(mixed $token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }

        if (!is_file($this->file)) {
            return false;
        }

        $lines = file($this->file, FILE_IGNORE_NEW_LINES);
        $valid = false;
        foreach ($lines === false ? [] : $lines as $line) {
            $line = trim($line);
            // Exact, constant-time comparison; check every line so timing doesn't
            // reveal which one matched.
            if ($line !== '' && hash_equals($line, $token)) {
                $valid = true;
            }
        }

        return $valid;
    }
}

<?php

/**
 * Response headers for deprecated request features.
 */

namespace StaticMaps;

/**
 * The headers sent with a response to a request that uses a deprecated
 * feature. See "Versioning and deprecation" in README.md.
 */
final class Deprecation
{
    /** @var string Date the current deprecations were announced, as an RFC 9745 value (2026-10-18). */
    public const DATE = '@1792281600';

    /** @var string Where deprecated features and their replacements are listed. */
    public const INFO_URL = 'https://github.com/dshanske/Static-Maps-API-PHP#deprecated';

    /** @var string Where removed providers and styles are listed (#52). */
    public const REMOVED_URL = 'https://github.com/dshanske/Static-Maps-API-PHP#removed-providers-and-styles';

    /**
     * The `Deprecation` (RFC 9745) and `Link` header lines.
     *
     * @return list<string> Header lines.
     */
    public static function headers(): array
    {
        return [
            'Deprecation: ' . self::DATE,
            'Link: <' . self::INFO_URL . '>; rel="deprecation"',
        ];
    }
}

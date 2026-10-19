<?php

/**
 * An HTTP response received by a test.
 */

namespace StaticMaps\Tests\Support;

use GdImage;
use RuntimeException;

/**
 * Status, headers and body of one response from PhpServer::get().
 */
final class Response
{
    /**
     * @param int                   $status  HTTP status code.
     * @param array<string, string> $headers Response headers, names lower-cased.
     * @param string                $body    Response body.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body
    ) {
    }

    /**
     * One response header.
     *
     * @param string $name Header name, any case.
     *
     * @return string|null The value, or null if the header is missing.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Decode the body as an image.
     *
     * @return GdImage The decoded image.
     *
     * @throws RuntimeException If the body is not an image GD can read.
     */
    public function image(): GdImage
    {
        $im = $this->body === '' ? false : @imagecreatefromstring($this->body);
        if ($im === false) {
            throw new RuntimeException('Response body is not an image: ' . substr($this->body, 0, 200));
        }

        return $im;
    }
}

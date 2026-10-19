<?php

/**
 * A rendered map, ready to send.
 */

namespace StaticMaps;

/**
 * Status, headers and body of the response to a request.
 */
final class Response
{
    /**
     * @param list<string> $headers Header lines, e.g. `Content-type: image/png`.
     * @param string       $body    Response body (the image file, or a page or message).
     * @param int          $status  HTTP status code.
     */
    public function __construct(
        public readonly array $headers,
        public readonly string $body,
        public readonly int $status = 200
    ) {
    }

    /**
     * A plain-text error response.
     *
     * @param int          $status  HTTP status code, e.g. 400.
     * @param string       $message Reason shown to the client, one line.
     * @param list<string> $headers Further header lines to send.
     *
     * @return self The response.
     */
    public static function error(int $status, string $message, array $headers = []): self
    {
        return new self(array_merge($headers, ['Content-Type: text/plain; charset=utf-8']), $message . "\n", $status);
    }

    /**
     * Send the headers and body to the client.
     *
     * @return void
     */
    public function send(): void
    {
        http_response_code($this->status);
        foreach (array_unique($this->headers) as $header) {
            // A response can carry several Link headers (deprecation, help); other
            // headers replace any earlier one of the same name.
            header($header, stripos($header, 'Link:') !== 0);
        }
        echo $this->body;
    }

    /**
     * A copy of this response with extra headers sent before its own.
     *
     * @param list<string> $headers Header lines to add.
     *
     * @return self The new response.
     */
    public function withHeaders(array $headers): self
    {
        return new self(array_merge($headers, $this->headers), $this->body, $this->status);
    }
}

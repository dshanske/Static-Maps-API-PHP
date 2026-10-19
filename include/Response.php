<?php

/**
 * A rendered map, ready to send.
 */

namespace StaticMaps;

/**
 * Headers and body of the response to a map request.
 */
final class Response
{
    /**
     * @param list<string> $headers Header lines, e.g. `Content-type: image/png`.
     * @param string       $body    Response body (the image file).
     */
    public function __construct(public readonly array $headers, public readonly string $body)
    {
    }

    /**
     * Send the headers and body to the client.
     *
     * @return void
     */
    public function send(): void
    {
        foreach ($this->headers as $header) {
            header($header);
        }
        echo $this->body;
    }
}

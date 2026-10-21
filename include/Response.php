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
        return new self(
            array_merge($headers, [
                'Content-Type: text/plain; charset=utf-8',
                'Cache-Control: no-store',
                // Also as a header, so a failed <img> shows the reason in the browser's devtools.
                'X-Map-Error: ' . preg_replace('/[\x00-\x1f\x7f]+/', ' ', $message),
            ]),
            $message . "\n",
            $status
        );
    }

    /**
     * Send the headers and body to the client.
     *
     * @return void
     */
    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headerLines() as $header) {
            header($header, stripos($header, 'Link:') !== 0);
        }
        echo $this->body;
    }

    /**
     * The header lines as sent (#79).
     *
     * Repeated lines are sent once. Headers with the same name but different
     * values are combined into one, the values joined with `, ` (HTTP list
     * syntax), because PHP's header() replaces an earlier header of the same
     * name; e.g. two `X-Map-Limit` notices become one header with both.
     * `Link` headers stay separate (a response can carry several).
     *
     * @return list<string> Header lines, in the order their names first appear.
     */
    public function headerLines(): array
    {
        $lines = [];
        $byName = [];
        foreach (array_unique($this->headers) as $header) {
            $parts = explode(':', $header, 2);
            $name = strtolower(trim($parts[0]));
            if (count($parts) < 2 || $name === 'link' || !isset($byName[$name])) {
                $lines[] = $header;
                $byName[$name] = count($lines) - 1;
                continue;
            }
            $lines[$byName[$name]] .= ', ' . trim($parts[1]);
        }

        return $lines;
    }

    /**
     * Answer a conditional request: a 304 without a body if the client already has this version.
     *
     * Applies to 200 responses with an `ETag` header. The 304 keeps the other
     * headers (Cache-Control, ETag, notices) and drops Content-Type.
     *
     * @param string|null $ifNoneMatch The request's If-None-Match header, or null.
     *
     * @return self This response, or the 304.
     */
    public function conditional(?string $ifNoneMatch): self
    {
        if ($this->status !== 200 || $ifNoneMatch === null || $ifNoneMatch === '') {
            return $this;
        }
        $etag = null;
        foreach ($this->headers as $header) {
            if (stripos($header, 'ETag:') === 0) {
                $etag = trim(substr($header, 5));
            }
        }
        if ($etag === null) {
            return $this;
        }

        $tags = array_map(
            static fn (string $tag): string => preg_replace('/^W\//', '', trim($tag)) ?? '',
            explode(',', $ifNoneMatch)
        );
        if (!in_array($etag, $tags, true) && !in_array('*', $tags, true)) {
            return $this;
        }

        $headers = array_values(array_filter(
            $this->headers,
            static fn (string $h): bool => stripos($h, 'Content-type:') !== 0
        ));

        return new self($headers, '', 304);
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

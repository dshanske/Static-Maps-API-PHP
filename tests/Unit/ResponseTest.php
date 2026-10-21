<?php

/**
 * Tests for include/Response.php.
 */

namespace StaticMaps\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StaticMaps\Response;

/**
 * Header lines as sent.
 */
final class ResponseTest extends TestCase
{
    /**
     * Same-name headers are combined into one (#79); repeats are sent once; Link stays separate.
     *
     * @return void
     */
    public function testHeaderLines(): void
    {
        $response = new Response([
            'X-Map-Limit: left out 2 marker(s) over the limit of 100',
            'Content-Type: image/png',
            'Deprecation: true',
            'x-map-limit: left out 1 path(s) over the limit of 10000 points',
            'Deprecation: true',
            'Link: <https://a.example/>; rel="deprecation"',
            'Link: <https://b.example/>; rel="help"',
        ], '');

        $this->assertSame([
            'X-Map-Limit: left out 2 marker(s) over the limit of 100, '
                . 'left out 1 path(s) over the limit of 10000 points',
            'Content-Type: image/png',
            'Deprecation: true',
            'Link: <https://a.example/>; rel="deprecation"',
            'Link: <https://b.example/>; rel="help"',
        ], $response->headerLines());
    }
}

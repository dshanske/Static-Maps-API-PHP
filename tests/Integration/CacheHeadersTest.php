<?php

/**
 * Cache headers on map images (#11).
 */

namespace StaticMaps\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use StaticMaps\Tests\Support\RenderTestCase;

/**
 * Cache-Control, ETag and 304 responses for map images.
 */
final class CacheHeadersTest extends RenderTestCase
{
    /** @var string Query string of a plain map. */
    private const MAP = '/?latitude=45.5165&longitude=-122.6764&zoom=14';

    /**
     * Requests and the Cache-Control they get.
     *
     * @return array<string, array{string, string}> Query string suffix and expected Cache-Control.
     */
    public static function cacheControlCases(): array
    {
        return [
            'plain map'               => ['', 'public, max-age=604800'],
            'with a token'            => ['&token=test-token', 'private, max-age=604800'],
            'provider with cache off' => ['&provider=no-proxy', 'private, max-age=604800'],
            'fallback map'            => ['&provider=nope', 'public, max-age=300'],
            'bad request'             => ['&zoom=abc', 'no-store'],
        ];
    }

    /**
     * The Cache-Control header for a request.
     *
     * @param string $suffix   Added to the map's query string.
     * @param string $expected Expected Cache-Control.
     *
     * @return void
     */
    #[DataProvider('cacheControlCases')]
    public function testCacheControl(string $suffix, string $expected): void
    {
        $this->assertSame($expected, self::$app->get(self::MAP . $suffix)->header('Cache-Control'));
    }

    /**
     * The ETag is the same for the same map and changes with it.
     *
     * @return void
     */
    public function testEtag(): void
    {
        $first = self::$app->get(self::MAP)->header('ETag');

        $this->assertMatchesRegularExpression('/^"[0-9a-f]{40}"$/', (string) $first);
        $this->assertSame($first, self::$app->get(self::MAP)->header('ETag'));
        $this->assertNotSame($first, self::$app->get(self::MAP . '&width=200')->header('ETag'));
        $this->assertNull(self::$app->get(self::MAP . '&zoom=abc')->header('ETag'), 'no ETag on errors');
    }

    /**
     * If-None-Match values and whether they get a 304.
     *
     * @return array<string, array{string, bool}> Header value ({etag} = the current ETag) and 304 expected.
     */
    public static function conditionalCases(): array
    {
        return [
            'same tag'        => ['{etag}', true],
            'weak tag'        => ['W/{etag}', true],
            'in a list'       => ['"other", {etag}', true],
            'any'             => ['*', true],
            'different tag'   => ['"0000"', false],
        ];
    }

    /**
     * A matching If-None-Match gets a 304 with no body but the cache headers.
     *
     * @param string $value     If-None-Match value; {etag} is replaced with the map's ETag.
     * @param bool   $notModified Whether a 304 is expected.
     *
     * @return void
     */
    #[DataProvider('conditionalCases')]
    public function testConditionalRequest(string $value, bool $notModified): void
    {
        $etag = (string) self::$app->get(self::MAP)->header('ETag');
        $response = self::$app->request('GET', self::MAP, ['If-None-Match: ' . str_replace('{etag}', $etag, $value)]);

        if ($notModified) {
            $this->assertSame(304, $response->status);
            $this->assertSame('', $response->body);
            $this->assertSame($etag, $response->header('ETag'));
            $this->assertSame('public, max-age=604800', $response->header('Cache-Control'));
        } else {
            $this->assertSame(200, $response->status);
            $this->assertSame('image/png', $response->header('Content-Type'));
        }
    }
}

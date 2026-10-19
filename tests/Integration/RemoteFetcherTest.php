<?php

/**
 * Download limits for tiles, icons and attribution images.
 */

namespace StaticMaps\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use StaticMaps\RemoteFetcher;
use StaticMaps\Tests\Support\RenderTestCase;

/**
 * StaticMaps\RemoteFetcher against the fake tile server, with the test
 * config's limits: 2-second timeout, 200000 bytes, 2 redirects.
 */
final class RemoteFetcherTest extends RenderTestCase
{
    /**
     * A fetcher with the test config's limits.
     *
     * @return RemoteFetcher The fetcher.
     */
    private static function fetcher(): RemoteFetcher
    {
        return new RemoteFetcher(2, 2, 200000, 2, 'Static Maps API tests');
    }

    /**
     * A normal download returns the body and sends the User-Agent.
     *
     * @return void
     */
    public function testFetch(): void
    {
        $body = self::fetcher()->fetch(self::$tiles?->url . '/overlay/1/1/1.png');

        $this->assertNotNull($body);
        $this->assertSame("\x89PNG", substr((string) $body, 0, 4));
        $this->assertSame(['Static Maps API tests'], array_column($this->tileRequests(), 'ua'));
    }

    /**
     * One redirect within the limit is followed.
     *
     * @return void
     */
    public function testRedirectIsFollowed(): void
    {
        $this->assertNotNull(self::fetcher()->fetch(self::$tiles?->url . '/redirect-once'));
    }

    /**
     * Downloads that break a limit, or aren't http(s), give nothing.
     *
     * @return array<string, array{string}> URL path on the tile server, or a full URL.
     */
    public static function refusedCases(): array
    {
        return [
            'too big'              => ['/big.png'],
            'too slow'             => ['/slow.png'],
            'too many redirects'   => ['/redirect-loop'],
            'redirect to file://'  => ['/redirect-file'],
            'error status'         => ['/not-found.png'],
            'file:// URL'          => ['file:///etc/hostname'],
        ];
    }

    /**
     * A refused download returns null.
     *
     * @param string $url Path on the tile server, or a full URL.
     *
     * @return void
     */
    #[DataProvider('refusedCases')]
    public function testRefused(string $url): void
    {
        $full = str_starts_with($url, '/') ? self::$tiles?->url . $url : $url;
        $started = microtime(true);

        $this->assertNull(self::fetcher()->fetch((string) $full));
        $this->assertLessThan(3.5, microtime(true) - $started, 'within the timeout');
    }

    /**
     * Through the renderer: a too-big icon URL is skipped with a header.
     *
     * @return void
     */
    public function testOversizedIconIsSkipped(): void
    {
        $icon = self::$tiles?->url . '/big.png';
        $response = $this->render([
            'latitude' => 45.5,
            'longitude' => -122.6,
            'zoom' => 12,
            'key' => 'test-token',
            'marker' => ["lat:45.5;lng:-122.6;icon:$icon"],
        ]);

        $this->assertSame(200, $response->status);
        $this->assertSame('icon could not be loaded', $response->header('X-Marker-1'));
    }
}

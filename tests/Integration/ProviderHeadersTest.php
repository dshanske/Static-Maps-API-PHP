<?php

/**
 * Provider API keys sent in request headers (#28).
 */

namespace StaticMaps\Tests\Integration;

use StaticMaps\Tests\Support\RenderTestCase;

/**
 * The `headered` fixture provider sends its key as `X-Api-Key`; the fake tile
 * server's `header` layer only answers with it.
 */
final class ProviderHeadersTest extends RenderTestCase
{
    /** @var array<string, mixed> A map in tile 2608/5860 at zoom 14. */
    private const MAP = [
        'latitude'  => 45.5165,
        'longitude' => -122.6764,
        'zoom'      => 14,
        'width'     => 300,
        'height'    => 300,
    ];

    /** @var list<int> The colour of a tile that failed to download. */
    private const GRAY = [224, 224, 224];

    /**
     * With a client key, the tiles are requested with the provider's headers, and the key
     * never appears in the URL.
     *
     * @return void
     */
    public function testHeadersAreSent(): void
    {
        $response = $this->render(self::MAP + ['provider' => 'headered', 'key' => 'test-token']);

        $this->assertSame(200, $response->status);
        $this->assertNull($response->header('X-Map-Fallback'));
        $this->assertPixel(self::tileColor(2608, 5860), $response->image(), 150, 150);
        $requests = $this->tileRequests();
        $this->assertNotSame([], $requests);
        foreach ($requests as $request) {
            $this->assertSame('fixture-header-key', $request['key']);
            $this->assertStringNotContainsString('fixture-header-key', $request['path'] . $request['query']);
        }
    }

    /**
     * A key in the headers makes the provider need a client key, like one in the URL.
     *
     * @return void
     */
    public function testHeaderKeyNeedsAClientKey(): void
    {
        $response = $this->render(self::MAP + ['provider' => 'headered']);

        $this->assertStringStartsWith('unavailable;', (string) $response->header('X-Map-Fallback'));
        foreach ($this->tileRequests() as $request) {
            $this->assertNull($request['key'], 'the provider key is not sent for the default map');
        }

        $plain = $this->render(self::MAP + ['provider' => 'headered', 'style' => 'plain']);
        $this->assertNull($plain->header('X-Map-Fallback'), 'fixed headers without {key} need no client key');
    }

    /**
     * A tile request carrying the key doesn't follow a redirect (the tile is drawn gray).
     *
     * @return void
     */
    public function testRedirectsAreNotFollowed(): void
    {
        $response = $this->render(self::MAP + ['provider' => 'headered', 'style' => 'bounce', 'key' => 'test-token']);

        $this->assertSame(200, $response->status);
        $this->assertPixel(self::GRAY, $response->image(), 150, 150);
        $followed = array_filter($this->tilePaths(), static fn (string $p): bool => str_starts_with($p, '/base/'));
        $this->assertSame([], $followed);
    }

    /**
     * The query API lists the provider (for a client that may use it) without its headers.
     *
     * @return void
     */
    public function testApiHidesHeaders(): void
    {
        $body = self::$app->get('/api/v1/providers?key=test-token')->body;

        $this->assertStringContainsString('"headered"', $body);
        $this->assertStringNotContainsString('fixture-header-key', $body);
        $this->assertStringNotContainsString('X-Api-Key', $body);
    }
}

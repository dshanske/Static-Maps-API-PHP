<?php

/**
 * Overlays and coverage areas (#30).
 */

namespace StaticMaps\Tests\Integration;

use StaticMaps\Tests\Support\RenderTestCase;
use StaticMaps\WebMercator;

/**
 * `overlay=` layers on top of any map, and regional maps with `bounds`.
 */
final class OverlayTest extends RenderTestCase
{
    /** @var array<string, mixed> A 300 x 300 map in Portland at zoom 14. */
    private const MAP = [
        'latitude'  => 45.5165,
        'longitude' => -122.6764,
        'zoom'      => 14,
        'width'     => 300,
        'height'    => 300,
    ];

    /**
     * Where the centre of the overlay square of the tile under the map centre is, in the image.
     *
     * @return array{int, int, int, int} Image x and y, and that tile's column and row.
     */
    private static function squareCentre(): array
    {
        $wm = new WebMercator();
        $c = $wm->latLngToPixels(self::MAP['latitude'], self::MAP['longitude'], 14);
        [$tx, $ty] = [(int) floor($c['x'] / 256), (int) floor($c['y'] / 256)];

        return [(int) ($tx * 256 + 128 - ($c['x'] - 150)), (int) ($ty * 256 + 128 - ($c['y'] - 150)), $tx, $ty];
    }

    /**
     * Paths of overlay tile requests.
     *
     * @return list<string> Requested `/overlay/` paths.
     */
    private function overlayRequests(): array
    {
        return array_values(array_filter(
            $this->tilePaths(),
            static fn (string $p): bool => str_starts_with($p, '/overlay/')
        ));
    }

    /**
     * An overlay is drawn on top of the map; where it is transparent the map shows through.
     *
     * @return void
     */
    public function testOverlayOnTop(): void
    {
        [$x, $y, $tx, $ty] = self::squareCentre();
        $response = $this->render(self::MAP + ['overlay' => 'squares']);

        $this->assertSame(200, $response->status);
        $this->assertNull($response->header('X-Map-Overlay'));
        $this->assertNotSame([], $this->overlayRequests());
        $im = $response->image();
        $this->assertPixel([255, 255, 255], $im, $x, $y);
        $this->assertPixel(self::tileColor($tx, $ty), $im, $x - 40, $y - 40);
    }

    /**
     * Opacity mixes the overlay with the map.
     *
     * @return void
     */
    public function testOpacity(): void
    {
        [$x, $y, $tx, $ty] = self::squareCentre();
        $im = $this->render(self::MAP + ['overlay' => 'faint'])->image();

        $expected = array_map(static fn (int $c): int => (int) round(($c + 255) / 2), self::tileColor($tx, $ty));
        $this->assertPixel($expected, $im, $x, $y, 3);
    }

    /**
     * Unknown, disabled, key-only and too many overlays are left out with a header; repeats count once.
     *
     * @return void
     */
    public function testUnavailableAndLimit(): void
    {
        $response = $this->render(self::MAP + ['overlay' => 'nope,retired,private,squares,squares, faint']);
        $this->assertSame(200, $response->status);
        $this->assertSame('unavailable=nope,retired,private', $response->header('X-Map-Overlay'));

        $keyed = $this->render(
            self::MAP + ['overlay' => ['private', 'squares', 'faint', 'missing', 'nope'], 'key' => 'test-token']
        );
        $this->assertSame('unavailable=nope; over-limit=missing; max=3', $keyed->header('X-Map-Overlay'));
    }

    /**
     * An overlay is skipped outside its zoom range or its coverage area, without downloading tiles.
     *
     * @return void
     */
    public function testSkippedOutsideItsRange(): void
    {
        $this->render(['overlay' => 'close,tokyo', 'zoom' => 11] + self::MAP);
        $this->assertSame([], $this->overlayRequests());

        $this->render(self::MAP + ['overlay' => 'close']);
        $this->assertNotSame([], $this->overlayRequests(), 'zoom 14 is in range');
    }

    /**
     * A failed overlay tile is transparent, not gray.
     *
     * @return void
     */
    public function testFailedOverlayTilesAreTransparent(): void
    {
        [$x, $y, $tx, $ty] = self::squareCentre();
        $im = $this->render(self::MAP + ['overlay' => 'missing'])->image();

        $this->assertPixel(self::tileColor($tx, $ty), $im, $x, $y);
    }

    /**
     * A regional map: drawn inside its bounds, `partial` when partly outside, the default map when
     * entirely outside.
     *
     * @return void
     */
    public function testRegionalMap(): void
    {
        $inside = $this->render(self::MAP + ['provider' => 'fixture', 'style' => 'regional']);
        $this->assertNull($inside->header('X-Map-Coverage'));
        $this->assertNull($inside->header('X-Map-Fallback'));

        $edge = $this->render(['latitude' => 45.0, 'longitude' => -122.5, 'zoom' => 8, 'width' => 300, 'height' => 300]
            + ['provider' => 'fixture', 'style' => 'regional']);
        $this->assertSame('partial', $edge->header('X-Map-Coverage'));

        $tokyo = $this->render(['latitude' => 35.68, 'longitude' => 139.69, 'zoom' => 10, 'width' => 300]
            + ['height' => 300, 'provider' => 'fixture', 'style' => 'regional']);
        $this->assertSame('outside; requested=fixture/regional; drawn=fixture/base', $tokyo->header('X-Map-Fallback'));
        $this->assertNull($tokyo->header('X-Map-Coverage'));
    }

    /**
     * The query API lists overlays and bounds, and combines credits.
     *
     * @return void
     */
    public function testApi(): void
    {
        $list = json_decode(self::$app->get('/api/v1/overlays')->body, true);
        $this->assertSame(['squares', 'faint', 'close', 'tokyo', 'missing'], array_column($list['overlays'], 'id'));
        $this->assertSame(0.5, $list['overlays'][1]['opacity']);
        $this->assertEquals([139.0, 35.0, 140.0, 36.0], $list['overlays'][3]['bounds']);
        $this->assertSame(3, $list['max_overlays']);

        $query = 'provider=fixture&style=credited&overlay=squares';
        $credit = json_decode(self::$app->get('/api/v1/attribution?' . $query)->body, true);
        $this->assertSame(['squares'], $credit['overlays']);
        $this->assertSame('© Fixture tiles contributors | Squares Inc.', $credit['text']);
        $this->assertStringEndsWith(' | <a href="https://squares.example/">Squares</a>', (string) $credit['html']);

        $providers = json_decode(self::$app->get('/api/v1/providers')->body, true);
        $styles = array_column(array_column($providers['providers'], null, 'id')['fixture']['styles'], null, 'id');
        $this->assertEquals([-123.0, 45.0, -122.0, 46.0], $styles['regional']['bounds']);
    }
}

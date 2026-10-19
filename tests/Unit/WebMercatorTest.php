<?php

/**
 * Tests for include/WebMercator.php.
 */

namespace StaticMaps\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StaticMaps\WebMercator;

/**
 * Pixel and tile maths for 256px Web Mercator tiles.
 */
final class WebMercatorTest extends TestCase
{
    /** @var WebMercator The object under test. */
    private WebMercator $wm;

    /**
     * Create the object under test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->wm = new WebMercator();
    }

    /**
     * The world is 256 pixels wide at zoom 0 and doubles with each zoom level.
     *
     * @return void
     */
    public function testTotalPixelsForZoomLevel(): void
    {
        $this->assertEquals(256, $this->wm->totalPixelsForZoomLevel(0));
        $this->assertEquals(512, $this->wm->totalPixelsForZoomLevel(1));
        $this->assertEquals(256 * 2 ** 14, $this->wm->totalPixelsForZoomLevel(14));
    }

    /**
     * Known lat/lng → pixel conversions.
     *
     * @return array<string, array{float, float, int, float, float}> Latitude, longitude, zoom,
     *                                                             expected x and y in pixels.
     */
    public static function pixelCases(): array
    {
        return [
            'null island z0'    => [0.0, 0.0, 0, 128.0, 128.0],
            'null island z1'    => [0.0, 0.0, 1, 256.0, 256.0],
            'north-west corner' => [85.05112877980662, -180.0, 1, 0.0, 0.0],
            'portland z14'      => [45.5165, -122.6764, 14, 667868.0, 1500247.0],
        ];
    }

    /**
     * latLngToPixels() returns rounded pixel coordinates.
     *
     * @param float $lat Latitude in degrees.
     * @param float $lng Longitude in degrees.
     * @param int   $zoom Zoom level.
     * @param float $x   Expected x in pixels.
     * @param float $y   Expected y in pixels.
     *
     * @return void
     */
    #[DataProvider('pixelCases')]
    public function testLatLngToPixels(float $lat, float $lng, int $zoom, float $x, float $y): void
    {
        $this->assertEquals(['x' => $x, 'y' => $y], $this->wm->latLngToPixels($lat, $lng, $zoom));
    }

    /**
     * pixelsToLatLng() is the inverse of latLngToPixels(), up to pixel rounding.
     *
     * @return void
     */
    public function testPixelsToLatLngRoundTrip(): void
    {
        $px = $this->wm->latLngToPixels(45.5165, -122.6764, 14);
        $ll = $this->wm->pixelsToLatLng($px['x'], $px['y'], 14);

        // One pixel at zoom 14 is about 0.00007 degrees.
        $this->assertEqualsWithDelta(45.5165, $ll['lat'], 0.0001);
        $this->assertEqualsWithDelta(-122.6764, $ll['lng'], 0.0001);
    }

    /**
     * Tile ↔ pixel conversions.
     *
     * @return void
     */
    public function testTileConversions(): void
    {
        $this->assertEquals(['x' => 512, 'y' => 768], $this->wm->tileToPixels(2, 3));
        $this->assertEquals(['x' => 1, 'y' => 1], $this->wm->pixelsToTile(511, 256));
        $this->assertEquals(['x' => 0, 'y' => 0], $this->wm->pixelsToTile(255, 0));
        $this->assertEquals(['x' => 44, 'y' => 255], $this->wm->positionInTile(300, 511));
    }
}

<?php

/**
 * High-density maps: scale=2 (#10).
 */

namespace StaticMaps\Tests\Integration;

use GdImage;
use StaticMaps\Tests\Support\RenderTestCase;

/**
 * The same area at twice the pixels.
 */
final class ScaleTest extends RenderTestCase
{
    /** @var array<string, mixed> A small map in Portland with a red dot marker. */
    private const MAP = [
        'latitude'  => 45.5165,
        'longitude' => -122.6764,
        'zoom'      => 9,
        'width'     => 200,
        'height'    => 150,
        'marker'    => ['lat:45.53;lng:-122.62;icon:dot-small-red'],
        'path'      => ['color:0000ff;weight:3;[-122.80,45.45][-122.55,45.45]'],
    ];

    /**
     * The centre of the pixels of one colour.
     *
     * @param GdImage   $im    The image.
     * @param list<int> $color RGB, matched within 40 on each channel.
     *
     * @return array{float, float, int} Mean x, mean y and the number of matching pixels.
     */
    private static function centroid(GdImage $im, array $color): array
    {
        [$sx, $sy, $n] = [0, 0, 0];
        for ($x = 0; $x < imagesx($im); $x++) {
            for ($y = 0; $y < imagesy($im); $y++) {
                $rgb = imagecolorat($im, $x, $y);
                $c = [($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255];
                if (max(array_map(static fn (int $a, int $b): int => abs($a - $b), $c, $color)) <= 40) {
                    [$sx, $sy, $n] = [$sx + $x, $sy + $y, $n + 1];
                }
            }
        }

        return $n === 0 ? [0.0, 0.0, 0] : [$sx / $n, $sy / $n, $n];
    }

    /**
     * Zoom levels of the tiles requested.
     *
     * @return list<int> Distinct zoom levels, in order.
     */
    private function tileZooms(): array
    {
        $zooms = array_values(array_unique(array_map(
            static fn (string $p): int => (int) explode('/', $p)[2],
            $this->tilePaths()
        )));
        sort($zooms);

        return $zooms;
    }

    /**
     * scale=2: twice the pixels of the same area, with tiles one zoom level up, and the marker,
     * path and attribution where they were, twice as big.
     *
     * @return void
     */
    public function testSameAreaTwiceThePixels(): void
    {
        $one = $this->render(self::MAP);
        $this->assertSame([9], $this->tileZooms());
        $two = $this->render(self::MAP + ['scale' => 2]);
        $this->assertSame([9, 10], $this->tileZooms(), 'the log covers both renders');

        $this->assertSame(200, $two->status);
        $im1 = $one->image();
        $im2 = $two->image();
        $this->assertSame([400, 300], [imagesx($im2), imagesy($im2)]);
        $this->assertNull($two->header('X-Map-Scale'));

        [$x1, $y1, $red1] = self::centroid($im1, [230, 40, 40]);
        [$x2, $y2, $red2] = self::centroid($im2, [230, 40, 40]);
        $this->assertGreaterThan(0, $red1);
        // Within 3 px: pixel positions are rounded at each zoom, and the 13 px icon's
        // whole-pixel anchor sits off-centre by a different fraction at each size.
        $this->assertEqualsWithDelta(2 * $x1, $x2, 3, 'marker in the same place');
        $this->assertEqualsWithDelta(2 * $y1, $y2, 3);
        $this->assertEqualsWithDelta(4 * $red1, $red2, $red1 * 1.5, 'marker twice the size');

        [$px1, $py1, $blue1] = self::centroid($im1, [0, 0, 255]);
        [$px2, $py2, $blue2] = self::centroid($im2, [0, 0, 255]);
        $this->assertEqualsWithDelta(2 * $py1, $py2, 3, 'path in the same place');
        $this->assertEqualsWithDelta(4 * $blue1, $blue2, $blue1 * 1.5, 'path twice as wide and long');
    }

    /**
     * A map whose URLs have {r} gets @2x tiles of the same zoom.
     *
     * @return void
     */
    public function testRetinaTiles(): void
    {
        $response = $this->render(self::MAP + ['provider' => 'fixture', 'style' => 'retina', 'scale' => 2]);

        $this->assertSame(200, $response->status);
        $paths = $this->tilePaths();
        $this->assertNotSame([], $paths);
        foreach ($paths as $path) {
            $this->assertMatchesRegularExpression('#^/base/9/\d+/\d+@2x\.png$#', $path);
        }
        // Centre pixel 200,150: the centre is in tile 81/183 at zoom 9.
        $this->assertPixel(self::tileColor(81, 183), $response->image(), 200, 150);
    }

    /**
     * At scale 1, {r} is empty.
     *
     * @return void
     */
    public function testRetinaPlaceholderAtScaleOne(): void
    {
        $this->render(self::MAP + ['provider' => 'fixture', 'style' => 'retina']);

        $this->assertNotSame([], $this->tilePaths());
        foreach ($this->tilePaths() as $path) {
            $this->assertMatchesRegularExpression('#^/base/9/\d+/\d+\.png$#', $path);
        }
    }

    /**
     * At the map's highest zoom there are no tiles above: the tiles are enlarged, with a header.
     *
     * @return void
     */
    public function testEnlargedAtMaxZoom(): void
    {
        $response = $this->render(
            ['zoom' => 10, 'provider' => 'fixture', 'style' => 'zoom', 'scale' => 2] + self::MAP
        );

        $this->assertSame([10], $this->tileZooms());
        $this->assertSame('enlarged; the map has no tiles above zoom 10', $response->header('X-Map-Scale'));
        $this->assertSame([400, 300], [imagesx($response->image()), imagesy($response->image())]);
    }

    /**
     * The size limits apply to the image as sent: a scale=2 map may be half as wide.
     *
     * @return void
     */
    public function testLimits(): void
    {
        $response = $this->render(['width' => 1000, 'height' => 100, 'scale' => 2] + self::MAP);

        $this->assertSame('clamped; requested=1000x100; drawn=640x100; scale=2', $response->header('X-Map-Size'));
        $this->assertSame([1280, 200], [imagesx($response->image()), imagesy($response->image())]);
    }

    /**
     * Only 1 and 2 are accepted.
     *
     * @return void
     */
    public function testInvalidScale(): void
    {
        foreach (['3', '0', '1.5', 'x'] as $scale) {
            $response = $this->render(['scale' => $scale] + self::MAP);
            $this->assertSame(400, $response->status, $scale);
            $this->assertStringStartsWith('scale must be', $response->body);
        }
    }
}

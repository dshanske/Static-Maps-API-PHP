<?php

/**
 * Maps across the antimeridian (±180° longitude) (#35).
 */

namespace StaticMaps\Tests\Integration;

use GdImage;
use StaticMaps\Tests\Support\RenderTestCase;

/**
 * Markers, paths, bbox and centres on both sides of ±180°.
 */
final class AntimeridianTest extends RenderTestCase
{
    /**
     * Zoom levels and tile columns requested, checking every column is inside the world.
     *
     * @return array{list<int>, list<int>} Zoom levels and tile columns.
     */
    private function tileColumns(): array
    {
        $zooms = [];
        $columns = [];
        foreach ($this->tilePaths() as $path) {
            [, , $z, $x] = explode('/', $path);
            $this->assertGreaterThanOrEqual(0, (int) $x, $path);
            $this->assertLessThan(2 ** (int) $z, (int) $x, $path);
            $zooms[] = (int) $z;
            $columns[] = (int) $x;
        }

        return [array_values(array_unique($zooms)), array_values(array_unique($columns))];
    }

    /**
     * How many pixels of a colour are in the left and right halves of an image.
     *
     * @param GdImage   $im    The image.
     * @param list<int> $color RGB.
     *
     * @return array{int, int} Pixels in the left half, and in the right half.
     */
    private static function colorHalves(GdImage $im, array $color): array
    {
        $halves = [0, 0];
        for ($x = 0; $x < imagesx($im); $x++) {
            for ($y = 0; $y < imagesy($im); $y++) {
                $rgb = imagecolorat($im, $x, $y);
                if ([($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255] === $color) {
                    $halves[$x < imagesx($im) / 2 ? 0 : 1]++;
                }
            }
        }

        return $halves;
    }

    /**
     * Two markers either side of ±180° (Fiji) fit as a small area, with tiles from both edges of
     * the world, and both are drawn.
     *
     * @return void
     */
    public function testMarkersEitherSide(): void
    {
        $response = $this->render([
            'marker' => ['lat:-17;lng:178.5;icon:dot-small-red', 'lat:-18;lng:-179;icon:dot-small-red'],
            'width'  => 400,
            'height' => 300,
        ]);

        $this->assertSame(200, $response->status);
        [$zooms, $columns] = $this->tileColumns();
        $this->assertCount(1, $zooms);
        $this->assertGreaterThanOrEqual(6, $zooms[0], 'not the whole world');
        $this->assertContains(0, $columns);
        $this->assertContains(2 ** $zooms[0] - 1, $columns);
    }

    /**
     * A path from Japan to Alaska takes the short way, across the Pacific: it is drawn across the
     * middle of the map, which is centred near the antimeridian.
     *
     * @return void
     */
    public function testPathAcrossThePacific(): void
    {
        $response = $this->render([
            'path'    => ['color:ff0000;weight:4;[139.7,35.7][-150,61.2]'],
            'width'   => 400,
            'height'  => 300,
            'padding' => '0',
        ]);

        $this->assertSame(200, $response->status);
        [$zooms, $columns] = $this->tileColumns();
        $this->assertGreaterThanOrEqual(2, $zooms[0]);
        $this->assertContains(0, $columns, 'tiles east of 180');
        [$left, $right] = self::colorHalves($response->image(), [255, 0, 0]);
        $this->assertGreaterThan(50, $left);
        $this->assertGreaterThan(50, $right);
    }

    /**
     * A bbox with minLng > maxLng crosses the antimeridian and draws the same as its corners.
     *
     * @return void
     */
    public function testBboxAcross(): void
    {
        $box = $this->render(['bbox' => '170,-20,-170,0', 'width' => 300, 'height' => 300]);
        $points = $this->render(['visible' => '-20,170|0,-170', 'width' => 300, 'height' => 300]);

        $this->assertSame(200, $box->status);
        $this->assertSame($box->body, $points->body);
        [$zooms] = $this->tileColumns();
        $this->assertGreaterThanOrEqual(3, $zooms[0]);
    }

    /**
     * A centre next to ±180° gets real tiles on both sides instead of gray ones.
     *
     * @return void
     */
    public function testCentreNextToTheAntimeridian(): void
    {
        $response = $this->render(
            ['latitude' => 0, 'longitude' => 179.9, 'zoom' => 3, 'width' => 600, 'height' => 200]
        );

        $this->assertSame(200, $response->status);
        [, $columns] = $this->tileColumns();
        $this->assertContains(0, $columns);
        $this->assertContains(7, $columns);
        // Image x 590 is world x 2337 at zoom 3 (2048 px wide): tile column 1 after wrapping; y 100 is row 4.
        $this->assertPixel(self::tileColor(1, 4), $response->image(), 590, 100);
    }
}

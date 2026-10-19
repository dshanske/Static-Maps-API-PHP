<?php

/**
 * Tests for include/Viewport.php.
 */

namespace StaticMaps\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StaticMaps\Bounds;
use StaticMaps\Viewport;
use StaticMaps\WebMercator;

/**
 * Choosing the centre and zoom.
 */
final class ViewportTest extends TestCase
{
    /**
     * A viewport.
     *
     * @return Viewport The viewport.
     */
    private static function viewport(): Viewport
    {
        return new Viewport(new WebMercator());
    }

    /**
     * Bounds around two Portland points.
     *
     * @return Bounds The bounds.
     */
    private static function portlandBounds(): Bounds
    {
        $bounds = new Bounds();
        $bounds->extend(45.5165, -122.6764);
        $bounds->extend(45.52, -122.68);

        return $bounds;
    }

    /** @var array{int, int, int, int} No margins. */
    private const NONE = [0, 0, 0, 0];

    /**
     * The centre is the given one, else the middle of the bounds in Mercator pixels.
     *
     * @return void
     */
    public function testCenter(): void
    {
        $vp = self::viewport();
        $bounds = new Bounds();
        $bounds->extend(10, 20);
        $bounds->extend(20, 40);

        $this->assertSame(
            ['latitude' => 1.0, 'longitude' => 2.0, 'located' => true],
            $vp->center(1.0, 2.0, 5, $bounds, self::NONE)
        );
        $center = $vp->center(null, null, 5, $bounds, self::NONE);
        $this->assertTrue($center['located']);
        $this->assertEqualsWithDelta(30.0, $center['longitude'], 0.01);
        // The Mercator midpoint of 10..20 is a little north of 15.
        $this->assertGreaterThan(15.0, $center['latitude']);
        $this->assertLessThan(15.2, $center['latitude']);
    }

    /**
     * Asymmetric margins shift the centre: more room on the left moves the centre west.
     *
     * @return void
     */
    public function testCenterWithMargins(): void
    {
        $vp = self::viewport();
        $bounds = self::portlandBounds();
        $plain = $vp->center(null, null, 15, $bounds, self::NONE);
        $left = $vp->center(null, null, 15, $bounds, [0, 0, 0, 100]);

        $this->assertLessThan($plain['longitude'], $left['longitude']);
        $this->assertEqualsWithDelta($plain['latitude'], $left['latitude'], 1e-9);
    }

    /**
     * Without coordinates or any bounds, the map is not located.
     *
     * @return void
     */
    public function testNotLocated(): void
    {
        $this->assertFalse(self::viewport()->center(null, null, 5, new Bounds(), self::NONE)['located']);
    }

    /**
     * A given zoom is returned unchanged.
     *
     * @return void
     */
    public function testGivenZoom(): void
    {
        $vp = self::viewport();
        $this->assertSame(14, $vp->zoom(14, 45.5, -122.6, 300, 300, new Bounds(), self::NONE));
        $this->assertSame(0, $vp->zoom(0, 45.5, -122.6, 300, 300, new Bounds(), self::NONE), 'zoom 0 is a zoom');
    }

    /**
     * Without a zoom, the result is the highest zoom at which the bounds and margins fit (#35).
     *
     * @return void
     */
    public function testFittedZoom(): void
    {
        $vp = self::viewport();
        $bounds = self::portlandBounds();

        $this->assertSame(16, $vp->zoom(null, null, null, 300, 300, $bounds, self::NONE));

        // The bounds really do fit at 16 and not at 17.
        $wm = new WebMercator();
        foreach ([16 => true, 17 => false] as $z => $fits) {
            $sw = $wm->latLngToPixels($bounds->minLat, $bounds->minLng, $z);
            $ne = $wm->latLngToPixels($bounds->maxLat, $bounds->maxLng, $z);
            $this->assertSame($fits, abs($ne['x'] - $sw['x']) <= 300 && abs($ne['y'] - $sw['y']) <= 300, "zoom $z");
        }

        // The content is 167 x 233 px at 16: 40 px above and below no longer fits, 40 at the sides does.
        $this->assertSame(15, $vp->zoom(null, null, null, 300, 300, $bounds, [40, 0, 40, 0]));
        $this->assertSame(16, $vp->zoom(null, null, null, 300, 300, $bounds, [0, 40, 0, 40]));
    }

    /**
     * With a given centre, the content must fit around that centre, not just in the image.
     *
     * @return void
     */
    public function testFittedZoomAroundACentre(): void
    {
        $vp = self::viewport();
        $bounds = self::portlandBounds();

        $this->assertSame(16, $vp->zoom(null, 45.5183, -122.6782, 300, 300, $bounds, self::NONE));
        // Centred on one end, the other end is twice as far: one level lower.
        $this->assertSame(15, $vp->zoom(null, 45.5165, -122.6764, 300, 300, $bounds, self::NONE));
    }

    /**
     * With nothing to fit the zoom is 0, clamped later to the map's minimum.
     *
     * @return void
     */
    public function testFittedZoomWithoutBounds(): void
    {
        $this->assertSame(0, self::viewport()->zoom(null, 0.0, 0.0, 300, 300, new Bounds(), self::NONE));
    }

    /**
     * A curved path widens the bounds to its bulge, on the side the curve goes; straight paths don't.
     *
     * @return void
     */
    public function testCurvesWidenTheBounds(): void
    {
        $paths = [['path' => [[-122.7, 45.5], [-122.6, 45.5]]]];
        $straight = new Bounds();
        $straight->extend(45.5, -122.7);
        $straight->extend(45.5, -122.6);

        $flat = clone $straight;
        self::viewport()->extendForCurves($paths, 0.0, $flat);
        $this->assertEquals($straight, $flat);

        $curved = clone $straight;
        self::viewport()->extendForCurves($paths, 30.0, $curved);
        $this->assertEqualsWithDelta(45.5, $curved->minLat, 1e-6, 'bulges north for +30 (left to right)');
        $this->assertGreaterThan(45.51, $curved->maxLat);
        $this->assertEqualsWithDelta([-122.7, -122.6], [$curved->minLng, $curved->maxLng], 1e-6);

        $other = clone $straight;
        self::viewport()->extendForCurves($paths, -30.0, $other);
        $this->assertLessThan(45.49, $other->minLat, 'bulges south for -30');
    }

    /**
     * Margins add the padding to the largest icon reach on each side and half the widest path.
     *
     * @return void
     */
    public function testMargins(): void
    {
        $pin = imagecreatetruecolor(20, 30);
        $dot = imagecreatetruecolor(10, 10);
        $markers = [
            ['icon' => 'small-red-pin', 'iconImg' => $pin],
            ['icon' => 'dot-small-blue', 'iconImg' => $dot],
        ];
        $paths = [['weight' => 9]];

        // A shadow icon is anchored at its bottom centre: 30 up, 10 each side, 0 down; the dot reaches 5 down.
        $this->assertSame([30, 10, 5, 10], Viewport::margins([0, 0, 0, 0], $markers, [], 300, 300));
        $this->assertSame([50, 30, 25, 30], Viewport::margins([20, 20, 20, 20], $markers, [], 300, 300));
        $this->assertSame([35, 15, 10, 15], Viewport::margins([5, 5, 5, 5], $markers, $paths, 300, 300));
        // Small maps shrink icons by 1.5 (the pin to 13 x 20: 7 left of the anchor, 6 right).
        $this->assertSame([20, 6, 3, 7], Viewport::margins([0, 0, 0, 0], $markers, [], 100, 100));
        $this->assertSame([0, 0, 0, 0], Viewport::margins([0, 0, 0, 0], [], [], 300, 300));
    }

    /**
     * Clamping cases.
     *
     * @return array<string, array{int, int|null, int, int, int}> Zoom, maxzoom, basemap min and max, expected.
     */
    public static function clampCases(): array
    {
        return [
            'in range'            => [12, null, 2, 18, 12],
            'maxzoom lower'       => [14, 9, 2, 18, 9],
            'maxzoom higher'      => [9, 14, 2, 18, 9],
            'maxzoom 0'           => [14, 0, 2, 18, 2],
            'below basemap min'   => [1, null, 2, 18, 2],
            'above basemap max'   => [20, null, 2, 18, 18],
            'maxzoom below min'   => [10, 1, 5, 18, 5],
        ];
    }

    /**
     * clampZoom() applies maxzoom, then the basemap minimum, then its maximum.
     *
     * @param int      $zoom     Zoom level.
     * @param int|null $maxzoom  The checked maxzoom parameter.
     * @param int      $min      Basemap minimum zoom.
     * @param int      $max      Basemap maximum zoom.
     * @param int      $expected Expected result.
     *
     * @return void
     */
    #[DataProvider('clampCases')]
    public function testClampZoom(int $zoom, ?int $maxzoom, int $min, int $max, int $expected): void
    {
        $this->assertSame($expected, self::viewport()->clampZoom($zoom, $maxzoom, $min, $max));
    }
}

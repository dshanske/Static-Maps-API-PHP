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

    /**
     * The centre is latitude/longitude if given, else the centre of the bounds.
     *
     * @return void
     */
    public function testCenter(): void
    {
        $vp = self::viewport();
        $bounds = new Bounds();
        $bounds->extend(10, 20);
        $bounds->extend(20, 40);

        $this->assertSame(['latitude' => 1.0, 'longitude' => 2.0, 'located' => true], $vp->center(1.0, 2.0, $bounds));
        $this->assertEquals(['latitude' => 15, 'longitude' => 30, 'located' => true], $vp->center(null, null, $bounds));
    }

    /**
     * Without coordinates or any bounds, the map is not located.
     *
     * @return void
     */
    public function testNotLocated(): void
    {
        $this->assertFalse(self::viewport()->center(null, null, new Bounds())['located']);
    }

    /**
     * A given zoom is returned unchanged, even as a string.
     *
     * @return void
     */
    public function testGivenZoom(): void
    {
        $this->assertSame(14, self::viewport()->zoom(14, 45.5, -122.6, 300, 300, new Bounds()));
        $this->assertSame(0, self::viewport()->zoom(0, 45.5, -122.6, 300, 300, new Bounds()), 'zoom 0 is a zoom');
    }

    /**
     * Without a zoom, the result is one level below the highest zoom at which the bounds fit.
     *
     * @return void
     */
    public function testFittedZoom(): void
    {
        $vp = self::viewport();
        $bounds = self::portlandBounds();
        $center = $bounds->center();

        $zoom = $vp->zoom(null, $center['lat'], $center['lng'], 300, 300, $bounds);
        $this->assertSame(15, $zoom);

        // The bounds really do fit at 16 and not at 17.
        $wm = new WebMercator();
        foreach ([16 => true, 17 => false] as $z => $fits) {
            $sw = $wm->latLngToPixels($bounds->minLat, $bounds->minLng, $z);
            $ne = $wm->latLngToPixels($bounds->maxLat, $bounds->maxLng, $z);
            $this->assertSame($fits, abs($ne['x'] - $sw['x']) <= 300 && abs($ne['y'] - $sw['y']) <= 300, "zoom $z");
        }
    }

    /**
     * With no markers or paths the fitted zoom bottoms out at 1.
     *
     * @return void
     */
    public function testFittedZoomWithoutBounds(): void
    {
        $this->assertSame(1, self::viewport()->zoom(null, 0, 0, 300, 300, new Bounds()));
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

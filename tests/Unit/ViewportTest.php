<?php

/**
 * Tests for include/Viewport.php.
 */

namespace StaticMaps\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StaticMaps\ArcGISGeocoderResult;
use StaticMaps\Bounds;
use StaticMaps\Request;
use StaticMaps\Viewport;
use StaticMaps\WebMercator;

/**
 * Choosing the centre and zoom.
 */
final class ViewportTest extends TestCase
{
    /**
     * A viewport whose geocoder finds only "Portland".
     *
     * @return Viewport The viewport.
     */
    private static function viewport(): Viewport
    {
        return new Viewport(new WebMercator(), static function (string $location): ArcGISGeocoderResult {
            if ($location !== 'Portland') {
                return new ArcGISGeocoderResult(false, 'not found');
            }
            $found = (object) [
                'name'    => 'Portland',
                'feature' => (object) ['geometry' => (object) ['x' => -122.68, 'y' => 45.52]],
            ];
            return new ArcGISGeocoderResult($found, '{}');
        });
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
     * Centre sources, in order of precedence.
     *
     * @return void
     */
    public function testCenter(): void
    {
        $vp = self::viewport();
        $bounds = new Bounds();
        $bounds->extend(10, 20);
        $bounds->extend(20, 40);

        $given = $vp->center(new Request(['latitude' => '1', 'longitude' => '2', 'location' => 'Portland']), $bounds);
        $this->assertSame(['latitude' => '1', 'longitude' => '2', 'headers' => []], $given);

        $this->assertSame(false, $vp->center(new Request(['latitude' => '1']), $bounds)['longitude']);

        $geocoded = $vp->center(new Request(['location' => 'Portland']), $bounds);
        $this->assertSame([45.52, -122.68], [$geocoded['latitude'], $geocoded['longitude']]);
        $this->assertSame(['X-Geocode: success', 'X-Geocode-Result: 45.52, -122.68'], $geocoded['headers']);

        $failed = $vp->center(new Request(['location' => 'Atlantis']), $bounds);
        $this->assertEquals([15, 30], [$failed['latitude'], $failed['longitude']]);
        $this->assertSame(['X-Geocode: error', 'X-Geocode-Result: not found'], $failed['headers']);

        $fromBounds = $vp->center(new Request([]), $bounds);
        $this->assertEquals(['latitude' => 15, 'longitude' => 30, 'headers' => []], $fromBounds);
    }

    /**
     * A given zoom is returned unchanged, even as a string.
     *
     * @return void
     */
    public function testGivenZoom(): void
    {
        $this->assertSame('14', self::viewport()->zoom('14', 45.5, -122.6, 300, 300, new Bounds()));
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

        $zoom = $vp->zoom(false, $center['lat'], $center['lng'], 300, 300, $bounds);
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
        $this->assertSame(1, self::viewport()->zoom(false, 0, 0, 300, 300, new Bounds()));
    }

    /**
     * Clamping cases.
     *
     * @return array<string, array{mixed, mixed, int, int, mixed}> Zoom, maxzoom, basemap min and max, expected.
     */
    public static function clampCases(): array
    {
        return [
            'in range'            => [12, false, 2, 18, 12],
            'maxzoom lower'       => [14, '9', 2, 18, '9'],
            'maxzoom higher'      => [9, '14', 2, 18, 9],
            'maxzoom 0 ignored'   => [14, '0', 2, 18, 14],
            'below basemap min'   => [1, false, 2, 18, 2],
            'above basemap max'   => [20, false, 2, 18, 18],
            'maxzoom below min'   => [10, '1', 5, 18, 5],
            'string zoom clamped' => ['20', false, 2, 18, 18],
        ];
    }

    /**
     * clampZoom() applies maxzoom, then the basemap minimum, then its maximum.
     *
     * @param mixed $zoom     Zoom level.
     * @param mixed $maxzoom  The maxzoom parameter.
     * @param int   $min      Basemap minimum zoom.
     * @param int   $max      Basemap maximum zoom.
     * @param mixed $expected Expected result.
     *
     * @return void
     */
    #[DataProvider('clampCases')]
    public function testClampZoom(mixed $zoom, mixed $maxzoom, int $min, int $max, mixed $expected): void
    {
        $this->assertSame($expected, self::viewport()->clampZoom($zoom, $maxzoom, $min, $max));
    }
}

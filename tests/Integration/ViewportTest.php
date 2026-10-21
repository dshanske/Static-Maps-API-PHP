<?php

/**
 * What area a map image shows: headers and /api/v1/plan (#101).
 */

namespace StaticMaps\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use StaticMaps\Tests\Support\RenderTestCase;

/**
 * X-Map-Center, X-Map-Zoom and X-Map-Bounds on map images, and the same
 * numbers from /api/v1/plan.
 */
final class ViewportTest extends RenderTestCase
{
    /**
     * Longitude of a world pixel x at a zoom (Web Mercator, 256 px tiles), wrapped into -180..180.
     *
     * @param float $x    World pixel.
     * @param int   $zoom Zoom level.
     *
     * @return float Degrees.
     */
    private static function lng(float $x, int $zoom): float
    {
        $lng = $x / (256 * 2 ** $zoom) * 360 - 180;

        return fmod($lng + 540, 360) - 180;
    }

    /**
     * Latitude of a world pixel y at a zoom.
     *
     * @param float $y    World pixel.
     * @param int   $zoom Zoom level.
     *
     * @return float Degrees.
     */
    private static function lat(float $y, int $zoom): float
    {
        return rad2deg(atan(sinh(M_PI * (1 - 2 * $y / (256 * 2 ** $zoom)))));
    }

    /**
     * The edges of a map, computed independently of the renderer.
     *
     * @param float $lat    Centre latitude.
     * @param float $lng    Centre longitude.
     * @param int   $zoom   Zoom level.
     * @param int   $width  Width in pixels.
     * @param int   $height Height in pixels.
     *
     * @return list<float> minLng, minLat, maxLng, maxLat.
     */
    private static function edges(float $lat, float $lng, int $zoom, int $width, int $height): array
    {
        $world = 256 * 2 ** $zoom;
        $x = ($lng + 180) / 360 * $world;
        $y = (1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / M_PI) / 2 * $world;

        return [
            self::lng($x - $width / 2, $zoom),
            self::lat($y + $height / 2, $zoom),
            self::lng($x + $width / 2, $zoom),
            self::lat($y - $height / 2, $zoom),
        ];
    }

    /**
     * A header's comma-separated numbers.
     *
     * @param string|null $value The header value.
     *
     * @return list<float> The numbers.
     */
    private static function numbers(?string $value): array
    {
        return array_map('floatval', explode(',', (string) $value));
    }

    /**
     * Centred maps: where they are, across the antimeridian too.
     *
     * @return array<string, array{float, float, int, int, int}> Latitude, longitude, zoom, width, height.
     */
    public static function centredMaps(): array
    {
        return [
            'portland'          => [45.5, -122.6, 10, 400, 300],
            'square, low zoom'  => [10.0, 20.0, 3, 256, 256],
            'across 180'        => [-17.7, 179.9, 6, 600, 300],
            'southern'          => [-33.86, 151.21, 12, 300, 500],
        ];
    }

    /**
     * The headers name the centre, zoom and edges of a centred map.
     *
     * @param float $lat    Centre latitude.
     * @param float $lng    Centre longitude.
     * @param int   $zoom   Zoom level.
     * @param int   $width  Width in pixels.
     * @param int   $height Height in pixels.
     *
     * @return void
     */
    #[DataProvider('centredMaps')]
    public function testCentredMap(float $lat, float $lng, int $zoom, int $width, int $height): void
    {
        $query = ['latitude' => $lat, 'longitude' => $lng, 'zoom' => $zoom, 'width' => $width, 'height' => $height];
        $response = $this->render($query);

        $this->assertSame(200, $response->status);
        $this->assertEqualsWithDelta([$lat, $lng], self::numbers($response->header('X-Map-Center')), 1e-6);
        $this->assertSame((string) $zoom, $response->header('X-Map-Zoom'));
        $bounds = self::numbers($response->header('X-Map-Bounds'));
        // The image is drawn round the centre's whole pixel, so its edges are within a pixel of these.
        $pixel = 360 / (256 * 2 ** $zoom);
        $this->assertEqualsWithDelta(self::edges($lat, $lng, $zoom, $width, $height), $bounds, $pixel);
        if ($lng + $width / 2 * 360 / (256 * 2 ** $zoom) > 180) {
            $this->assertGreaterThan($bounds[2], $bounds[0], 'west > east across the antimeridian');
        }
    }

    /**
     * A fitted map reports the zoom and centre it chose, and its markers are inside its edges.
     *
     * @return void
     */
    public function testFittedMap(): void
    {
        $response = $this->render(['width' => 400, 'height' => 300, 'marker' => [
            'lat:45.52;lng:-122.68;icon:pin',
            'lat:45.50;lng:-122.64;icon:pin',
        ]]);

        $zoom = (int) $response->header('X-Map-Zoom');
        $this->assertGreaterThan(10, $zoom);
        [$west, $south, $east, $north] = self::numbers($response->header('X-Map-Bounds'));
        foreach ([[45.52, -122.68], [45.50, -122.64]] as [$lat, $lng]) {
            $this->assertTrue($lng > $west && $lng < $east && $lat > $south && $lat < $north);
        }
    }

    /**
     * scale=2 covers the same area as scale 1.
     *
     * @return void
     */
    public function testScaleTwoSameArea(): void
    {
        $query = ['latitude' => 45.5, 'longitude' => -122.6, 'zoom' => 10, 'width' => 300, 'height' => 200];
        $one = $this->render($query);
        $two = $this->render($query + ['scale' => 2]);

        foreach (['X-Map-Center', 'X-Map-Zoom', 'X-Map-Bounds'] as $name) {
            $this->assertSame($one->header($name), $two->header($name), $name);
        }
    }

    /**
     * An image wider than the world spans all longitudes.
     *
     * @return void
     */
    public function testWiderThanTheWorld(): void
    {
        // The fixture map's lowest zoom is 2, where the world is 1024 pixels wide.
        $response = $this->render(['latitude' => 0, 'longitude' => 0, 'zoom' => 2, 'width' => 1200, 'height' => 200]);

        $bounds = self::numbers($response->header('X-Map-Bounds'));
        $this->assertSame([-180.0, 180.0], [$bounds[0], $bounds[2]]);
    }

    /**
     * /api/v1/plan gives the image's numbers and where its markers are, and fetches nothing.
     *
     * @return void
     */
    public function testPlanMatchesTheImage(): void
    {
        $query = http_build_query(['width' => 400, 'height' => 300, 'overlay' => 'squares', 'marker' => [
            'lat:45.52;lng:-122.68;icon:pin',
            'nonsense',
            'lat:45.50;lng:-122.64;icon:dot-small-blue',
        ]]);
        $query = (string) preg_replace('/%5B\d+%5D/', '%5B%5D', $query);
        $image = self::$app->get("/?$query");
        file_put_contents(self::$tileLog, '');

        $response = self::$app->get("/api/v1/plan?$query");
        $plan = json_decode($response->body, true);

        $this->assertSame(200, $response->status);
        $this->assertSame([], $this->tileRequests(), 'no tiles fetched');
        $this->assertSame(['fixture', 'base', null], [$plan['provider'], $plan['style'], $plan['fallback']]);
        $this->assertSame(['squares'], $plan['overlays']);
        $this->assertSame([400, 300, 1], [$plan['width'], $plan['height'], $plan['scale']]);
        $this->assertSame($image->header('X-Map-Zoom'), (string) $plan['zoom']);
        $center = $plan['center'];
        $this->assertEqualsWithDelta(
            self::numbers($image->header('X-Map-Center')),
            [$center['latitude'], $center['longitude']],
            1e-6
        );
        $this->assertEqualsWithDelta(self::numbers($image->header('X-Map-Bounds')), $plan['bounds'], 1e-6);

        // Markers 1 and 3 (2 isn't a marker), at their anchors inside the image.
        $this->assertSame([1, 3], array_column($plan['markers'], 'n'));
        foreach ($plan['markers'] as $marker) {
            $this->assertTrue($marker['x'] > 0 && $marker['x'] < 400 && $marker['y'] > 0 && $marker['y'] < 300);
            [$left, $top, $right, $bottom] = $marker['box'];
            $this->assertTrue($left <= $marker['x'] && $marker['x'] <= $right);
            $this->assertTrue($top <= $marker['y'] && $marker['y'] <= $bottom);
        }
        // A pin is anchored at its bottom centre, a dot at its centre.
        [$pin, $dot] = $plan['markers'];
        $this->assertSame($pin['y'], $pin['box'][3]);
        $this->assertEqualsWithDelta(($dot['box'][1] + $dot['box'][3]) / 2, $dot['y'], 1);
    }

    /**
     * A marker at the centre of a centred map is at the image's centre.
     *
     * @return void
     */
    public function testMarkerAtTheCentre(): void
    {
        $response = self::$app->get(
            '/api/v1/plan?latitude=45.5&longitude=-122.6&zoom=12&width=300&height=200'
            . '&marker[]=lat:45.5;lng:-122.6;icon:pin'
        );
        $marker = json_decode($response->body, true)['markers'][0];

        $this->assertSame([150, 100], [$marker['x'], $marker['y']]);
    }

    /**
     * A request the image would refuse gets the same 400 from /api/v1/plan.
     *
     * @return void
     */
    public function testPlanErrors(): void
    {
        $response = self::$app->get('/api/v1/plan?width=400');
        $this->assertSame(400, $response->status);
        $this->assertStringStartsWith('A map needs a location', json_decode($response->body, true)['error']);

        $response = self::$app->get('/api/v1/plan?latitude=45&longitude=-122&zoom=99');
        $this->assertSame(400, $response->status);
        $this->assertStringContainsString('zoom', json_decode($response->body, true)['error']);
    }

    /**
     * A regional map outside its area: the plan names the map drawn, and passes on the notice.
     *
     * @return void
     */
    public function testPlanFallback(): void
    {
        $response = self::$app->get('/api/v1/plan?style=regional&latitude=35.68&longitude=139.76&zoom=10');
        $plan = json_decode($response->body, true);

        $this->assertSame('base', $plan['style']);
        $this->assertStringStartsWith('outside;', (string) $plan['fallback']);
        $this->assertSame($plan['fallback'], $response->header('X-Map-Fallback'));
    }
}

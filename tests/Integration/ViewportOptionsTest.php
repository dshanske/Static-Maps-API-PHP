<?php

/**
 * center, bbox, visible and padding (#35).
 */

namespace StaticMaps\Tests\Integration;

use StaticMaps\Tests\Support\RenderTestCase;

/**
 * The map area set by `center`, `bbox`, `visible` and `padding`.
 */
final class ViewportOptionsTest extends RenderTestCase
{
    /**
     * `center=lat,lng` draws the same map as latitude and longitude.
     *
     * @return void
     */
    public function testCenterShorthand(): void
    {
        $long = $this->render(['latitude' => '45.5165', 'longitude' => '-122.6764', 'zoom' => 14]);
        $short = $this->render(['center' => '45.5165,-122.6764', 'zoom' => 14]);

        $this->assertSame(200, $short->status);
        $this->assertSame($long->body, $short->body);
    }

    /**
     * Invalid or conflicting values get a 400, before any tile is fetched.
     *
     * @return void
     */
    public function testInvalidValues(): void
    {
        $cases = [
            [['center' => '45.5', 'zoom' => 3], 'center must be lat,lng'],
            [['center' => '95,1', 'zoom' => 3], 'center must be lat,lng'],
            [['center' => '1,2|3,4', 'zoom' => 3], 'center must be one lat,lng point'],
            [['center' => '1,2', 'latitude' => 1, 'longitude' => 2], 'give center or latitude and longitude, not both'],
            [['bbox' => '1,2,3'], 'bbox must be minLng,minLat,maxLng,maxLat'],
            [['bbox' => '-122,46,-121,45'], 'bbox must be minLng,minLat,maxLng,maxLat'],
            [['visible' => '45.5,-122.6|nope'], 'visible must be lat,lng points separated by |'],
            [['center' => '1,2', 'padding' => '1,2,3,4,5'], 'padding must be 1 to 4'],
            [['center' => '1,2', 'padding' => '-5'], 'padding must be 1 to 4'],
        ];
        foreach ($cases as [$query, $message]) {
            $response = $this->render($query);
            $this->assertSame(400, $response->status, $message);
            $this->assertStringStartsWith($message, $response->body);
        }
        $this->assertSame([], $this->tileRequests());
    }

    /**
     * `bbox` alone places the map: the box fills the image as far as the zoom allows.
     *
     * @return void
     */
    public function testBbox(): void
    {
        $box = $this->render(['bbox' => '-122.70,45.50,-122.65,45.53', 'width' => 300, 'height' => 300]);
        $points = $this->render([
            'visible' => '45.50,-122.70|45.53,-122.65',
            'width'   => 300,
            'height'  => 300,
        ]);

        $this->assertSame(200, $box->status);
        $this->assertSame($box->body, $points->body, 'a bbox is the same as its corners as visible points');
        // 0.05 degrees of longitude is 291 px at 13: with 20 px padding on each side that's
        // too wide for 300 px, so 12.
        $this->assertSame(['/base/12/'], array_values(array_unique(array_map(
            static fn (string $p): string => substr($p, 0, 9),
            $this->tilePaths()
        ))));
    }

    /**
     * `visible` widens the fit around markers without drawing anything there.
     *
     * @return void
     */
    public function testVisible(): void
    {
        $marker = ['marker' => ['lat:45.5165;lng:-122.6764;icon:dot-small-red']];
        $alone = $this->render($marker + ['width' => 300, 'height' => 300]);
        $wider = $this->render($marker + ['visible' => '45.60,-122.50', 'width' => 300, 'height' => 300]);

        $this->assertNotSame($alone->body, $wider->body);
        $this->assertSame(200, $wider->status);
    }

    /**
     * Padding zooms out when the content would come too close to the edge, and shifts the
     * centre when it's uneven; `padding=0` fits tighter than the 20 px default.
     *
     * @return void
     */
    public function testPadding(): void
    {
        $path = ['path' => ['[-122.70,45.50][-122.66,45.52]'], 'width' => 300, 'height' => 300];

        $zoom = function (array $query): int {
            $this->render($query);
            $paths = $this->tilePaths();
            $this->assertNotSame([], $paths);

            return (int) explode('/', $paths[0])[2];
        };
        $none = $zoom($path + ['padding' => '0']);
        $default = $zoom($path);
        $wide = $zoom($path + ['padding' => '100']);

        // The path is 233 x 167 px at 13: it fits with 0 or 20 px, not with 100.
        $this->assertSame([13, 13, 11], [$none, $default, $wide]);

        $even = $this->render($path + ['padding' => '20']);
        $uneven = $this->render($path + ['padding' => '20,20,20,120']);
        $this->assertNotSame($even->body, $uneven->body, 'more room on the left moves the map');
    }
}

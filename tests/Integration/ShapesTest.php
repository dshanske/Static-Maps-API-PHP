<?php

/**
 * Polygons, circles, rectangles, line styles and opacity (#36).
 */

namespace StaticMaps\Tests\Integration;

use StaticMaps\Tests\Support\RenderTestCase;

/**
 * Shapes drawn on the fixture tiles.
 */
final class ShapesTest extends RenderTestCase
{
    /** @var array<string, mixed> A map in Portland at zoom 13. */
    private const MAP = [
        'latitude'  => 45.5165,
        'longitude' => -122.6764,
        'zoom'      => 13,
        'width'     => 400,
        'height'    => 300,
    ];

    /**
     * One of each, drawn as in the reference image.
     *
     * @return void
     */
    public function testShapes(): void
    {
        $response = $this->render(self::MAP + ['path' => [
            // A filled triangle with a translucent fill.
            'color:000000;weight:2;fillcolor:ff000080;[-122.72,45.53][-122.70,45.50][-122.68,45.53]',
            // A 1 km circle, outline only.
            'color:0000ff;weight:3;circle:-122.65,45.525,1000',
            // A dashed rectangle with a light fill.
            'color:008800;weight:3;linestyle:dashed;fillcolor:00ff0030;rect:-122.66,45.49,-122.62,45.505',
            // A dotted, half-transparent line.
            'color:ff00ff80;weight:5;linestyle:dotted;[-122.73,45.50][-122.62,45.54]',
        ]]);

        $this->assertSame(200, $response->status);
        $this->assertSame([], array_values(array_filter(
            $response->headers,
            static fn (string $h): bool => stripos($h, 'X-Path') === 0
        )));
        $this->assertMatchesSnapshot('shapes.png', $response->body);

        $im = $response->image();
        // Inside the triangle (tile 1303/2930) the tile colour is mixed with red at 0x80/0xff opacity.
        $tile = self::tileColor(1303, 2930);
        $alpha = 0x80 / 0xff;
        $expected = array_map(
            static fn (int $t, int $red): int => (int) round($t * (1 - $alpha) + $red * $alpha),
            $tile,
            [255, 0, 0]
        );
        $this->assertPixel($expected, $im, 60, 120, 2);
    }

    /**
     * A geodesic path and a polyline next to path[] are drawn; bad style values are left out with a header
     * (they used to stop the whole map with an error).
     *
     * @return void
     */
    public function testMixedAndInvalid(): void
    {
        $response = $this->render([
            'path'     => ['color:zzz;[-122.68,45.51][-122.67,45.52]', 'weight:abc;[-122.68,45.51][-122.67,45.52]'],
            'polyline' => 'enc:_p~iF~ps|U_ulLnnqC;color:ff0000',
            'width'    => 300,
            'height'   => 300,
        ]);

        $this->assertSame(200, $response->status);
        $this->assertSame('color must be RRGGBB or RRGGBBAA (hex)', $response->header('X-Path-1'));
        $this->assertSame('weight must be a number of pixels from 0 to 100', $response->header('X-Path-2'));
        $this->assertNull($response->header('X-Path-3'), 'the polyline is drawn');
    }
}

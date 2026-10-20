<?php

/**
 * Tests for include/PathParser.php.
 */

namespace StaticMaps\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StaticMaps\Bounds;
use StaticMaps\PathParser;
use StaticMaps\Polyline;

/**
 * Parsing `path[]` and `polyline` values.
 */
final class PathParserTest extends TestCase
{
    /**
     * Points are [lng,lat] pairs; missing style comes from the defaults.
     *
     * @return void
     */
    public function testPathWithDefaults(): void
    {
        $bounds = new Bounds();
        $paths = (new PathParser('333333', 3))->parse(['[-122.68,45.51][-122.67,45.52]'], false, $bounds)['paths'];

        $this->assertSame([[
            'color'     => '333333',
            'weight'    => 3,
            'path'      => [[-122.68, 45.51], [-122.67, 45.52]],
            'closed'    => false,
            'fill'      => null,
            'linestyle' => 'solid',
            'geodesic'  => false,
        ]], $paths);
        $this->assertSame([45.51, 45.52], [$bounds->minLat, $bounds->maxLat]);
        $this->assertSame([-122.68, -122.67], [$bounds->minLng, $bounds->maxLng]);
    }

    /**
     * Style pairs override the defaults; a single path may be given without [].
     *
     * @return void
     */
    public function testStyledPath(): void
    {
        $paths = (new PathParser('333333', 3))->parse('color:ff0000;weight:6;[1,2][3,4]', false, new Bounds())['paths'];

        $this->assertSame('ff0000', $paths[0]['color']);
        $this->assertSame('6', $paths[0]['weight']);
    }

    /**
     * A path without points is left out.
     *
     * @return void
     */
    public function testPathWithoutPoints(): void
    {
        $this->assertSame([], (new PathParser('333333', 3))->parse(['color:ff0000'], false, new Bounds())['paths']);
    }

    /**
     * Paths with unreadable or out-of-range points are left out with an X-Path header (#9).
     *
     * @return void
     */
    public function testInvalidPathsAreReported(): void
    {
        $bounds = new Bounds();
        $result = (new PathParser('333333', 3))->parse(
            ['[1.2.3,4][5,6]', '[-122.6,45.5][-122.7,45.6]', '[10,95][11,96]'],
            false,
            $bounds
        );

        $this->assertCount(1, $result['paths']);
        $this->assertSame([
            'X-Path-1: points must be [lng,lat] numbers in range',
            'X-Path-3: points must be [lng,lat] numbers in range',
        ], $result['headers']);
        $this->assertSame([45.5, 45.6], [$bounds->minLat, $bounds->maxLat]);
    }

    /**
     * A polyline is read in Google's lat,lng order and turned into [lng,lat] path points (#43).
     *
     * @return void
     */
    public function testPolyline(): void
    {
        $bounds = new Bounds();
        $encoded = Polyline::encode([[45.51, -122.68], [45.52, -122.67]]);
        $paths = (new PathParser('333333', 3))->parse(false, "enc:$encoded;weight:5", $bounds)['paths'];

        $this->assertCount(1, $paths);
        $this->assertSame('5', $paths[0]['weight']);
        $this->assertSame('333333', $paths[0]['color']);
        $this->assertEqualsWithDelta([[-122.68, 45.51], [-122.67, 45.52]], $paths[0]['path'], 1e-9);
        $this->assertEqualsWithDelta(45.51, $bounds->minLat, 1e-9);
    }

    /**
     * Google's documented example polyline lands in California and Oregon.
     *
     * @see https://developers.google.com/maps/documentation/utilities/polylinealgorithm
     *
     * @return void
     */
    public function testGoogleExamplePolyline(): void
    {
        $bounds = new Bounds();
        $paths = (new PathParser('333333', 3))->parse(false, 'enc:_p~iF~ps|U_ulLnnqC_mqNvxq`@', $bounds)['paths'];

        $this->assertEqualsWithDelta([[-120.2, 38.5], [-120.95, 40.7], [-126.453, 43.252]], $paths[0]['path'], 1e-9);
        $this->assertEqualsWithDelta([38.5, 43.252], [$bounds->minLat, $bounds->maxLat], 1e-9);
        $this->assertEqualsWithDelta([-126.453, -120.2], [$bounds->minLng, $bounds->maxLng], 1e-9);
    }

    /**
     * A polyline without enc: is left out; with path[] it is drawn too, after them (#36).
     *
     * @return void
     */
    public function testPolylineRules(): void
    {
        $parser = new PathParser('333333', 3);

        $none = $parser->parse(false, 'color:ff0000', new Bounds());
        $this->assertSame([], $none['paths']);
        $this->assertSame(['X-Path-1: no points (expected enc:<encoded polyline>)'], $none['headers']);

        $both = $parser->parse(['[1,2][3,4]', 'nothing'], 'enc:_p~iF~ps|U', new Bounds());
        $this->assertCount(2, $both['paths']);
        $this->assertSame([[1, 2], [3, 4]], $both['paths'][0]['path']);
        $this->assertEqualsWithDelta([[-120.2, 38.5]], $both['paths'][1]['path'], 1e-9);
        $this->assertSame(
            ['X-Path-2: no points (expected [lng,lat] pairs, enc, circle or rect)'],
            $both['headers'],
            'the polyline is number 3'
        );
    }

    /**
     * enc: works inside path[], at precision 5 or 6 (#36).
     *
     * @return void
     */
    public function testEncodedPaths(): void
    {
        $parser = new PathParser('333333', 3);
        $five = $parser->parse(['enc:_p~iF~ps|U_ulLnnqC'], false, new Bounds())['paths'];
        $this->assertEqualsWithDelta([[-120.2, 38.5], [-120.95, 40.7]], $five[0]['path'], 1e-9);

        // The same digits read with six decimal places: ten times smaller.
        $six = $parser->parse(['enc:_p~iF~ps|U_ulLnnqC;precision:6'], false, new Bounds())['paths'];
        $this->assertEqualsWithDelta([[-12.02, 3.85], [-12.095, 4.07]], $six[0]['path'], 1e-9);
    }

    /**
     * Opacity, fill, line style and validation of style values (#36).
     *
     * @return void
     */
    public function testStyles(): void
    {
        $parser = new PathParser('333333', 3);
        $result = $parser->parse([
            'color:ff000080;fillcolor:00ff0040;weight:0;linestyle:dashed;[1,2][3,4][5,2]',
            'color:red;[1,2][3,4]',
            'fillcolor:12345;[1,2][3,4]',
            'weight:200;[1,2][3,4]',
            'weight:abc;[1,2][3,4]',
            'linestyle:wavy;[1,2][3,4]',
            'geodesic:maybe;[1,2][3,4]',
            'enc:_p~iF~ps|U;precision:7',
            '[1,2][3,4];enc:_p~iF~ps|U',
        ], false, new Bounds());

        $path = $result['paths'][0];
        $this->assertSame(['ff000080', '00ff0040', '0', 'dashed', true], [
            $path['color'],
            $path['fill'],
            $path['weight'],
            $path['linestyle'],
            $path['closed'],
        ]);
        $this->assertCount(1, $result['paths']);
        $this->assertSame([
            'X-Path-2: color must be RRGGBB or RRGGBBAA (hex)',
            'X-Path-3: fillcolor must be RRGGBB or RRGGBBAA (hex)',
            'X-Path-4: weight must be a number of pixels from 0 to 100',
            'X-Path-5: weight must be a number of pixels from 0 to 100',
            'X-Path-6: linestyle must be solid, dashed or dotted',
            'X-Path-7: geodesic must be true or false',
            'X-Path-8: precision must be 5 or 6',
            'X-Path-9: use one of [lng,lat] points, enc, circle or rect',
        ], $result['headers']);
    }

    /**
     * A circle is a closed ring of points at the radius from its centre (#36).
     *
     * @return void
     */
    public function testCircle(): void
    {
        $bounds = new Bounds();
        $result = (new PathParser('333333', 3))->parse(
            ['circle:-122.6,45.5,1000', 'circle:1,2', 'circle:1,2,-5', 'circle:1,95,10'],
            false,
            $bounds
        );

        $circle = $result['paths'][0];
        $this->assertTrue($circle['closed']);
        $this->assertCount(72, $circle['path']);
        // 1 km north is 0.008993 degrees of latitude.
        $this->assertEqualsWithDelta(45.5 + 0.008993, $circle['path'][0][1], 1e-5);
        $this->assertEqualsWithDelta(-122.6, $circle['path'][0][0], 1e-9);
        $this->assertEqualsWithDelta(45.5 - 0.008993, $bounds->minLat, 1e-5);
        $this->assertCount(3, $result['headers']);
        $this->assertStringContainsString('circle must be lng,lat,radius', $result['headers'][0]);
    }

    /**
     * A rect is closed, and one across the antimeridian goes the short way (#36, #35).
     *
     * @return void
     */
    public function testRect(): void
    {
        $bounds = new Bounds();
        $result = (new PathParser('333333', 3))->parse(['rect:170,-20,-170,0', 'rect:1,5,2,4'], false, $bounds);

        $this->assertTrue($result['paths'][0]['closed']);
        $this->assertEqualsWithDelta([170.0, 190.0], $bounds->span(), 1e-9);
        $this->assertSame([-20.0, 0.0], [$bounds->minLat, $bounds->maxLat]);
        $this->assertStringContainsString('rect must be', $result['headers'][0]);
    }

    /**
     * geodesic:true follows the great circle: London to Los Angeles bulges north, to 62.36 N.
     *
     * @return void
     */
    public function testGeodesic(): void
    {
        $bounds = new Bounds();
        $paths = (new PathParser('333333', 3))->parse(
            ['geodesic:true;[-0.1,51.5][-118.2,34.05]'],
            false,
            $bounds
        )['paths'];

        $points = $paths[0]['path'];
        $this->assertGreaterThan(50, count($points));
        $this->assertEqualsWithDelta([-0.1, 51.5], $points[0], 1e-9);
        $this->assertEqualsWithDelta([-118.2, 34.05], end($points), 1e-9);
        // The great circle's highest point (from its initial bearing, 311.8 degrees) is 62.36 N.
        $this->assertEqualsWithDelta(62.36, $bounds->maxLat, 0.05);
    }
}

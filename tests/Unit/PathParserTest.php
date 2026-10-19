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
            'color'  => '333333',
            'weight' => 3,
            'path'   => [[-122.68, 45.51], [-122.67, 45.52]],
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
     * A polyline is decoded into [lng,lat] pairs in the order they were encoded (#43).
     *
     * @return void
     */
    public function testPolyline(): void
    {
        $bounds = new Bounds();
        $encoded = Polyline::encode([[-122.68, 45.51], [-122.67, 45.52]]);
        $paths = (new PathParser('333333', 3))->parse(false, "enc:$encoded;weight:5", $bounds)['paths'];

        $this->assertCount(1, $paths);
        $this->assertSame('5', $paths[0]['weight']);
        $this->assertSame('333333', $paths[0]['color']);
        $this->assertEqualsWithDelta([[-122.68, 45.51], [-122.67, 45.52]], $paths[0]['path'], 1e-9);
        $this->assertEqualsWithDelta(45.51, $bounds->minLat, 1e-9);
    }

    /**
     * A polyline without enc: is left out, and path[] takes precedence over polyline.
     *
     * @return void
     */
    public function testPolylineRules(): void
    {
        $parser = new PathParser('333333', 3);

        $this->assertSame([], $parser->parse(false, 'color:ff0000', new Bounds())['paths']);
        $paths = $parser->parse(['[1,2][3,4]'], 'enc:_p~iF~ps|U', new Bounds())['paths'];
        $this->assertSame([[1, 2], [3, 4]], $paths[0]['path']);
        $this->assertCount(1, $paths);
    }
}

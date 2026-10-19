<?php

/**
 * Tests for include/MarkerParser.php.
 */

namespace StaticMaps\Tests\Unit;

use GdImage;
use PHPUnit\Framework\TestCase;
use StaticMaps\Bounds;
use StaticMaps\MarkerParser;

/**
 * Parsing `marker[]` values.
 */
final class MarkerParserTest extends TestCase
{
    /**
     * A parser.
     *
     * @return MarkerParser The parser.
     */
    private static function parser(): MarkerParser
    {
        return new MarkerParser();
    }

    /**
     * The edges of a box, for compact assertions.
     *
     * @param Bounds $bounds The box.
     *
     * @return list<mixed> minLat, maxLat, minLng, maxLng.
     */
    private static function edges(Bounds $bounds): array
    {
        return [$bounds->minLat, $bounds->maxLat, $bounds->minLng, $bounds->maxLng];
    }

    /**
     * No marker parameter means no markers and no headers.
     *
     * @return void
     */
    public function testNoMarkers(): void
    {
        $bounds = new Bounds();

        $this->assertSame(
            ['markers' => [], 'headers' => [], 'deprecated' => false],
            self::parser()->parse(false, false, $bounds)
        );
        $this->assertSame(90, $bounds->minLat);
    }

    /**
     * Markers keep their key:value pairs, get their icon loaded and widen the bounds.
     *
     * @return void
     */
    public function testValidMarkers(): void
    {
        $bounds = new Bounds();
        $result = self::parser()->parse(
            ['lat:45.5;lng:-122.6;icon:small-blue-cutout', 'lat:45.6;lng:-122.7;icon:dot-small-red;extra:kept'],
            false,
            $bounds
        );

        $this->assertSame([], $result['headers']);
        $this->assertCount(2, $result['markers']);
        $this->assertSame('small-blue-cutout', $result['markers'][0]['icon']);
        $this->assertSame(45.5, $result['markers'][0]['lat']);
        $this->assertInstanceOf(GdImage::class, $result['markers'][0]['iconImg']);
        $icon = $result['markers'][0]['iconImg'];
        $this->assertSame([31, 43], [imagesx($icon), imagesy($icon)]);
        $this->assertSame('kept', $result['markers'][1]['extra']);
        $this->assertSame([45.5, 45.6, -122.7, -122.6], self::edges($bounds));
    }

    /**
     * A single marker may be given without [].
     *
     * @return void
     */
    public function testSingleString(): void
    {
        $result = self::parser()->parse('lat:1;lng:2;icon:dot-small-red', false, new Bounds());

        $this->assertCount(1, $result['markers']);
    }

    /**
     * Definitions without an icon or a position are skipped with a header; the number counts from 1.
     *
     * @return void
     */
    public function testInvalidMarkers(): void
    {
        $bounds = new Bounds();
        $markers = ['lat:1;lng:2;icon:dot-small-red', 'lat:1;lng:2', 'icon:dot-small-red;lat:1'];
        $result = self::parser()->parse($markers, false, $bounds);

        $this->assertCount(1, $result['markers']);
        $this->assertSame([
            'X-Marker-2: missing icon, or lat/lng parameters',
            'X-Marker-3: missing icon, or lat/lng parameters',
        ], $result['headers']);
    }

    /**
     * Strings without any key:value pair are ignored silently.
     *
     * @return void
     */
    public function testGarbageIsIgnored(): void
    {
        $result = self::parser()->parse(['nothing here'], false, new Bounds());

        $this->assertSame(['markers' => [], 'headers' => [], 'deprecated' => false], $result);
    }

    /**
     * `location` is no longer geocoded (#16): a marker with only a location is
     * skipped with a header, one that also has lat/lng uses them, and both are
     * reported as deprecated.
     *
     * @return void
     */
    public function testLocationIsIgnored(): void
    {
        $bounds = new Bounds();
        $markers = ['location:Portland;icon:dot-small-red', 'location:Portland;lat:10;lng:20;icon:dot-small-red'];
        $result = self::parser()->parse($markers, false, $bounds);

        $this->assertTrue($result['deprecated']);
        $this->assertSame(['X-Marker-1: location is no longer supported, use lat and lng'], $result['headers']);
        $this->assertCount(1, $result['markers']);
        $this->assertSame([10.0, 20.0], [$result['markers'][0]['lat'], $result['markers'][0]['lng']]);
        $this->assertSame([10.0, 10.0, 20.0, 20.0], self::edges($bounds));
    }

    /**
     * An icon URL without a valid token is not fetched: the marker is left out,
     * but it still widens the bounds.
     *
     * @return void
     */
    public function testIconUrlNeedsToken(): void
    {
        $bounds = new Bounds();
        $result = self::parser()->parse(['lat:10;lng:20;icon:https://icons.invalid/pin.png'], false, $bounds);

        $this->assertSame([], $result['markers']);
        $this->assertSame([10.0, 10.0, 20.0, 20.0], self::edges($bounds));
        $this->assertSame(['X-Marker-1: icon URLs need a valid key'], $result['headers']);
    }

    /**
     * Markers with coordinates that aren't numbers, or are out of range, are skipped (#9).
     *
     * @return void
     */
    public function testInvalidCoordinatesAreReported(): void
    {
        $bounds = new Bounds();
        $markers = [
            'lat:abc;lng:2;icon:dot-small-red',
            'lat:86;lng:2;icon:dot-small-red',
            'lat:1;lng:181;icon:dot-small-red',
        ];
        $result = self::parser()->parse($markers, false, $bounds);

        $this->assertSame([], $result['markers']);
        $this->assertCount(3, $result['headers']);
        $this->assertStringStartsWith('X-Marker-1: lat and lng must be numbers in range', $result['headers'][0]);
        $this->assertTrue($bounds->isEmpty());
    }
}

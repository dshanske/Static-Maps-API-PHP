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
     * Values without any key:value pair, or that aren't strings, are skipped with a header (#39).
     *
     * @return void
     */
    public function testGarbageIsReported(): void
    {
        $result = self::parser()->parse(['nothing here', ['nested']], false, new Bounds());

        $this->assertSame([], $result['markers']);
        $this->assertSame([
            'X-Marker-1: not a marker (key:value pairs separated by ;)',
            'X-Marker-2: not a marker (key:value pairs separated by ;)',
        ], $result['headers']);
    }

    /**
     * Valid markers over the limit are left out before their icons load, and not in the bounds (#39).
     *
     * @return void
     */
    public function testLimit(): void
    {
        $bounds = new Bounds();
        $result = (new MarkerParser(null, 2))->parse([
            'lat:1;lng:1;icon:dot-small-blue',
            'lat:x;lng:1;icon:dot-small-blue',
            'lat:2;lng:2;icon:dot-small-blue',
            'lat:50;lng:50;icon:dot-small-blue',
            'lat:60;lng:60;icon:dot-small-blue',
        ], false, $bounds);

        $this->assertCount(2, $result['markers']);
        $this->assertSame('X-Map-Limit: left out 2 marker(s) over the limit of 2', end($result['headers']));
        $this->assertStringStartsWith('X-Marker-2:', $result['headers'][0], 'invalid ones are not counted');
        $this->assertEqualsWithDelta(2.0, $bounds->maxLat, 0.0001);
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

    /**
     * Generated pins, dots and labels (#37): drawn at the requested density, with their anchors.
     *
     * @return void
     */
    public function testGeneratedIcons(): void
    {
        $result = (new MarkerParser(null, PHP_INT_MAX, 2))->parse([
            'lat:1;lng:2;icon:pin;color:00ff00;size:large;label:A',
            'lat:1;lng:2;icon:dot;size:small',
            'lat:1;lng:2;icon:none;label:Hello',
        ], false, new Bounds());

        $this->assertSame([], $result['headers']);
        [$pin, $dot, $text] = $result['markers'];
        $this->assertSame(100, imagesy($pin['iconImg']), 'a large pin is 50 CSS px, drawn at density 2');
        $this->assertSame(2.0, $pin['density']);
        $this->assertSame(['frac', 0.5, 1.0], $pin['anchor']);
        $this->assertSame(['frac', 0.5, 0.5], $dot['anchor']);
        $this->assertSame(['frac', 0.5, 0.5], $text['anchor']);
        // The pin's fill colour is in its upper half.
        $rgba = imagecolorsforindex($pin['iconImg'], imagecolorat($pin['iconImg'], 16, 22));
        $this->assertSame([0, 255, 0], [$rgba['red'], $rgba['green'], $rgba['blue']]);
    }

    /**
     * Anchor, scale, rotation and opacity are read and checked; bad values leave the marker out.
     *
     * @return void
     */
    public function testOptions(): void
    {
        $result = self::parser()->parse([
            'lat:1;lng:2;icon:small-blue-cutout;anchor:topleft;scale:2;rotation:90;opacity:0.5',
            'lat:1;lng:2;icon:dot-small-red;anchor:3,4',
            'lat:1;lng:2;icon:pin;anchor:middle',
            'lat:1;lng:2;icon:pin;scale:9',
            'lat:1;lng:2;icon:pin;rotation:left',
            'lat:1;lng:2;icon:pin;opacity:2',
            'lat:1;lng:2;icon:pin;color:red',
            'lat:1;lng:2;icon:pin;size:huge',
            'lat:1;lng:2;icon:pin;label:ABCD',
            'lat:1;lng:2;icon:none',
            'lat:1;lng:2;icon:small-blue-cutout;label:A',
        ], false, new Bounds());

        [$first, $second] = $result['markers'];
        $this->assertSame([['frac', 0.0, 0.0], 2.0, 90.0, 0.5], [
            $first['anchor'],
            $first['scale'],
            $first['rotation'],
            $first['opacity'],
        ]);
        $this->assertSame(['px', 3.0, 4.0], $second['anchor']);
        $this->assertCount(2, $result['markers']);
        $this->assertSame([
            'X-Marker-3: anchor must be center, top, bottom, left, right, topleft, topright, bottomleft, '
                . 'bottomright, or x,y in pixels',
            'X-Marker-4: scale must be a number from 0.25 to 4',
            'X-Marker-5: rotation must be a number of degrees',
            'X-Marker-6: opacity must be a number from 0 to 1',
            'X-Marker-7: color must be RRGGBB (hex)',
            'X-Marker-8: size must be small, medium or large',
            'X-Marker-9: label must be at most 3 characters on icon:pin',
            'X-Marker-10: icon:none needs a label',
            'X-Marker-11: label works with icon:pin, dot or none',
        ], $result['headers']);
    }
}

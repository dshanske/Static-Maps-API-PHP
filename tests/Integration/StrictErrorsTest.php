<?php

/**
 * errors.strict (#39).
 */

namespace StaticMaps\Tests\Integration;

use StaticMaps\Tests\Support\RenderTestCase;

/**
 * Runs with tests/fixtures/config-strict.php: a marker or path that can't be drawn is a 400.
 */
final class StrictErrorsTest extends RenderTestCase
{
    /** @var array<string, mixed> A small map in Portland. */
    private const MAP = [
        'latitude'  => 45.5165,
        'longitude' => -122.6764,
        'zoom'      => 14,
        'width'     => 200,
        'height'    => 150,
    ];

    /**
     * Use the strict configuration.
     *
     * @param string $tileServerUrl Base URL of the fake tile server.
     *
     * @return array<string, string> Environment variables.
     */
    protected static function appEnvironment(string $tileServerUrl): array
    {
        $strict = ['STATIC_MAPS_CONFIG' => dirname(__DIR__) . '/fixtures/config-strict.php'];

        return $strict + parent::appEnvironment($tileServerUrl);
    }

    /**
     * Bad markers and paths get a 400 naming the first problem, before any tile is fetched.
     *
     * @return void
     */
    public function testBadOverlaysAreErrors(): void
    {
        foreach ($this->cases() as [$query, $message]) {
            $response = $this->render(self::MAP + $query);
            $this->assertSame(400, $response->status, $message);
            $this->assertStringStartsWith($message, $response->body);
            $this->assertStringStartsWith($message, (string) $response->header('X-Map-Error'));
        }
        $this->assertSame([], $this->tileRequests());
    }

    /**
     * The requests and the start of their error messages.
     *
     * @return list<array{array<string, mixed>, string}> Query and message prefix.
     */
    private function cases(): array
    {
        return [
            [
                ['marker' => ['lat:45.5;lng:-122.6;icon:dot-small-blue', 'lat:99;lng:0;icon:dot-small-blue']],
                'marker 2: lat and lng must be numbers in range',
            ],
            [['marker' => ['lat:45.5;lng:-122.6;icon:no-such-icon']], 'marker 1: '],
            [
                ['path' => ['[-122.6,45.5][-122.7,45.6]', '[-122.6,95]']],
                'path 2: points must be [lng,lat] numbers in range',
            ],
            [['polyline' => 'color:ff0000'], 'path 1: no points (expected enc:<encoded polyline>)'],
        ];
    }

    /**
     * Limits clamp, even in strict mode: markers and paths over them are left out with an
     * X-Map-Limit header (limits here: 3 markers, 20 points).
     *
     * @return void
     */
    public function testLimitsClamp(): void
    {
        $markers = array_fill(0, 5, 'lat:45.5165;lng:-122.6764;icon:dot-small-blue');
        $response = $this->render(self::MAP + ['marker' => $markers]);
        $this->assertSame(200, $response->status);
        $this->assertSame('left out 2 marker(s) over the limit of 3', $response->header('X-Map-Limit'));

        $ten = implode('', array_fill(0, 10, '[-122.67,45.51]'));
        $paths = $this->render(self::MAP + ['path' => [$ten, $ten, $ten]]);
        $this->assertSame(200, $paths->status);
        $this->assertSame('left out 1 path(s) over the limit of 20 points', $paths->header('X-Map-Limit'));

        $polyline = $this->render(self::MAP + ['polyline' => 'enc:_p~iF~ps|U' . str_repeat('??', 20)]);
        $this->assertSame('left out 1 path(s) over the limit of 20 points', $polyline->header('X-Map-Limit'));
    }

    /**
     * At most icon_urls (here 2) different icon URLs; the same URL again doesn't count twice.
     *
     * @return void
     */
    public function testIconUrlLimit(): void
    {
        $tiles = (string) self::$tiles?->url;
        $icon = static fn (string $path): string => 'lat:45.5165;lng:-122.6764;icon:' . $tiles . $path;
        $two = ['/overlay/1/1/1.png', '/overlay/1/1/2.png', '/overlay/1/1/1.png'];
        $ok = $this->render(self::MAP + ['key' => 'test-token', 'marker' => array_map($icon, $two)]);
        $this->assertSame(200, $ok->status);

        // Three markers (this config's max_markers), three different URLs.
        $three = ['/overlay/1/1/1.png', '/overlay/1/1/2.png', '/overlay/1/2/1.png'];
        $over = $this->render(self::MAP + ['key' => 'test-token', 'marker' => array_map($icon, $three)]);
        $this->assertSame(400, $over->status);
        $this->assertSame("marker 3: at most 2 different icon URLs per map\n", $over->body);
    }

    /**
     * Icons from URLs bigger than icon_pixels (here 64) are drawn at that size: the 256 px
     * fixture icon's 16 px white centre square becomes 4 px, 32 px above the anchor.
     *
     * @return void
     */
    public function testLargeIconsAreDrawnSmaller(): void
    {
        $marker = 'lat:45.5165;lng:-122.6764;icon:' . self::$tiles?->url . '/overlay/1/1/1.png';
        $response = $this->render(
            ['latitude' => 45.5165, 'longitude' => -122.6764, 'zoom' => 14, 'width' => 300, 'height' => 300]
            + ['key' => 'test-token', 'marker' => [$marker]]
        );

        $im = $response->image();
        $this->assertPixel([255, 255, 255], $im, 150, 118, 10);
        $this->assertNotSame([255, 255, 255], self::rgbAt($im, 150, 22), 'not at full size');
    }
}

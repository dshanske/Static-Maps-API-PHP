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
}

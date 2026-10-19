<?php

/**
 * End-to-end render tests for public/index.php.
 */

namespace StaticMaps\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use StaticMaps\Polyline;
use StaticMaps\Tests\Support\RenderTestCase;

/**
 * Characterisation tests: they pin down what the renderer does today, so the
 * refactor in #14 can be checked against them. Tests that pin a known bug say
 * so and name its issue; change them when the bug is fixed.
 */
final class RenderTest extends RenderTestCase
{
    /** @var array{latitude: float, longitude: float, zoom: int} Centre of tile 2608/5860 at zoom 14 (Portland). */
    private const TILE_CENTER = ['latitude' => 45.51404592560425, 'longitude' => -122.684326171875, 'zoom' => 14];

    /** @var array<string, mixed> Downtown Portland at zoom 14. */
    private const PORTLAND = ['latitude' => 45.5165, 'longitude' => -122.6764, 'zoom' => 14];

    /** @var list<string> Two markers in Portland; with no centre or zoom given they decide the view. */
    private const MARKERS = [
        'lat:45.5165;lng:-122.6764;icon:small-blue-cutout',
        'lat:45.52;lng:-122.68;icon:dot-small-red',
    ];

    /** @var string A three-point path, as [lng,lat] pairs. */
    private const PATH = '[-122.68,45.51][-122.67,45.52][-122.66,45.515]';

    /** @var array{int, int, int} Colour of a tile that failed to download. */
    private const GRAY = [224, 224, 224];

    /** @var array{int, int, int} Colour of the square in the centre of each overlay tile. */
    private const WHITE = [255, 255, 255];

    /**
     * A plain map: PNG of the default size, tiles placed by their coordinates.
     *
     * @return void
     */
    public function testCenterAndZoom(): void
    {
        $response = $this->render(self::PORTLAND);

        $this->assertSame(200, $response->status);
        $this->assertSame('image/png', $response->header('Content-Type'));
        $im = $response->image();
        $this->assertSame([300, 300], [imagesx($im), imagesy($im)]);

        // Centre pixel 667868,1500247 is in tile 2608/5860; the top-left corner in 2608/5859.
        $this->assertPixel(self::tileColor(2608, 5860), $im, 150, 150);
        $this->assertPixel(self::tileColor(2608, 5859), $im, 0, 0);
        $this->assertPixel(self::tileColor(2609, 5860), $im, 299, 200);

        $this->assertSame(
            ['/base/14/2608/5859.png', '/base/14/2608/5860.png', '/base/14/2609/5859.png', '/base/14/2609/5860.png'],
            $this->tilePaths()
        );
        $userAgents = array_unique(array_column($this->tileRequests(), 'ua'));
        $this->assertSame(['Static Maps API tests'], array_values($userAgents));

        $this->assertMatchesSnapshot('center-zoom.png', $response->body);
    }

    /**
     * The renderer answers at /, at /index.php and at the deprecated /img.php
     * with the same image; only /img.php adds deprecation headers (#24).
     *
     * @return void
     */
    public function testEntryPoints(): void
    {
        $root = $this->render(self::PORTLAND);
        $index = $this->render(self::PORTLAND, '/index.php');
        $legacy = $this->render(self::PORTLAND, '/img.php');

        $this->assertSame('image/png', $root->header('Content-Type'));
        $this->assertSame($root->body, $index->body);
        $this->assertSame($root->body, $legacy->body);

        $this->assertNull($root->header('Deprecation'));
        $this->assertNull($index->header('Deprecation'));
        $this->assertSame('@1792281600', $legacy->header('Deprecation'));
        $this->assertSame(
            '<https://github.com/dshanske/Static-Maps-API-PHP#deprecated>; rel="deprecation"',
            $legacy->header('Link')
        );
    }

    /**
     * A request without any parameters gets the HTML home page, at every entry point (#47).
     *
     * @return void
     */
    public function testHomePage(): void
    {
        foreach (['/', '/index.php', '/img.php'] as $path) {
            $response = self::$app->get($path);

            $this->assertSame(200, $response->status, $path);
            $this->assertSame('text/html; charset=utf-8', $response->header('Content-Type'), $path);
            $this->assertStringContainsString('<title>Static Maps API</title>', $response->body);
        }
        $this->assertSame([], $this->tileRequests());
        $this->assertSame([], self::$app?->errors());
    }

    /**
     * Requests that don't say where the map is.
     *
     * @return array<string, array{array<string, mixed>, string}> Parameters and expected message.
     */
    public static function unlocatedCases(): array
    {
        $none = 'A map needs a location: latitude and longitude, or at least one marker or path';
        $pair = 'latitude and longitude must be given together';

        return [
            'zoom only'            => [['zoom' => 12], $none],
            'unrelated parameter'  => [['foo' => 'bar'], $none],
            'empty parameter'      => [['width' => ''], $none],
            'invalid markers only' => [['marker' => ['icon:dot-small-red', 'lat:1;lng:2']], $none],
            'path without points'  => [['path' => ['color:ff0000']], $none],
            'latitude only'        => [['latitude' => 45.5, 'marker' => self::MARKERS], $pair],
            'longitude only'       => [['longitude' => -122.6, 'zoom' => 10], $pair],
        ];
    }

    /**
     * A request without a location gets a 400 with the reason, and no tiles are fetched (#47).
     *
     * @param array<string, mixed> $params  Request parameters.
     * @param string               $message Expected reason.
     *
     * @return void
     */
    #[DataProvider('unlocatedCases')]
    public function testRequestWithoutLocationIsRejected(array $params, string $message): void
    {
        $response = $this->render($params);

        $this->assertSame(400, $response->status);
        $this->assertSame('text/plain; charset=utf-8', $response->header('Content-Type'));
        $this->assertSame($message . "\n", $response->body);
        $this->assertSame([], $this->tileRequests());
    }

    /**
     * Skipped markers are still reported on a 400.
     *
     * @return void
     */
    public function testRejectedRequestKeepsMarkerHeaders(): void
    {
        $response = $this->render(['marker' => ['icon:dot-small-red']]);

        $this->assertSame(400, $response->status);
        $this->assertSame('missing icon, or lat/lng/location parameters', $response->header('X-Marker-1'));
    }

    /**
     * Any one way of locating the map is enough.
     *
     * @return array<string, array{array<string, mixed>}> Parameters.
     */
    public static function locatedCases(): array
    {
        return [
            'latitude and longitude' => [['latitude' => 45.5, 'longitude' => -122.6]],
            'one marker'             => [['marker' => ['lat:45.5;lng:-122.6;icon:dot-small-red']]],
            'one path'               => [['path' => ['[-122.68,45.51][-122.67,45.52]']]],
            'marker without its icon' => [['marker' => ['lat:45.5;lng:-122.6;icon:https://icons.invalid/x.png']]],
        ];
    }

    /**
     * Requests that locate the map render it.
     *
     * @param array<string, mixed> $params Request parameters.
     *
     * @return void
     */
    #[DataProvider('locatedCases')]
    public function testLocatedRequestRenders(array $params): void
    {
        $response = $this->render($params);

        $this->assertSame(200, $response->status);
        $this->assertSame('image/png', $response->header('Content-Type'));
    }

    /**
     * width and height set the image size.
     *
     * @return void
     */
    public function testSize(): void
    {
        $im = $this->render(self::PORTLAND + ['width' => 400, 'height' => 240])->image();

        $this->assertSame([400, 240], [imagesx($im), imagesy($im)]);
    }

    /**
     * Odd sizes put tiles at half-pixel offsets (logged as float-to-int deprecations, #18).
     *
     * @return void
     */
    public function testOddSize(): void
    {
        $response = $this->render(self::PORTLAND + ['width' => 301, 'height' => 201]);

        $this->assertMatchesSnapshot('odd-size.png', $response->body);
    }

    /**
     * Markers set the centre and zoom when none are given, and are drawn on top.
     *
     * @return void
     */
    public function testMarkersFitTheView(): void
    {
        $response = $this->render(['marker' => self::MARKERS]);

        $tiles = [];
        foreach ([5216, 5217, 5218] as $x) {
            foreach ([11719, 11720, 11721] as $y) {
                $tiles[] = "/base/15/$x/$y.png";
            }
        }
        $this->assertSame($tiles, $this->tilePaths());
        $this->assertMatchesSnapshot('markers.png', $response->body);
    }

    /**
     * A centre-anchored dot icon is drawn centred on its point.
     *
     * @return void
     */
    public function testDotMarkerIsCentred(): void
    {
        $point = 'lat:' . self::TILE_CENTER['latitude'] . ';lng:' . self::TILE_CENTER['longitude'];
        $im = $this->render(self::TILE_CENTER + ['marker' => [$point . ';icon:dot-small-red']])->image();

        $this->assertPixel([229, 58, 68], $im, 150, 150, 8);
    }

    /**
     * Markers are shrunk on images smaller than 120px.
     *
     * @return void
     */
    public function testMarkersOnSmallImage(): void
    {
        $response = $this->render(['marker' => self::MARKERS, 'width' => 100, 'height' => 100]);

        $this->assertMatchesSnapshot('markers-small.png', $response->body);
    }

    /**
     * A marker without an icon is skipped and reported in a header.
     *
     * @return void
     */
    public function testInvalidMarkerIsReported(): void
    {
        $response = $this->render(self::PORTLAND + ['marker' => ['lat:45.5;lng:-122.6']]);

        $this->assertSame(200, $response->status);
        $this->assertSame('missing icon, or lat/lng/location parameters', $response->header('X-Marker-1'));
    }

    /**
     * Paths with default and explicit style.
     *
     * @return array<string, array{string, string}> path[] value and snapshot name.
     */
    public static function pathCases(): array
    {
        return [
            'default style'  => [self::PATH, 'path-default.png'],
            'colour, weight' => ['color:ff0000;weight:6;' . self::PATH, 'path-styled.png'],
        ];
    }

    /**
     * Paths fit the view and are drawn with their style.
     *
     * @param string $path     The path[] value.
     * @param string $snapshot Snapshot file name.
     *
     * @return void
     */
    #[DataProvider('pathCases')]
    public function testPath(string $path, string $snapshot): void
    {
        $response = $this->render(['path' => [$path]]);

        $this->assertMatchesSnapshot($snapshot, $response->body);
    }

    /**
     * bezier curves path segments.
     *
     * @return void
     */
    public function testBezierPath(): void
    {
        $response = $this->render(['path' => ['color:ff0000;weight:4;' . self::PATH], 'bezier' => 30]);

        $this->assertMatchesSnapshot('path-bezier.png', $response->body);
    }

    /**
     * An encoded polyline draws the same image as the same points in path[].
     *
     * The encoded points are read as [lng,lat] pairs, like path[] (Google's
     * format encodes lat,lng).
     *
     * @return void
     */
    public function testPolylineMatchesPath(): void
    {
        $points = [[-122.68, 45.51], [-122.67, 45.52], [-122.66, 45.515]];
        $style = 'color:0033ff;weight:5';

        $path = $this->render(['path' => [$style . ';' . self::PATH]]);
        $polyline = $this->render(['polyline' => 'enc:' . Polyline::encode($points) . ';' . $style]);

        $this->assertSame($path->body, $polyline->body);
    }

    /**
     * Basemap choice: the requested id, its alias target, or the default.
     *
     * @return array<string, array{array<string, string>, array{int, int, int}, string}>
     *         Extra parameters, colour expected at the centre and the tile layer requested.
     */
    public static function basemapCases(): array
    {
        $base = self::tileColor(2608, 5860);

        return [
            'default'               => [[], $base, 'base'],
            'overlay'               => [['basemap' => 'fixture-overlay'], self::WHITE, 'overlay'],
            'alias'                 => [['basemap' => 'fixture-alias'], self::WHITE, 'overlay'],
            'unknown → default'     => [['basemap' => 'nope'], $base, 'base'],
            'disabled → default'    => [['basemap' => 'fixture-off'], $base, 'base'],
            'needs token → default' => [['basemap' => 'fixture-auth'], $base, 'base'],
            'shipped, switched off' => [['basemap' => 'osm'], $base, 'base'],
            'custom without token'  => [
                ['basemap' => 'custom', 'tileurl' => '__TILES__/overlay/{z}/{x}/{y}.png'],
                $base,
                'base',
            ],
            'failed tiles are gray' => [['basemap' => 'fixture-broken'], self::GRAY, 'broken'],
        ];
    }

    /**
     * The basemap actually drawn for a requested basemap id.
     *
     * @param array<string, string> $params Extra request parameters.
     * @param array{int, int, int}  $center Expected colour at the image centre.
     * @param string                $layer  The only tile layer that should be requested last.
     *
     * @return void
     */
    #[DataProvider('basemapCases')]
    public function testBasemapChoice(array $params, array $center, string $layer): void
    {
        $params = str_replace('__TILES__', (string) self::$tiles?->url, $params);
        $im = $this->render(self::TILE_CENTER + $params)->image();

        $this->assertPixel($center, $im, 150, 150);
        $layers = array_unique(array_map(
            static fn (string $p): string => explode('/', $p)[1],
            $this->tilePaths()
        ));
        $this->assertContains($layer, $layers);
        if ($layer !== 'overlay') {
            $this->assertNotContains('overlay', $layers);
        }
    }

    /**
     * A valid token unlocks basemaps with requires_auth.
     *
     * @return void
     */
    public function testTokenUnlocksBasemap(): void
    {
        $im = $this->render(self::TILE_CENTER + ['basemap' => 'fixture-auth', 'token' => 'test-token'])->image();

        $this->assertPixel(self::WHITE, $im, 150, 150);
        $this->assertContains('/overlay/14/2608/5860.png', $this->tilePaths());
    }

    /**
     * With a valid token, basemap=custom draws tiles from tileurl.
     *
     * @return void
     */
    public function testCustomTileUrlWithToken(): void
    {
        $im = $this->render(self::TILE_CENTER + [
            'basemap' => 'custom',
            'tileurl' => self::$tiles?->url . '/overlay/{z}/{x}/{y}.png',
            'token'   => 'test-token',
        ])->image();

        $this->assertPixel(self::WHITE, $im, 150, 150);
        foreach ($this->tilePaths() as $path) {
            $this->assertStringStartsWith('/overlay/14/', $path);
        }
    }

    /**
     * An icon URL is only fetched and drawn for a request with a valid token.
     *
     * The "icon" is an overlay tile (256x256, white 16px square in the middle),
     * anchored at its bottom centre, so the square lands 128px above the point.
     *
     * @return void
     */
    public function testRemoteIconNeedsToken(): void
    {
        $point = 'lat:' . self::TILE_CENTER['latitude'] . ';lng:' . self::TILE_CENTER['longitude'];
        $marker = ['marker' => [$point . ';icon:' . self::$tiles?->url . '/overlay/1/1/1.png']];

        foreach (['wrong-token' => false, 'test-token' => true] as $token => $drawn) {
            $this->setUp();
            $im = $this->render(self::TILE_CENTER + $marker + ['token' => $token])->image();

            $fetched = in_array('/overlay/1/1/1.png', $this->tilePaths(), true);
            $this->assertSame($drawn, $fetched, "icon fetched with $token");
            $this->assertPixel($drawn ? self::WHITE : self::tileColor(2608, 5860), $im, 150, 22);
        }
    }

    /**
     * The basemap's key is URL-encoded into {key}.
     *
     * @return void
     */
    public function testBasemapKey(): void
    {
        $this->render(self::PORTLAND + ['basemap' => 'fixture-keyed']);

        $queries = array_unique(array_column($this->tileRequests(), 'query'));
        $this->assertSame(['key=a%20b%26c'], array_values($queries));
    }

    /**
     * Zoom levels requested for a given request.
     *
     * @return array<string, array{array<string, mixed>, int}> Request parameters and the zoom of the tiles fetched.
     */
    public static function zoomCases(): array
    {
        $portland = ['latitude' => 45.5165, 'longitude' => -122.6764];

        return [
            'explicit'                  => [$portland + ['zoom' => 12], 12],
            'above global max'          => [$portland + ['zoom' => 20], 18],
            'below global min'          => [$portland + ['zoom' => 1], 2],
            'below basemap min'         => [$portland + ['zoom' => 3, 'basemap' => 'fixture-zoom'], 5],
            'above basemap max'         => [$portland + ['zoom' => 15, 'basemap' => 'fixture-zoom'], 10],
            'maxzoom caps fitted zoom'  => [['marker' => self::MARKERS, 'maxzoom' => 11], 11],
            'maxzoom caps given zoom'   => [$portland + ['zoom' => 14, 'maxzoom' => 9], 9],
            'maxzoom above zoom'        => [$portland + ['zoom' => 9, 'maxzoom' => 14], 9],
        ];
    }

    /**
     * zoom, maxzoom and the basemap/global zoom range decide the tile zoom.
     *
     * @param array<string, mixed> $params Request parameters.
     * @param int                  $zoom   Expected tile zoom level.
     *
     * @return void
     */
    #[DataProvider('zoomCases')]
    public function testZoom(array $params, int $zoom): void
    {
        $this->render($params);

        $zooms = array_unique(array_map(
            static fn (string $p): int => (int) explode('/', $p)[2],
            $this->tilePaths()
        ));
        $this->assertSame([$zoom], array_values($zooms));
    }

    /**
     * Attribution logos: request parameter, basemap default and size rules.
     *
     * @return array<string, array{array<string, mixed>, string}> Extra parameters and snapshot name.
     */
    public static function attributionCases(): array
    {
        return [
            'osm (default)'        => [[], 'attr-osm.png'],
            'esri from basemap'    => [['basemap' => 'fixture-esri'], 'attr-esri.png'],
            'esri, shrunk < 220px' => [['attribution' => 'esri', 'width' => 200, 'height' => 200], 'attr-esri-200.png'],
            'esri, none < 120px'   => [['attribution' => 'esri', 'width' => 100, 'height' => 100], 'attr-esri-100.png'],
            'mapbox'               => [['attribution' => 'mapbox'], 'attr-mapbox.png'],
        ];
    }

    /**
     * The attribution logo drawn in the bottom-right corner.
     *
     * @param array<string, mixed> $params   Extra request parameters.
     * @param string               $snapshot Snapshot file name.
     *
     * @return void
     */
    #[DataProvider('attributionCases')]
    public function testAttribution(array $params, string $snapshot): void
    {
        $response = $this->render(self::TILE_CENTER + $params);

        $this->assertMatchesSnapshot($snapshot, $response->body);
    }

    /**
     * Known bug (#18): attribution=none still draws the OpenStreetMap logo.
     *
     * @return void
     */
    public function testAttributionNoneDrawsOsmLogo(): void
    {
        $this->assertSame(
            $this->render(self::TILE_CENTER)->body,
            $this->render(self::TILE_CENTER + ['attribution' => 'none'])->body
        );
    }

    /**
     * format=jpg returns a JPEG whose size follows quality.
     *
     * @return void
     */
    public function testJpeg(): void
    {
        $low = $this->render(self::PORTLAND + ['format' => 'jpg', 'quality' => 10]);
        $high = $this->render(self::PORTLAND + ['format' => 'jpeg', 'quality' => 95]);

        $this->assertSame('image/jpg', $low->header('Content-Type'));
        $this->assertSame("\xFF\xD8\xFF", substr($low->body, 0, 3));
        $this->assertLessThan(strlen($high->body), strlen($low->body));
        $this->assertMatchesSnapshot('jpeg.jpg', $high->body);
    }

    /**
     * Known bug (#18): an unknown format returns an empty body.
     *
     * @return void
     */
    public function testUnknownFormatReturnsEmptyBody(): void
    {
        $response = $this->render(self::PORTLAND + ['format' => 'gif']);

        $this->assertSame(200, $response->status);
        $this->assertSame('', $response->body);
    }
}

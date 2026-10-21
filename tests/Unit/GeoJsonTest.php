<?php

/**
 * Tests for include/GeoJson.php.
 */

namespace StaticMaps\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StaticMaps\GeoJson;
use StaticMaps\InvalidRequest;

/**
 * GeoJSON features as marker and path values (#38).
 */
final class GeoJsonTest extends TestCase
{
    /**
     * Points, lines and polygons with simplestyle properties and defaults.
     *
     * @return void
     */
    public function testFeatures(): void
    {
        $result = GeoJson::toOverlays(['type' => 'FeatureCollection', 'features' => [
            ['type' => 'Feature', 'properties' => ['marker-color' => '#f00', 'marker-size' => 'large',
                'marker-symbol' => 'A'], 'geometry' => ['type' => 'Point', 'coordinates' => [-122.6, 45.5]]],
            ['type' => 'Feature', 'properties' => ['marker-symbol' => 'bus'],
                'geometry' => ['type' => 'Point', 'coordinates' => [1, 2]]],
            ['type' => 'Feature', 'properties' => ['stroke' => '#0000ff', 'stroke-width' => 4, 'stroke-opacity' => 0.5],
                'geometry' => ['type' => 'LineString', 'coordinates' => [[-122.6, 45.5], [-122.7, 45.6]]]],
            ['type' => 'Feature', 'properties' => [],
                'geometry' => ['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [1, 1], [0, 0]], [[0.2, 0.2]]]]],
        ]]);

        $this->assertSame([
            'lat:45.5;lng:-122.6;icon:pin;color:ff0000;size:large;label:A',
            'lat:2;lng:1;icon:pin;color:7e7e7e;size:medium',
        ], $result['markers'], 'a Maki icon name is not a label');
        $this->assertSame([
            'color:0000ff80;weight:4;[-122.6,45.5][-122.7,45.6]',
            'color:555555;weight:2;fillcolor:55555599;[0,0][1,0][1,1][0,0]',
        ], $result['paths'], 'stroke opacity 0.5 is 80, the default fill opacity 0.6 is 99; holes are dropped');
        $this->assertSame([], $result['headers']);
    }

    /**
     * Multi-geometries, collections, bare geometries and features without a geometry.
     *
     * @return void
     */
    public function testCollections(): void
    {
        $result = GeoJson::toOverlays(json_encode(['type' => 'FeatureCollection', 'features' => [
            ['type' => 'Feature', 'geometry' => ['type' => 'MultiPoint', 'coordinates' => [[1, 2], [3, 4]]]],
            ['type' => 'Feature', 'geometry' => ['type' => 'GeometryCollection', 'geometries' => [
                ['type' => 'LineString', 'coordinates' => [[1, 2], [3, 4]]],
                ['type' => 'MultiPolygon', 'coordinates' => [[[[0, 0], [1, 1], [1, 0], [0, 0]]]]],
            ]]],
            ['type' => 'Feature', 'geometry' => null],
            ['type' => 'Feature', 'geometry' => ['type' => 'Circle', 'coordinates' => [1, 2]]],
        ]]));

        $this->assertCount(2, $result['markers']);
        $this->assertCount(2, $result['paths']);
        $this->assertSame(['X-Map-GeoJSON: skipped 2 feature(s) without a supported geometry'], $result['headers']);

        $bare = GeoJson::toOverlays(['type' => 'Point', 'coordinates' => [1.5e-5, 2]]);
        $this->assertSame(['lat:2;lng:0.000015;icon:pin;color:7e7e7e;size:medium'], $bare['markers'], 'no exponents');
    }

    /**
     * Values that would break the key:value syntax are cleaned; invalid colours are passed on to be reported.
     *
     * @return void
     */
    public function testUnsafeValues(): void
    {
        $result = GeoJson::toOverlays(['type' => 'Feature', 'properties' => ['stroke' => 'red;weight:90'],
            'geometry' => ['type' => 'LineString', 'coordinates' => [[1, 2], [3, 4]]]]);

        $this->assertSame(['color:redweight90;weight:2;[1,2][3,4]'], $result['paths']);
    }

    /**
     * Values that aren't GeoJSON.
     *
     * @return array<string, array{mixed}> The values.
     */
    public static function invalid(): array
    {
        return [
            'not JSON'            => ['{nope'],
            'no type'             => [['features' => []]],
            'collection, no list' => [['type' => 'FeatureCollection', 'features' => 'x']],
            'a number'            => [5],
        ];
    }

    /**
     * Anything that isn't a GeoJSON object is a 400.
     *
     * @param mixed $value The parameter.
     *
     * @return void
     */
    #[DataProvider('invalid')]
    public function testInvalid(mixed $value): void
    {
        $this->expectException(InvalidRequest::class);
        GeoJson::toOverlays($value);
    }
}

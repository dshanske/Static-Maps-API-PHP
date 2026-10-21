<?php

/**
 * GeoJSON overlays and POST with a JSON body (#38).
 */

namespace StaticMaps\Tests\Integration;

use StaticMaps\Tests\Support\RenderTestCase;

/**
 * Maps from JSON bodies and `geojson`.
 */
final class GeoJsonTest extends RenderTestCase
{
    /** @var array<string, mixed> A triangle and a point in Portland. */
    private const GEOJSON = ['type' => 'FeatureCollection', 'features' => [
        ['type' => 'Feature', 'properties' => ['fill' => '#ff0000', 'fill-opacity' => 1, 'stroke-width' => 0],
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[
                [-122.70, 45.50], [-122.65, 45.50], [-122.675, 45.53], [-122.70, 45.50],
            ]]]],
        ['type' => 'Feature', 'properties' => ['marker-color' => '#0000ff', 'marker-symbol' => '1'],
            'geometry' => ['type' => 'Point', 'coordinates' => [-122.675, 45.51]]],
    ]];

    /**
     * POST a JSON body.
     *
     * @param array<string, mixed>|string $body The body (encoded if an array).
     *
     * @return \StaticMaps\Tests\Support\Response The response.
     */
    private function post(array|string $body): \StaticMaps\Tests\Support\Response
    {
        return self::$app->request(
            'POST',
            '/',
            ['Content-Type: application/json'],
            is_string($body) ? $body : (string) json_encode($body)
        );
    }

    /**
     * A POSTed GeoJSON map: fitted to the features, the polygon filled, the same as the GET form.
     *
     * @return void
     */
    public function testPostGeoJson(): void
    {
        $response = $this->post(['width' => 300, 'height' => 300, 'geojson' => self::GEOJSON]);

        $this->assertSame(200, $response->status);
        $this->assertSame('image/png', $response->header('Content-Type'));
        $this->assertNull($response->header('X-Path-1'));
        $this->assertNull($response->header('X-Marker-1'));
        // Near the bottom of the triangle (the centre has the marker on it): solid red.
        $this->assertPixel([255, 0, 0], $response->image(), 150, 215, 2);

        $get = $this->render(['width' => 300, 'height' => 300, 'geojson' => json_encode(self::GEOJSON)]);
        $this->assertSame($response->body, $get->body, 'geojson as a JSON string in the query');
    }

    /**
     * A JSON body works like query parameters, including marker and path lists.
     *
     * @return void
     */
    public function testPostParameters(): void
    {
        $query = ['latitude' => 45.5165, 'longitude' => -122.6764, 'zoom' => 14, 'width' => 200, 'height' => 150,
            'marker' => ['lat:45.5165;lng:-122.6764;icon:dot;color:ff0000']];
        $post = $this->post($query);
        $get = $this->render($query);

        $this->assertSame(200, $post->status);
        $this->assertSame($get->body, $post->body);
    }

    /**
     * Bodies that are too large or not a JSON object, and invalid geojson.
     *
     * @return void
     */
    public function testErrors(): void
    {
        $big = $this->post(['zoom' => 3, 'padding' => str_repeat('1', 30000)]);
        $this->assertSame(413, $big->status);
        $this->assertSame("The request body is over the limit of 20000 bytes\n", $big->body);

        $this->assertSame(400, $this->post('[1, 2]')->status);
        $this->assertSame(400, $this->post('{nope')->status);

        $invalid = $this->render(['latitude' => 1, 'longitude' => 2, 'geojson' => '{"features": []}']);
        $this->assertSame(400, $invalid->status);
        $this->assertStringStartsWith('geojson must be', $invalid->body);
        $this->assertSame([], $this->tileRequests());
    }

    /**
     * Signed URLs are GET-only; a POST uses a plain key.
     *
     * @return void
     */
    public function testPostNeedsAPlainKey(): void
    {
        $signed = self::$app->request('POST', '/?key=test&signature=abc', ['Content-Type: application/json'], '{}');
        $this->assertSame(403, $signed->status);

        $keyed = $this->post(['latitude' => 45.5, 'longitude' => -122.6, 'zoom' => 12, 'key' => 'test-token',
            'provider' => 'keyed']);
        $this->assertSame(200, $keyed->status);
        $this->assertNull($keyed->header('X-Map-Fallback'));
    }
}

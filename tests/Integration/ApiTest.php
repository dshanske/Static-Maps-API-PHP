<?php

/**
 * The query API under /api/v1/ (#25).
 */

namespace StaticMaps\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use StaticMaps\Tests\Support\RenderTestCase;
use StaticMaps\Tests\Support\Response;
use StaticMaps\Version;

/**
 * Runs the API with the test configuration (tests/fixtures/config.php).
 */
final class ApiTest extends RenderTestCase
{
    /**
     * Request an API path and decode the JSON body.
     *
     * @param string $path Path and query string.
     *
     * @return array{Response, array<string, mixed>} The response and its decoded body.
     */
    private function api(string $path): array
    {
        $response = self::$app->get($path);
        $this->assertSame([], self::$app->errors(), 'PHP logged errors');

        return [$response, (array) json_decode($response->body, true)];
    }

    /**
     * `/api/v1/` describes the service.
     *
     * @return void
     */
    public function testRoot(): void
    {
        [$response, $data] = $this->api('/api/v1/');

        $this->assertSame(200, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->header('Content-Type'));
        $this->assertSame('*', $response->header('Access-Control-Allow-Origin'));
        $this->assertSame('public, max-age=300', $response->header('Cache-Control'));
        $this->assertSame(Version::VERSION, $data['version']);
        $this->assertSame(1, $data['api_version']);
        $this->assertSame(['fixture', 'base'], [$data['defaults']['provider'], $data['defaults']['style']]);
        $this->assertSame(['min' => 2, 'max' => 18, 'max_limit' => null], $data['zoom']);
        $this->assertFalse($data['custom_tiles']);
        $this->assertSame('/api/v1/providers', $data['links']['providers']);
        $this->assertSame([], $this->tileRequests());
    }

    /**
     * The trailing slash is optional.
     *
     * @return void
     */
    public function testTrailingSlash(): void
    {
        $this->assertSame($this->api('/api/v1/')[1], $this->api('/api/v1')[1]);
        $this->assertSame($this->api('/api/v1/icons')[1], $this->api('/api/v1/icons/')[1]);
    }

    /**
     * `/api/v1/providers` lists only maps that can be drawn, with their settings.
     *
     * @return void
     */
    public function testProviders(): void
    {
        [$response, $data] = $this->api('/api/v1/providers');

        $this->assertSame(200, $response->status);
        $this->assertSame(['provider' => 'fixture', 'style' => 'base'], $data['default']);

        $byId = array_column($data['providers'], null, 'id');
        // Shipped providers are switched off in the test config; token-only, keyless and
        // disabled providers are left out.
        $this->assertSame(['fixture', 'keyed'], array_keys($byId));

        $styles = array_column($byId['fixture']['styles'], null, 'id');
        $this->assertSame(['base', 'overlay', 'broken', 'esri', 'zoom'], array_keys($styles), 'no auth or off');
        $this->assertSame(
            ['id' => 'zoom', 'name' => 'Limited to zoom 5-10', 'minzoom' => 5, 'maxzoom' => 10,
                'attribution' => 'osm', 'labels_layer' => false, 'requires_auth' => false],
            $styles['zoom']
        );
        $this->assertTrue($styles['overlay']['labels_layer']);
        $this->assertSame('esri', $styles['esri']['attribution']);
        $this->assertSame('base', $byId['fixture']['default_style']);

        $keyed = array_column($byId['keyed']['styles'], null, 'id');
        $main = $keyed['main'];
        $this->assertSame([2, 12, 'esri'], [$main['minzoom'], $main['maxzoom'], $main['attribution']]);
        $this->assertSame(14, $keyed['overridden']['maxzoom']);

        $overlay = $data['deprecated_basemaps']['fixture-overlay'];
        $this->assertSame(['provider' => 'fixture', 'style' => 'overlay'], $overlay);
        $this->assertArrayNotHasKey('fixture-auth', $data['deprecated_basemaps'], 'token-only target');
        $this->assertArrayNotHasKey('osm', $data['deprecated_basemaps'], 'switched-off target');
        $this->assertContains('stamen', array_column($data['removed'], 'id'));
    }

    /**
     * With a valid token, token-only providers and styles are listed too.
     *
     * @return void
     */
    public function testProvidersWithToken(): void
    {
        [$response, $data] = $this->api('/api/v1/providers?token=test-token');

        $this->assertSame('private, no-store', $response->header('Cache-Control'));
        $byId = array_column($data['providers'], null, 'id');
        $this->assertSame(['fixture', 'keyed', 'secret'], array_keys($byId));
        $styles = array_column($byId['fixture']['styles'], null, 'id');
        $this->assertTrue($styles['auth']['requires_auth']);
        $this->assertArrayHasKey('fixture-auth', $data['deprecated_basemaps']);
        $this->assertTrue($this->api('/api/v1/?token=test-token')[1]['custom_tiles']);
    }

    /**
     * No response ever contains a tile URL or an API key.
     *
     * @return void
     */
    public function testNoTileUrlsOrKeys(): void
    {
        foreach (['/api/v1/', '/api/v1/providers', '/api/v1/providers?token=test-token', '/api/v1/icons'] as $path) {
            $body = self::$app->get($path)->body;
            $this->assertStringNotContainsString((string) self::$tiles?->url, $body, $path);
            $this->assertStringNotContainsString('{z}', $body, $path);
            $this->assertStringNotContainsString('a b&c', $body, $path);
            $this->assertStringNotContainsString('"own"', $body, $path);
            $this->assertStringNotContainsString('"key"', $body, $path);
            $this->assertStringNotContainsString('"layers"', $body, $path);
        }
    }

    /**
     * `/api/v1/icons` lists every built-in icon with its size, anchor and URL.
     *
     * @return void
     */
    public function testIcons(): void
    {
        [$response, $data] = $this->api('/api/v1/icons');

        $files = glob(dirname(__DIR__, 2) . '/public/images/*.png') ?: [];
        $this->assertSame(200, $response->status);
        $this->assertCount(count($files), $data['icons']);

        $icons = array_column($data['icons'], null, 'name');
        $this->assertSame(
            ['name' => 'small-blue-cutout', 'width' => 31, 'height' => 43, 'anchor' => 'bottom-center',
                'url' => '/images/small-blue-cutout.png'],
            $icons['small-blue-cutout']
        );
        $this->assertSame('center', $icons['dot-small-red']['anchor']);

        $image = self::$app->get($icons['dot-small-red']['url']);
        $this->assertSame(200, $image->status);
        $this->assertSame('image/png', $image->header('Content-Type'));
    }

    /**
     * Error responses are JSON with the right status.
     *
     * @return array<string, array{string, string, int}> Method, path and expected status.
     */
    public static function errorCases(): array
    {
        return [
            'unknown endpoint'  => ['GET', '/api/v1/basemaps', 404],
            'unknown version'   => ['GET', '/api/v2/', 404],
            'bare api'          => ['GET', '/api', 404],
            'post'              => ['POST', '/api/v1/providers', 405],
            'delete'            => ['DELETE', '/api/v1/', 405],
        ];
    }

    /**
     * Unknown endpoints get 404 and other methods 405, as JSON with CORS headers.
     *
     * @param string $method HTTP method.
     * @param string $path   Request path.
     * @param int    $status Expected status.
     *
     * @return void
     */
    #[DataProvider('errorCases')]
    public function testErrors(string $method, string $path, int $status): void
    {
        $response = self::$app->request($method, $path);

        $this->assertSame($status, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->header('Content-Type'));
        $this->assertSame('*', $response->header('Access-Control-Allow-Origin'));
        $this->assertArrayHasKey('error', (array) json_decode($response->body, true));
        if ($status === 405) {
            $this->assertSame('GET, HEAD, OPTIONS', $response->header('Allow'));
        }
    }

    /**
     * HEAD gets the headers without a body; OPTIONS (CORS preflight) gets 204.
     *
     * @return void
     */
    public function testHeadAndOptions(): void
    {
        $head = self::$app->request('HEAD', '/api/v1/providers');
        $this->assertSame(200, $head->status);
        $this->assertSame('application/json; charset=utf-8', $head->header('Content-Type'));
        $this->assertSame('', $head->body);

        $options = self::$app->request('OPTIONS', '/api/v1/providers');
        $this->assertSame(204, $options->status);
        $this->assertSame('GET, HEAD, OPTIONS', $options->header('Access-Control-Allow-Methods'));
    }

    /**
     * Map requests, the home page and the deprecated entry point are unaffected.
     *
     * @return void
     */
    public function testOtherPathsStillRender(): void
    {
        $map = $this->render(['latitude' => 45.5, 'longitude' => -122.6]);
        $this->assertSame('image/png', $map->header('Content-Type'));
        $this->assertSame('text/html; charset=utf-8', self::$app->get('/')->header('Content-Type'));
        $notApi = self::$app->get('/api-docs');
        $this->assertSame('text/html; charset=utf-8', $notApi->header('Content-Type'), 'not under /api/');
    }
}

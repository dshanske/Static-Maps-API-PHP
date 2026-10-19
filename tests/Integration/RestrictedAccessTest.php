<?php

/**
 * The restricted access mode (#28).
 */

namespace StaticMaps\Tests\Integration;

use StaticMaps\Tests\Support\RenderTestCase;

/**
 * Runs with tests/fixtures/config-restricted.php: every map needs a client key.
 */
final class RestrictedAccessTest extends RenderTestCase
{
    /**
     * Use the restricted configuration.
     *
     * @param string $tileServerUrl Base URL of the fake tile server.
     *
     * @return array<string, string> Environment variables.
     */
    protected static function appEnvironment(string $tileServerUrl): array
    {
        $restricted = ['STATIC_MAPS_CONFIG' => dirname(__DIR__) . '/fixtures/config-restricted.php'];

        return $restricted + parent::appEnvironment($tileServerUrl);
    }

    /**
     * Without a valid key every map request gets 403 before anything else is checked.
     *
     * @return void
     */
    public function testMapsNeedAKey(): void
    {
        $map = ['latitude' => 45.5, 'longitude' => -122.6, 'zoom' => 10];
        foreach ([[], ['key' => 'nope'], ['key' => 'disabled-key'], ['zoom' => 'abc']] as $extra) {
            $response = $this->render($extra + $map);
            $this->assertSame(403, $response->status, json_encode($extra));
            $this->assertSame("A valid key is required to draw maps on this server\n", $response->body);
            $this->assertSame('no-store', $response->header('Cache-Control'));
        }
        $this->assertSame([], $this->tileRequests());

        $this->assertSame(200, $this->render($map + ['key' => 'test-token'])->status);
        $limited = $this->render($map + ['key' => 'limited-key']);
        $this->assertSame(200, $limited->status, 'the default map is always allowed');
    }

    /**
     * The home page and the query API stay open.
     *
     * @return void
     */
    public function testHomePageAndApiStayOpen(): void
    {
        $this->assertSame(200, self::$app->get('/')->status);

        $root = self::$app->get('/api/v1/');
        $this->assertSame(200, $root->status);
        $this->assertSame(
            ['mode' => 'restricted', 'key_required' => true, 'key_valid' => false],
            ((array) json_decode($root->body, true))['access']
        );
        $providers = (array) json_decode(self::$app->get('/api/v1/providers')->body, true);
        $this->assertSame([], $providers['providers'], 'nothing is drawable without a key');
    }
}

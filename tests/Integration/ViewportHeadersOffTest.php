<?php

/**
 * The viewport_headers setting switched off (#101).
 */

namespace StaticMaps\Tests\Integration;

use StaticMaps\Tests\Support\RenderTestCase;

/**
 * Runs the renderer with tests/fixtures/config-no-viewport.php.
 */
final class ViewportHeadersOffTest extends RenderTestCase
{
    /**
     * Use the configuration without viewport headers.
     *
     * @param string $tileServerUrl Base URL of the fake tile server.
     *
     * @return array<string, string> Environment variables.
     */
    protected static function appEnvironment(string $tileServerUrl): array
    {
        $config = ['STATIC_MAPS_CONFIG' => dirname(__DIR__) . '/fixtures/config-no-viewport.php'];

        return $config + parent::appEnvironment($tileServerUrl);
    }

    /**
     * Images don't say what they show; /api/v1/plan still does.
     *
     * @return void
     */
    public function testNoHeaders(): void
    {
        $response = $this->render(['latitude' => 45.5, 'longitude' => -122.6, 'zoom' => 10]);

        $this->assertSame(200, $response->status);
        $this->assertNull($response->header('X-Map-Center'));
        $this->assertNull($response->header('X-Map-Zoom'));
        $this->assertNull($response->header('X-Map-Bounds'));

        $plan = json_decode(self::$app->get('/api/v1/plan?latitude=45.5&longitude=-122.6&zoom=10')->body, true);
        $this->assertSame(10, $plan['zoom']);
    }
}

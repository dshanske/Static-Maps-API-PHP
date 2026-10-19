<?php

/**
 * Renderer behaviour with an invalid install configuration.
 */

namespace StaticMaps\Tests\Integration;

use StaticMaps\Tests\Support\RenderTestCase;

/**
 * Runs the renderer with tests/fixtures/config-broken.php.
 */
final class ConfigErrorTest extends RenderTestCase
{
    /**
     * Point the renderer at the broken configuration.
     *
     * @param string $tileServerUrl Base URL of the fake tile server.
     *
     * @return array<string, string> Environment variables.
     */
    protected static function appEnvironment(string $tileServerUrl): array
    {
        $broken = ['STATIC_MAPS_CONFIG' => dirname(__DIR__) . '/fixtures/config-broken.php'];

        return $broken + parent::appEnvironment($tileServerUrl);
    }

    /**
     * The renderer logs the error and returns HTTP 500 instead of an image.
     *
     * @return void
     */
    public function testConfigErrorIsA500(): void
    {
        $response = self::$app->get('/?latitude=45&longitude=-122&zoom=10');

        $this->assertSame(500, $response->status);
        $this->assertSame("Server configuration error\n", $response->body);
        $this->assertSame([], $this->tileRequests());

        $errors = self::$app->errors();
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("Static Maps API: Default basemap 'no-such-basemap' is unknown", $errors[0]);
    }

    /**
     * A home_page file that doesn't exist is a configuration error, also for map requests.
     *
     * @return void
     */
    public function testMissingHomePageIsA500(): void
    {
        $missing = new \StaticMaps\Tests\Support\PhpServer(
            dirname(__DIR__, 2) . '/public',
            null,
            ['STATIC_MAPS_CONFIG' => dirname(__DIR__) . '/fixtures/config-home-missing.php']
                + parent::appEnvironment((string) self::$tiles?->url)
        );
        try {
            foreach (['/', '/?latitude=45&longitude=-122&zoom=10'] as $path) {
                $response = $missing->get($path);
                $this->assertSame(500, $response->status, $path);
                $this->assertSame("Server configuration error\n", $response->body, $path);
            }
            $errors = $missing->errors();
            $this->assertCount(2, $errors);
            $this->assertStringContainsString("'home_page' must name a readable file", $errors[0]);
        } finally {
            $missing->stop();
        }
    }
}

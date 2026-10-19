<?php

/**
 * The home page setting.
 */

namespace StaticMaps\Tests\Integration;

use StaticMaps\Tests\Support\RenderTestCase;

/**
 * Runs the renderer with tests/fixtures/config-home.php.
 */
final class CustomHomePageTest extends RenderTestCase
{
    /**
     * Use the configuration with a custom home page.
     *
     * @param string $tileServerUrl Base URL of the fake tile server.
     *
     * @return array<string, string> Environment variables.
     */
    protected static function appEnvironment(string $tileServerUrl): array
    {
        $home = ['STATIC_MAPS_CONFIG' => dirname(__DIR__) . '/fixtures/config-home.php'];

        return $home + parent::appEnvironment($tileServerUrl);
    }

    /**
     * The install's page is served byte for byte, and its PHP is not run.
     *
     * @return void
     */
    public function testCustomHomePage(): void
    {
        $response = self::$app->get('/');

        $this->assertSame(200, $response->status);
        $this->assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        $this->assertSame(file_get_contents(dirname(__DIR__) . '/fixtures/home-custom.html'), $response->body);
    }
}

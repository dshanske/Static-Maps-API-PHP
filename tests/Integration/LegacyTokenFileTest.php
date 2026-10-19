<?php

/**
 * The deprecated token file (#28).
 */

namespace StaticMaps\Tests\Integration;

use StaticMaps\Tests\Support\RenderTestCase;

/**
 * Runs with tests/fixtures/config-legacy-tokens.php, which still sets `token_file`.
 */
final class LegacyTokenFileTest extends RenderTestCase
{
    /**
     * Use the configuration with the token file.
     *
     * @param string $tileServerUrl Base URL of the fake tile server.
     *
     * @return array<string, string> Environment variables.
     */
    protected static function appEnvironment(string $tileServerUrl): array
    {
        $legacy = ['STATIC_MAPS_CONFIG' => dirname(__DIR__) . '/fixtures/config-legacy-tokens.php'];

        return $legacy + parent::appEnvironment($tileServerUrl);
    }

    /**
     * The deprecation message is expected here.
     *
     * @return list<string> Patterns of log lines that don't fail a render.
     */
    protected static function knownErrors(): array
    {
        $deprecated = '/Static Maps API: the token file \(token_file, .*\) is deprecated/';

        return array_merge(parent::knownErrors(), [$deprecated]);
    }

    /**
     * A token from the file still works as a key, and each request logs the deprecation.
     *
     * @return void
     */
    public function testTokensStillWork(): void
    {
        $map = ['latitude' => 45.5, 'longitude' => -122.6, 'zoom' => 10, 'provider' => 'secret'];
        $this->assertNull($this->render($map + ['key' => 'legacy-token'])->header('X-Map-Fallback'));

        self::$app->get('/?latitude=45&longitude=-122&zoom=10');
        $logged = implode("\n", self::$app->errors());
        $this->assertStringContainsString('php bin/clients.php import', $logged);
    }
}

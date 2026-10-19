<?php

/**
 * Install files in the deprecated pre-#40 settings format.
 */

namespace StaticMaps\Tests\Integration;

use StaticMaps\Tests\Support\RenderTestCase;

/**
 * Runs the renderer with tests/fixtures/config-legacy.php.
 */
final class LegacyConfigTest extends RenderTestCase
{
    /**
     * Use the configuration in the old format.
     *
     * @param string $tileServerUrl Base URL of the fake tile server.
     *
     * @return array<string, string> Environment variables.
     */
    protected static function appEnvironment(string $tileServerUrl): array
    {
        $legacy = ['STATIC_MAPS_CONFIG' => dirname(__DIR__) . '/fixtures/config-legacy.php'];

        return $legacy + parent::appEnvironment($tileServerUrl);
    }

    /**
     * The deprecation messages are expected here.
     *
     * @return list<string> Patterns of log lines that don't fail a render.
     */
    protected static function knownErrors(): array
    {
        $deprecated = '/Static Maps API: Setting \'(basemaps|defaults\.basemap)\' is deprecated/';

        return array_merge(parent::knownErrors(), [$deprecated]);
    }

    /**
     * Old basemaps, aliases and the old default keep working, and each request logs the deprecations.
     *
     * @return void
     */
    public function testOldFormatStillWorks(): void
    {
        $center = ['latitude' => 45.51404592560425, 'longitude' => -122.684326171875, 'zoom' => 14];

        // defaults.basemap → the install's own basemap (base + overlay).
        $this->assertPixel([255, 255, 255], $this->render($center)->image(), 150, 150);
        $errors = self::$app?->errors();
        $this->assertSame([], $errors, 'render() already consumed and allowed the messages');

        // An install-defined alias of a fixture basemap.
        $aliased = $this->render($center + ['basemap' => 'legacy-alias'])->image();
        $this->assertPixel(self::tileColor(2608, 5860), $aliased, 150, 150);

        // An override of an existing basemap id applies to its style.
        $this->setUp();
        $this->render($center + ['basemap' => 'fixture-zoom']);
        $this->assertStringStartsWith('/base/8/', $this->tilePaths()[0]);
    }

    /**
     * Each request logs one line per deprecated setting.
     *
     * @return void
     */
    public function testDeprecationsAreLogged(): void
    {
        self::$app?->get('/?latitude=45&longitude=-122&zoom=10');
        $errors = array_values(array_filter(
            self::$app?->errors() ?? [],
            static fn (string $line): bool => str_contains($line, 'Static Maps API:')
        ));

        $this->assertCount(2, $errors);
        $this->assertStringContainsString("Setting 'basemaps' is deprecated", $errors[0]);
        $this->assertStringContainsString("Setting 'defaults.basemap' is deprecated", $errors[1]);
    }
}

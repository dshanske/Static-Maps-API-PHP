<?php

/**
 * The zoom.max_limit setting (#54).
 */

namespace StaticMaps\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use StaticMaps\Tests\Support\RenderTestCase;

/**
 * Runs the renderer with tests/fixtures/config-zoom-limit.php (max_limit 11).
 */
final class ZoomLimitTest extends RenderTestCase
{
    /**
     * Use the configuration with the zoom cap.
     *
     * @param string $tileServerUrl Base URL of the fake tile server.
     *
     * @return array<string, string> Environment variables.
     */
    protected static function appEnvironment(string $tileServerUrl): array
    {
        $limited = ['STATIC_MAPS_CONFIG' => dirname(__DIR__) . '/fixtures/config-zoom-limit.php'];

        return $limited + parent::appEnvironment($tileServerUrl);
    }

    /**
     * Requests and the zoom their tiles should be fetched at.
     *
     * @return array<string, array{array<string, mixed>, int}> Parameters and tile zoom.
     */
    public static function cases(): array
    {
        $portland = ['latitude' => 45.5165, 'longitude' => -122.6764];

        return [
            'style without maxzoom' => [$portland + ['zoom' => 16], 11],
            'style with maxzoom 10' => [$portland + ['zoom' => 16, 'style' => 'zoom'], 10],
            'below the cap'         => [$portland + ['zoom' => 9], 9],
            'custom tile URL'       => [
                $portland + ['zoom' => 16, 'provider' => 'custom', 'key' => 'test-token',
                    'tileurl' => '__TILES__/base/{z}/{x}/{y}.png'],
                11,
            ],
        ];
    }

    /**
     * No map is drawn above zoom.max_limit, whatever its own maximum.
     *
     * @param array<string, mixed> $params Request parameters.
     * @param int                  $zoom   Expected tile zoom.
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testZoomIsCapped(array $params, int $zoom): void
    {
        $params = str_replace('__TILES__', (string) self::$tiles?->url, $params);
        $this->render($params);

        $zooms = array_unique(array_map(
            static fn (string $p): int => (int) explode('/', $p)[2],
            $this->tilePaths()
        ));
        $this->assertSame([$zoom], array_values($zooms));
    }
}

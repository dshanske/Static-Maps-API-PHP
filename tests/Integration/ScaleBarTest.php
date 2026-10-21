<?php

/**
 * The scale bar on rendered maps (#102).
 */

namespace StaticMaps\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use StaticMaps\Tests\Support\RenderTestCase;

/**
 * `scalebar` and `scalebar_position`, drawn on the fixture map.
 */
final class ScaleBarTest extends RenderTestCase
{
    /** @var array<string, mixed> Downtown Portland at zoom 14, 400 x 250. */
    private const MAP = [
        'latitude' => 45.5165, 'longitude' => -122.6764, 'zoom' => 14, 'width' => 400, 'height' => 250,
    ];

    /**
     * Scale bars drawn in each corner, next to the credit and at scale 2.
     *
     * @return array<string, array{array<string, mixed>, string}> Extra parameters and snapshot name.
     */
    public static function drawnCases(): array
    {
        return [
            'metric, bottom left'        => [['scalebar' => 'metric'], 'scalebar-metric.png'],
            'both, above the credit'     => [
                ['scalebar' => 'both', 'scalebar_position' => 'bottomright', 'style' => 'credited'],
                'scalebar-both-credit.png',
            ],
            'imperial, top left'         => [
                ['scalebar' => 'imperial', 'scalebar_position' => 'topleft'],
                'scalebar-imperial-topleft.png',
            ],
            'both, scale 2'              => [['scalebar' => 'both', 'scale' => 2], 'scalebar-scale2.png'],
        ];
    }

    /**
     * The scale bar is drawn, clear of the attribution.
     *
     * @param array<string, mixed> $params   Extra request parameters.
     * @param string               $snapshot Snapshot file name.
     *
     * @return void
     */
    #[DataProvider('drawnCases')]
    public function testDrawn(array $params, string $snapshot): void
    {
        $response = $this->render(self::MAP + $params);

        $this->assertSame(200, $response->status);
        $this->assertNull($response->header('X-Map-Scalebar'));
        $this->assertMatchesSnapshot($snapshot, $response->body);
    }

    /**
     * Without `scalebar`, nothing changes; with it, only its corner does.
     *
     * @return void
     */
    public function testOnlyItsCornerChanges(): void
    {
        $plain = $this->render(self::MAP)->image();
        $barred = $this->render(self::MAP + ['scalebar' => 'metric'])->image();

        $changed = [];
        for ($x = 0; $x < 400; $x += 4) {
            for ($y = 0; $y < 250; $y += 4) {
                if (imagecolorat($plain, $x, $y) !== imagecolorat($barred, $x, $y)) {
                    $changed[] = [$x, $y];
                }
            }
        }
        $this->assertNotEmpty($changed);
        foreach ($changed as [$x, $y]) {
            $this->assertTrue($x < 120 && $y > 220, "pixel $x,$y outside the bottom-left corner");
        }
    }

    /**
     * Where one scale would mislead, the bar is left out with a notice.
     *
     * @return void
     */
    public function testLeftOutAtLowZoom(): void
    {
        $response = $this->render(['latitude' => 10, 'longitude' => 0, 'zoom' => 3, 'width' => 400, 'height' => 250,
            'scalebar' => 'metric']);

        $this->assertSame(200, $response->status);
        $this->assertSame(
            'left out; the scale changes by 12% across this map (at most 10%)',
            $response->header('X-Map-Scalebar')
        );
        $this->assertSame(
            $this->render(['latitude' => 10, 'longitude' => 0, 'zoom' => 3, 'width' => 400, 'height' => 250])->body,
            $response->body
        );
    }

    /**
     * Unknown values get a 400, from the image and from /api/v1/plan.
     *
     * @return void
     */
    public function testInvalidValues(): void
    {
        $response = $this->render(self::MAP + ['scalebar' => 'nautical']);
        $this->assertSame(400, $response->status);
        $this->assertSame('scalebar must be metric, imperial, both', trim($response->body));

        $response = $this->render(self::MAP + ['scalebar' => 'metric', 'scalebar_position' => 'middle']);
        $this->assertSame(400, $response->status);
        $this->assertStringStartsWith('scalebar_position must be', trim($response->body));

        $this->assertSame(400, self::$app->get('/api/v1/plan?latitude=45&longitude=-122&scalebar=x')->status);
    }
}

<?php

/**
 * Scale bar maths (#102).
 */

namespace StaticMaps\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StaticMaps\ScaleBar;

/**
 * StaticMaps\ScaleBar: ground distance, round lengths, labels and the variation rule.
 */
final class ScaleBarTest extends TestCase
{
    /**
     * Web Mercator's scale: 156543 m per pixel at zoom 0 on the equator, halving each zoom and with cos(lat).
     *
     * @return void
     */
    public function testMetresPerPixel(): void
    {
        $this->assertEqualsWithDelta(156543.034, ScaleBar::metresPerPixel(0, 0), 0.001);
        $this->assertEqualsWithDelta(156543.034 / 1024, ScaleBar::metresPerPixel(0, 10), 0.001);
        $this->assertEqualsWithDelta(156543.034 / 2, ScaleBar::metresPerPixel(60, 0), 0.001);
    }

    /**
     * Round numbers: 1, 2 or 5 times a power of ten, never above the value.
     *
     * @return array<string, array{float, float}> Value and its round number.
     */
    public static function roundCases(): array
    {
        return [
            'of 1'    => [1.0, 1.0],
            'of 1.9'  => [1.9, 1.0],
            'of 2'    => [2.0, 2.0],
            'of 4.99' => [4.99, 2.0],
            'of 5'    => [5.0, 5.0],
            'of 9.9'  => [9.9, 5.0],
            'of 150'  => [150.0, 100.0],
            'of 2400' => [2400.0, 2000.0],
            'of 0.07' => [0.07, 0.05],
        ];
    }

    /**
     * round() picks the largest round number not above the value.
     *
     * @param float $value    The value.
     * @param float $expected Its round number.
     *
     * @return void
     */
    #[DataProvider('roundCases')]
    public function testRound(float $value, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, ScaleBar::round($value), 1e-9);
    }

    /**
     * Bars: at most a quarter of the width (40-150 px), metric first, m or km, ft or mi.
     *
     * @return void
     */
    public function testBars(): void
    {
        // 1 m per pixel, 600 px wide: up to 150 px = 150 m -> 100 m; 492 ft -> 200 ft.
        $bars = ScaleBar::bars('both', 1.0, 600);
        $this->assertSame(['100 m', '200 ft'], array_column($bars, 'label'));
        $this->assertEqualsWithDelta([100.0, 60.96], array_column($bars, 'length'), 1e-9);

        // 100 m per pixel: up to 15 km -> 10 km; 9.3 mi -> 5 mi.
        $bars = ScaleBar::bars('both', 100.0, 600);
        $this->assertSame(['10 km', '5 mi'], array_column($bars, 'label'));
        $this->assertEqualsWithDelta(5 * 1609.344 / 100, $bars[1]['length'], 1e-9);

        // A quarter of 400 px is 100 px (100 m); a 100 px map still gets 40 px (40 m -> 20 m).
        $this->assertSame(['100 m'], array_column(ScaleBar::bars('metric', 1.0, 400), 'label'));
        $this->assertSame(['20 m'], array_column(ScaleBar::bars('metric', 1.0, 100), 'label'));
        $this->assertSame(['100 ft'], array_column(ScaleBar::bars('imperial', 1.0, 100), 'label'));
    }

    /**
     * variation() compares the scale at the top and bottom edges with the centre's.
     *
     * @return void
     */
    public function testVariation(): void
    {
        $this->assertEqualsWithDelta(1 - cos(deg2rad(10)), ScaleBar::variation(0, -10, 10), 1e-9);
        $this->assertEqualsWithDelta(
            1 - cos(deg2rad(50)) / cos(deg2rad(45)),
            ScaleBar::variation(45, 40, 50),
            1e-9
        );
        $this->assertLessThan(ScaleBar::MAX_VARIATION, ScaleBar::variation(45.5, 45.49, 45.53));
    }
}

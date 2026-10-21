<?php

/**
 * Tests for include/MarkerIcon.php.
 */

namespace StaticMaps\Tests\Unit;

use GdImage;
use PHPUnit\Framework\TestCase;
use StaticMaps\MarkerIcon;

/**
 * Placing icons: size, anchor, rotation and opacity (#37).
 */
final class MarkerIconTest extends TestCase
{
    /**
     * A 20 x 30 opaque icon.
     *
     * @return GdImage The icon.
     */
    private static function icon(): GdImage
    {
        $im = imagecreatetruecolor(20, 30);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 200, 0, 0));

        return $im;
    }

    /**
     * Default and named anchors, at different sizes.
     *
     * @return void
     */
    public function testAnchors(): void
    {
        $marker = ['iconImg' => self::icon(), 'icon' => 'x'];
        [$im, $ax, $ay] = MarkerIcon::place($marker, 300, 300, 1);
        $this->assertSame([20, 30, 10.0, 30.0], [imagesx($im), imagesy($im), $ax, $ay], 'bottom centre');

        [, $ax, $ay] = MarkerIcon::place(['icon' => 'dot-x'] + $marker, 300, 300, 1);
        $this->assertSame([10.0, 15.0], [$ax, $ay], 'dot- icons are centred');

        [$im, $ax, $ay] = MarkerIcon::place(['anchor' => ['frac', 0.0, 0.0], 'scale' => 2.0] + $marker, 300, 300, 1);
        $this->assertSame([40, 60, 0.0, 0.0], [imagesx($im), imagesy($im), $ax, $ay], 'scale 2, top left');

        [$im, $ax, $ay] = MarkerIcon::place(['anchor' => ['px', 4.0, 6.0]] + $marker, 300, 300, 2);
        $this->assertSame([40, 60, 8.0, 12.0], [imagesx($im), imagesy($im), $ax, $ay], 'request scale 2');

        [$im] = MarkerIcon::place(['density' => 2.0] + $marker, 300, 300, 2);
        $this->assertSame([20, 30], [imagesx($im), imagesy($im)], 'drawn at density 2 for scale 2: as is');

        [$im] = MarkerIcon::place($marker, 100, 100, 1);
        $this->assertSame([13, 20], [imagesx($im), imagesy($im)], 'small maps shrink icons by 1.5');
    }

    /**
     * Rotation turns the icon about its anchor, which stays on the point.
     *
     * @return void
     */
    public function testRotation(): void
    {
        $marker = ['iconImg' => self::icon(), 'icon' => 'x', 'rotation' => 90.0];
        [$im, $ax, $ay] = MarkerIcon::place($marker, 300, 300, 1);

        // A quarter turn clockwise: 30 wide and 20 high, the bottom-centre anchor now on the left edge.
        $this->assertEqualsWithDelta([30, 20], [imagesx($im), imagesy($im)], 1);
        $this->assertEqualsWithDelta(0.0, $ax, 1.0);
        $this->assertEqualsWithDelta(10.0, $ay, 1.0);
    }

    /**
     * Opacity makes every pixel more transparent.
     *
     * @return void
     */
    public function testOpacity(): void
    {
        [$im] = MarkerIcon::place(['iconImg' => self::icon(), 'icon' => 'x', 'opacity' => 0.5], 300, 300, 1);

        $this->assertEqualsWithDelta(64, imagecolorsforindex($im, imagecolorat($im, 10, 10))['alpha'], 1);
    }
}

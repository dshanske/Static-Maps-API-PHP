<?php

/**
 * Tests for include/Bounds.php.
 */

namespace StaticMaps\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StaticMaps\Bounds;

/**
 * The bounding box of markers and paths.
 */
final class BoundsTest extends TestCase
{
    /**
     * An empty box is centred on 0,0.
     *
     * @return void
     */
    public function testEmptyCenter(): void
    {
        $this->assertEquals(['lat' => 0, 'lng' => 0], (new Bounds())->center());
    }

    /**
     * The box grows to include every point, and its centre is the middle of the box.
     *
     * @return void
     */
    public function testExtend(): void
    {
        $bounds = new Bounds();
        $bounds->extend(45.5, -122.7);
        $bounds->extend('45.6', '-122.5');
        $bounds->extend(45.55, -122.6);

        $this->assertSame(45.5, $bounds->minLat);
        $this->assertSame('45.6', $bounds->maxLat);
        $this->assertSame(-122.7, $bounds->minLng);
        $this->assertSame('-122.5', $bounds->maxLng);

        $center = $bounds->center();
        $this->assertEqualsWithDelta(45.55, $center['lat'], 1e-9);
        $this->assertEqualsWithDelta(-122.6, $center['lng'], 1e-9);
    }

    /**
     * A box is empty until the first point is added.
     *
     * @return void
     */
    public function testIsEmpty(): void
    {
        $bounds = new Bounds();
        $this->assertTrue($bounds->isEmpty());

        $bounds->extend(0, 0);
        $this->assertFalse($bounds->isEmpty());
    }

    /**
     * One point gives a zero-size box centred on it.
     *
     * @return void
     */
    public function testSinglePoint(): void
    {
        $bounds = new Bounds();
        $bounds->extend(10, 20);

        $this->assertEquals(['lat' => 10, 'lng' => 20], $bounds->center());
    }
}

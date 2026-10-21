<?php

/**
 * Tests for include/Bounds.php.
 */

namespace StaticMaps\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Longitude stretches and the shortest span covering them (#35).
     *
     * @return array<string, array{callable(Bounds): void, array{float, float}}> How to fill the
     *         bounds, and the expected [west, east].
     */
    public static function spans(): array
    {
        return [
            'plain'                => [static function (Bounds $b): void {
                $b->extend(45.5, -122.7);
                $b->extend(45.6, -122.5);
            }, [-122.7, -122.5]],
            'japan and alaska'     => [static function (Bounds $b): void {
                $b->extend(35, 139.7);
                $b->extend(61, -150);
            }, [139.7, 210.0]],
            'path the short way'   => [
                static fn (Bounds $b) => $b->extendPath([[139.7, 35], [-150, 61]]),
                [139.7, 210.0],
            ],
            'point inside a span'  => [static function (Bounds $b): void {
                $b->extendPath([[170, 0], [-170, 0]]);
                $b->extend(0, -175);
            }, [170.0, 190.0]],
            'bbox across'          => [static fn (Bounds $b) => $b->extendArea(-10, 170, 10, -170), [170.0, 190.0]],
            'wide bbox not across' => [static fn (Bounds $b) => $b->extendArea(-10, -170, 10, 170), [-170.0, 170.0]],
            '180 apart: no cross'  => [static function (Bounds $b): void {
                $b->extend(0, -90);
                $b->extend(0, 90);
            }, [-90.0, 90.0]],
            'fiji'                 => [static function (Bounds $b): void {
                $b->extend(-17, 178);
                $b->extend(-18, -179.5);
            }, [178.0, 180.5]],
            // The crossing segment's end misses -179.14... + 360 by a rounding error (#93).
            'rounding at 180'      => [
                static fn (Bounds $b) => $b->extendPath([[170, 50], [179.13361088874973, 50],
                    [-179.14374802213686, 50], [-170, 50]]),
                [170.0, 190.0],
            ],
            'all the way round'    => [
                static fn (Bounds $b) => $b->extendPath([[0, 0], [120, 0], [-120, 0], [0, 0]]),
                [-180.0, 180.0],
            ],
        ];
    }

    /**
     * span() is the shortest stretch covering everything; it crosses the antimeridian only when shorter.
     *
     * @param callable(Bounds): void $fill     Adds points or spans.
     * @param array{float, float}    $expected West and east edges.
     *
     * @return void
     */
    #[DataProvider('spans')]
    public function testSpan(callable $fill, array $expected): void
    {
        $bounds = new Bounds();
        $fill($bounds);

        $this->assertEqualsWithDelta($expected, $bounds->span(), 1e-9);
    }

    /**
     * The centre of a span across the antimeridian is wrapped into -180..180.
     *
     * @return void
     */
    public function testCenterAcrossTheAntimeridian(): void
    {
        $bounds = new Bounds();
        $bounds->extend(-17, 178);
        $bounds->extend(-18, -176);

        $this->assertEqualsWithDelta(['lat' => -17.5, 'lng' => -179.0], $bounds->center(), 1e-9);
    }

    /**
     * wrap() and delta().
     *
     * @return void
     */
    public function testWrapAndDelta(): void
    {
        $this->assertSame(180.0, Bounds::wrap(180.0));
        $this->assertEqualsWithDelta(-170.0, Bounds::wrap(190.0), 1e-9);
        $this->assertEqualsWithDelta(170.0, Bounds::wrap(-190.0), 1e-9);
        $this->assertEqualsWithDelta(10.0, Bounds::wrap(730.0), 1e-9);
        $this->assertEqualsWithDelta(20.0, Bounds::delta(170, -170), 1e-9);
        $this->assertEqualsWithDelta(-20.0, Bounds::delta(-170, 170), 1e-9);
        $this->assertEqualsWithDelta(10.0, Bounds::delta(0, 10), 1e-9);
    }
}

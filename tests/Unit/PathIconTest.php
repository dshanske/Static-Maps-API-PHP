<?php

/**
 * Positions along a path for vehicle icons (#106).
 */

namespace StaticMaps\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StaticMaps\PathIcon;

/**
 * StaticMaps\PathIcon::along().
 */
final class PathIconTest extends TestCase
{
    /**
     * On a straight line: the ends, the middle, and its direction (clockwise from east, y down).
     *
     * @return void
     */
    public function testStraightLine(): void
    {
        $line = [['x' => 0, 'y' => 0], ['x' => 100, 'y' => 0]];

        $this->assertEqualsWithDelta(['x' => 50.0, 'y' => 0.0, 'angle' => 0.0], PathIcon::along($line, 50), 1e-9);
        $this->assertEqualsWithDelta(['x' => 0.0, 'y' => 0.0, 'angle' => 0.0], PathIcon::along($line, 0), 1e-9);
        $this->assertEqualsWithDelta(['x' => 100.0, 'y' => 0.0, 'angle' => 0.0], PathIcon::along($line, 100), 1e-9);

        $up = [['x' => 0, 'y' => 100], ['x' => 0, 'y' => 0]];
        $this->assertEqualsWithDelta(-90.0, PathIcon::along($up, 50)['angle'] ?? null, 1e-9);
        $west = [['x' => 100, 'y' => 0], ['x' => 0, 'y' => 0]];
        $this->assertEqualsWithDelta(180.0, abs(PathIcon::along($west, 50)['angle'] ?? 0), 1e-9);
    }

    /**
     * Along several segments, by length: 75% of an L of two 100 px legs is half way along the second.
     *
     * @return void
     */
    public function testBySegmentLength(): void
    {
        $line = [['x' => 0, 'y' => 0], ['x' => 100, 'y' => 0], ['x' => 100, 'y' => 100]];

        $this->assertEqualsWithDelta(['x' => 100.0, 'y' => 50.0, 'angle' => 90.0], PathIcon::along($line, 75), 1e-9);
        $this->assertEqualsWithDelta(['x' => 50.0, 'y' => 0.0, 'angle' => 0.0], PathIcon::along($line, 25), 1e-9);
    }

    /**
     * Repeated points are skipped; a line without length has nowhere to put an icon.
     *
     * @return void
     */
    public function testDegenerateLines(): void
    {
        $line = [['x' => 0, 'y' => 0], ['x' => 0, 'y' => 0], ['x' => 10, 'y' => 0], ['x' => 10, 'y' => 0]];
        $this->assertEqualsWithDelta(['x' => 5.0, 'y' => 0.0, 'angle' => 0.0], PathIcon::along($line, 50), 1e-9);
        $this->assertEqualsWithDelta(['x' => 10.0, 'y' => 0.0, 'angle' => 0.0], PathIcon::along($line, 100), 1e-9);

        $this->assertNull(PathIcon::along([['x' => 3, 'y' => 3], ['x' => 3, 'y' => 3]], 50));
        $this->assertNull(PathIcon::along([['x' => 3, 'y' => 3]], 50));
    }
}

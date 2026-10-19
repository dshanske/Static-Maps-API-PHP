<?php

/**
 * Tests for include/Polyline.php.
 */

namespace StaticMaps\Tests\Unit;

use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Polyline;

/**
 * Google encoded polyline decoding and encoding (precision 1e-5).
 */
final class PolylineTest extends TestCase
{
    /** @var string Google's documented example polyline. */
    private const EXAMPLE = '_p~iF~ps|U_ulLnnqC_mqNvxq`@';

    /** @var list<float> The points of EXAMPLE as a flat list of numbers. */
    private const EXAMPLE_POINTS = [38.5, -120.2, 40.7, -120.95, 43.252, -126.453];

    /**
     * decode() returns a flat list of numbers in the order they were encoded.
     *
     * On PHP 8.5 decode() triggers "ord(): Providing a string that is not one
     * byte long is deprecated" (#18); remove IgnoreDeprecations once fixed.
     *
     * @return void
     */
    #[IgnoreDeprecations]
    public function testDecode(): void
    {
        $decoded = Polyline::decode(self::EXAMPLE);

        $this->assertCount(6, $decoded);
        foreach (self::EXAMPLE_POINTS as $i => $expected) {
            $this->assertEqualsWithDelta($expected, $decoded[$i], 1e-9);
        }
    }

    /**
     * encode() accepts pairs and is the inverse of decode().
     *
     * @return void
     */
    public function testEncode(): void
    {
        $this->assertSame(self::EXAMPLE, Polyline::encode(Polyline::pair(self::EXAMPLE_POINTS)));
        $this->assertSame(self::EXAMPLE, Polyline::encode(self::EXAMPLE_POINTS));
    }

    /**
     * An empty string decodes to no points.
     *
     * @return void
     */
    public function testDecodeEmpty(): void
    {
        $this->assertSame([], Polyline::decode(''));
    }

    /**
     * pair() groups a flat list into pairs.
     *
     * @return void
     */
    public function testPair(): void
    {
        $this->assertSame([[1, 2], [3, 4], [5]], Polyline::pair([1, 2, 3, 4, 5]));
    }

    /**
     * flatten() turns nested arrays into one list.
     *
     * @return void
     */
    public function testFlatten(): void
    {
        $this->assertSame([1, 2, 3, 4], Polyline::flatten([[1, 2], [3, [4]]]));
    }
}

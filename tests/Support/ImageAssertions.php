<?php

/**
 * PHPUnit assertions for rendered map images.
 */

namespace StaticMaps\Tests\Support;

use GdImage;
use PHPUnit\Framework\Assert;

/**
 * Pixel and snapshot assertions for GD images.
 *
 * Snapshots are reference images in tests/fixtures/expected/. They are compared
 * with a tolerance, because anti-aliased paths drawn by ImageMagick differ
 * slightly between ImageMagick versions. Run the tests with
 * `UPDATE_SNAPSHOTS=1` to (re)write the reference images after an intended
 * change in output, then review the new images before committing them.
 *
 * When a comparison fails, the rendered image is saved to the directory named
 * by SNAPSHOT_FAILURES_DIR (default: `static-maps-snapshots` in the system
 * temp directory) so it can be compared with the reference by eye.
 */
trait ImageAssertions
{
    /**
     * Read one pixel.
     *
     * @param GdImage $im The image.
     * @param int     $x  Column, 0 = left edge.
     * @param int     $y  Row, 0 = top edge.
     *
     * @return array{int, int, int} Red, green and blue, 0-255.
     */
    protected static function rgbAt(GdImage $im, int $x, int $y): array
    {
        $c = imagecolorsforindex($im, imagecolorat($im, $x, $y));

        return [$c['red'], $c['green'], $c['blue']];
    }

    /**
     * Assert that a pixel has (close to) the expected colour.
     *
     * @param array{int, int, int} $expected  Red, green and blue, 0-255.
     * @param GdImage              $im        The image.
     * @param int                  $x         Column.
     * @param int                  $y         Row.
     * @param int                  $tolerance Largest allowed difference per channel.
     *
     * @return void
     */
    protected static function assertPixel(array $expected, GdImage $im, int $x, int $y, int $tolerance = 0): void
    {
        $actual = self::rgbAt($im, $x, $y);
        $diff = max(array_map(static fn (int $a, int $b): int => abs($a - $b), $expected, $actual));
        Assert::assertLessThanOrEqual(
            $tolerance,
            $diff,
            sprintf(
                'Pixel (%d, %d) is rgb(%s), expected rgb(%s)',
                $x,
                $y,
                implode(',', $actual),
                implode(',', $expected)
            )
        );
    }

    /**
     * Assert that a rendered image matches its reference image.
     *
     * A pixel counts as different when any channel differs by more than
     * $channelTolerance; the assertion fails when more than $maxDifferentRatio
     * of all pixels differ, or when the sizes differ.
     *
     * @param string  $name              Snapshot file name in tests/fixtures/expected/, e.g. `markers.png`.
     * @param string  $body              The rendered image file (PNG or JPEG bytes).
     * @param int     $channelTolerance  Per-channel difference treated as equal, 0-255.
     * @param float   $maxDifferentRatio Share of pixels that may differ, 0-1.
     *
     * @return void
     */
    protected static function assertMatchesSnapshot(
        string $name,
        string $body,
        int $channelTolerance = 48,
        float $maxDifferentRatio = 0.005
    ): void {
        $file = __DIR__ . '/../fixtures/expected/' . $name;
        if (getenv('UPDATE_SNAPSHOTS') === '1') {
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            file_put_contents($file, $body);
        }
        Assert::assertFileExists($file, "Missing snapshot $name; run the tests with UPDATE_SNAPSHOTS=1 to create it");

        $expected = imagecreatefromstring((string) file_get_contents($file));
        $actual = @imagecreatefromstring($body);
        Assert::assertInstanceOf(GdImage::class, $expected, "Snapshot $name is not an image");
        Assert::assertInstanceOf(GdImage::class, $actual, 'Rendered output is not an image');

        $w = imagesx($expected);
        $h = imagesy($expected);
        Assert::assertSame([$w, $h], [imagesx($actual), imagesy($actual)], "Image size differs from $name");

        $different = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $a = imagecolorsforindex($expected, imagecolorat($expected, $x, $y));
                $b = imagecolorsforindex($actual, imagecolorat($actual, $x, $y));
                if (
                    abs($a['red'] - $b['red']) > $channelTolerance
                    || abs($a['green'] - $b['green']) > $channelTolerance
                    || abs($a['blue'] - $b['blue']) > $channelTolerance
                ) {
                    $different++;
                }
            }
        }

        $message = sprintf('%d of %d pixels differ from snapshot %s', $different, $w * $h, $name);
        if ($different > $maxDifferentRatio * $w * $h) {
            $message .= '; rendered image saved as ' . self::saveFailure($name, $body);
        }
        Assert::assertLessThanOrEqual($maxDifferentRatio * $w * $h, $different, $message);
    }

    /**
     * Save a rendered image that did not match its snapshot.
     *
     * @param string $name Snapshot file name.
     * @param string $body The rendered image file.
     *
     * @return string Path of the saved file.
     */
    private static function saveFailure(string $name, string $body): string
    {
        $dir = getenv('SNAPSHOT_FAILURES_DIR') ?: sys_get_temp_dir() . '/static-maps-snapshots';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $file = $dir . '/' . $name;
        file_put_contents($file, $body);

        return $file;
    }
}

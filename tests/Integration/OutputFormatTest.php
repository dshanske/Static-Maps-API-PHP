<?php

/**
 * Output formats, overlay limits and error responses (#39).
 */

namespace StaticMaps\Tests\Integration;

use StaticMaps\Tests\Support\RenderTestCase;

/**
 * `format=webp` and `png8`, and lenient handling of bad overlays (limits: StrictErrorsTest).
 */
final class OutputFormatTest extends RenderTestCase
{
    /** @var array<string, mixed> A small map in Portland. */
    private const MAP = [
        'latitude'  => 45.5165,
        'longitude' => -122.6764,
        'zoom'      => 14,
        'width'     => 200,
        'height'    => 150,
    ];

    /**
     * WebP, when GD supports it, with the quality applied.
     *
     * @return void
     */
    public function testWebp(): void
    {
        if (!function_exists('imagewebp') || (gd_info()['WebP Support'] ?? false) !== true) {
            $response = $this->render(self::MAP + ['format' => 'webp']);
            $this->assertSame('image/png', $response->header('Content-Type'));
            $this->assertStringContainsString('not available', (string) $response->header('X-Map-Format'));
            return;
        }
        $low = $this->render(self::MAP + ['format' => 'webp', 'quality' => 10]);
        $high = $this->render(self::MAP + ['format' => 'webp', 'quality' => 95]);

        $this->assertSame(200, $low->status);
        $this->assertSame('image/webp', $low->header('Content-Type'));
        $this->assertSame('RIFF', substr($low->body, 0, 4));
        $this->assertSame('WEBP', substr($low->body, 8, 4));
        $this->assertLessThan(strlen($high->body), strlen($low->body));
        $this->assertSame([200, 150], [imagesx($high->image()), imagesy($high->image())]);
        $this->assertPixel(self::tileColor(2608, 5860), $high->image(), 100, 75, 8);
    }

    /**
     * png8 is a palette PNG, smaller than the full-colour one, with the same picture.
     *
     * @return void
     */
    public function testPng8(): void
    {
        $query = self::MAP + ['path[]' => 'color:ff0000;weight:4;[-122.69,45.51][-122.66,45.52]'];
        $full = $this->render($query);
        $palette = $this->render($query + ['format' => 'png8']);

        $this->assertSame('image/png', $palette->header('Content-Type'));
        $this->assertSame(3, ord($palette->body[25]), 'colour type 3 = palette');
        $this->assertLessThan(strlen($full->body), strlen($palette->body));
        $this->assertPixel(self::tileColor(2608, 5860), $palette->image(), 100, 10, 8);
    }

    /**
     * By default a bad marker or path is left out with a header (also nested arrays, which used to crash).
     *
     * @return void
     */
    public function testBadOverlaysAreLeftOut(): void
    {
        $response = $this->render(self::MAP + [
            'marker' => [['nested'], 'lat:45.5;lng:-122.6;icon:no-such-icon'],
            'path'   => [['nested'], 'color:ff0000'],
        ]);

        $this->assertSame(200, $response->status);
        $this->assertSame('not a marker (key:value pairs separated by ;)', $response->header('X-Marker-1'));
        $this->assertNotNull($response->header('X-Marker-2'));
        $this->assertSame('not a path', $response->header('X-Path-1'));
        $this->assertSame('no points (expected [lng,lat] pairs, enc, circle or rect)', $response->header('X-Path-2'));
    }
}

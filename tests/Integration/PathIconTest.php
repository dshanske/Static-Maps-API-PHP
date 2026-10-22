<?php

/**
 * Vehicle icons along paths (#106).
 */

namespace StaticMaps\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use StaticMaps\Tests\Support\RenderTestCase;

/**
 * `icon`, `iconat` and `iconcolor` on paths, drawn on the fixture map.
 */
final class PathIconTest extends RenderTestCase
{
    /** @var array<string, mixed> A 400 x 250 map over Portland at zoom 14. */
    private const MAP = ['latitude' => 45.515, 'longitude' => -122.67, 'zoom' => 14, 'width' => 400, 'height' => 250];

    /**
     * Icons drawn on paths.
     *
     * @return array<string, array{array<string, mixed>, string}> Request parameters and snapshot name.
     */
    public static function drawnCases(): array
    {
        $diagonal = '[-122.69,45.507][-122.65,45.523]';

        return [
            'each type'              => [self::MAP + ['path' => [
                '[-122.69,45.519][-122.65,45.519];color:e65100;weight:4;icon:car;iconat:20',
                '[-122.69,45.5165][-122.65,45.5165];color:2e7d32;weight:4;icon:train;iconat:40',
                '[-122.69,45.5135][-122.65,45.5135];color:c62828;weight:4;icon:bus;iconat:60',
                '[-122.69,45.511][-122.65,45.511];color:0277bd;weight:4;icon:ship;iconat:80',
            ]], 'path-icons-types.png'],
            'plane turns to the path' => [self::MAP + ['path' => [
                "$diagonal;color:6a1b9a;weight:3;icon:plane;iconat:25,75",
                '[-122.66,45.508][-122.68,45.508];color:000000;weight:2;icon:plane;iconcolor:1565c0',
            ]], 'path-icons-plane.png'],
            'curved path'            => [self::MAP + ['bezier' => 30, 'path' => [
                "$diagonal;color:6a1b9a;weight:3;icon:plane",
            ]], 'path-icons-bezier.png'],
            'fitted, icons at the ends' => [['width' => 300, 'height' => 200, 'path' => [
                '[-122.69,45.507][-122.65,45.523];color:2e7d32;weight:3;icon:train;iconat:0,100',
            ]], 'path-icons-ends.png'],
            // The overrides first: with +, the left side's keys win.
            'scale 2'                => [['scale' => 2, 'width' => 200, 'height' => 125, 'path' => [
                "$diagonal;color:c62828;weight:3;icon:bus",
            ]] + self::MAP, 'path-icons-scale2.png'],
        ];
    }

    /**
     * The icons are drawn where they should be, with no notices.
     *
     * @param array<string, mixed> $params   Request parameters.
     * @param string               $snapshot Snapshot file name.
     *
     * @return void
     */
    #[DataProvider('drawnCases')]
    public function testDrawn(array $params, string $snapshot): void
    {
        $response = $this->render($params);

        $this->assertSame(200, $response->status);
        $this->assertNull($response->header('X-Path-1'));
        $this->assertMatchesSnapshot($snapshot, $response->body);
    }

    /**
     * The badge is centred on the path's middle, in the icon colour, with a white glyph inside.
     *
     * @return void
     */
    public function testBadgeAtTheMiddle(): void
    {
        $im = $this->render(self::MAP + ['path' => [
            '[-122.69,45.515][-122.65,45.515];color:000000;weight:2;icon:car;iconcolor:ff0000',
        ]])->image();

        // The path runs across the centre (y 125); its middle is at x = 200 (the map is centred on -122.67).
        $edge = imagecolorsforindex($im, imagecolorat($im, 200, 125 - 7));
        $this->assertGreaterThan(200, $edge['red'], 'red badge above the centre');
        $this->assertLessThan(60, $edge['green']);
        $outside = imagecolorsforindex($im, imagecolorat($im, 200, 125 - 14));
        $this->assertFalse($outside['red'] > 200 && $outside['green'] < 60, 'nothing red outside the badge');
    }

    /**
     * The icon is drawn: the same path with one gives a different image.
     *
     * @return void
     */
    public function testIconIsDrawn(): void
    {
        $path = '[-122.69,45.515][-122.65,45.515];color:000000;weight:2';
        $this->assertNotSame(
            $this->render(self::MAP + ['path' => [$path]])->body,
            $this->render(self::MAP + ['path' => ["$path;icon:car"]])->body
        );
    }

    /**
     * Bad icon values leave the path out with a notice, like other bad path values.
     *
     * @return void
     */
    public function testBadValues(): void
    {
        $response = $this->render(self::MAP + ['path' => ['[-122.69,45.515][-122.65,45.515];icon:rocket']]);

        $this->assertSame(200, $response->status);
        $this->assertSame('icon must be plane, train, car, bus, ship', $response->header('X-Path-1'));
    }
}

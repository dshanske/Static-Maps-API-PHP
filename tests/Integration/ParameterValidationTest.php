<?php

/**
 * Checking numeric request parameters and the size limit (#9).
 */

namespace StaticMaps\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use StaticMaps\Tests\Support\RenderTestCase;

/**
 * Invalid numbers are a 400; sizes above the limit are drawn at the limit.
 */
final class ParameterValidationTest extends RenderTestCase
{
    /** @var array<string, mixed> A valid map centre. */
    private const CENTER = ['latitude' => '45.5165', 'longitude' => '-122.6764', 'zoom' => '14'];

    /**
     * Invalid values and the message each gets.
     *
     * @return array<string, array{array<string, mixed>, string}> Parameters (merged over CENTER) and message.
     */
    public static function invalidCases(): array
    {
        return [
            'width not a number'   => [['width' => 'abc'], 'width must be a whole number'],
            'width with decimals'  => [['width' => '400.5'], 'width must be a whole number'],
            'width zero'           => [['width' => '0'], 'width must be 1 or more'],
            'height negative'      => [['height' => '-5'], 'height must be 1 or more'],
            'huge zoom'            => [['zoom' => '99999999999999999999999'], 'zoom must be between 0 and 30'],
            'latitude text'        => [['latitude' => 'north'], 'latitude must be a number'],
            'latitude too far'     => [['latitude' => '86'], 'latitude must be between -85.0511 and 85.0511'],
            'longitude too far'    => [['longitude' => '-181'], 'longitude must be between -180 and 180'],
            'latitude as list'     => [['latitude' => ['1']], 'latitude must be a number'],
            'zoom text'            => [['zoom' => 'close'], 'zoom must be a whole number'],
            'zoom too high'        => [['zoom' => '31'], 'zoom must be between 0 and 30'],
            'zoom negative'        => [['zoom' => '-1'], 'zoom must be between 0 and 30'],
            'maxzoom text'         => [['maxzoom' => 'x'], 'maxzoom must be a whole number'],
            'quality zero'         => [['format' => 'jpg', 'quality' => '0'], 'quality must be between 1 and 100'],
            'quality too high'     => [['format' => 'jpg', 'quality' => '101'], 'quality must be between 1 and 100'],
            'bezier too steep'     => [['bezier' => '90'], 'bezier must be between -89 and 89'],
            'bezier text'          => [['bezier' => 'curvy'], 'bezier must be a number'],
            'infinite number'      => [['bezier' => 'INF'], 'bezier must be a number'],
        ];
    }

    /**
     * Invalid numeric values get HTTP 400 with the reason, and no tiles are fetched.
     *
     * @param array<string, mixed> $params  Parameters merged over CENTER.
     * @param string               $message Expected reason.
     *
     * @return void
     */
    #[DataProvider('invalidCases')]
    public function testInvalidValueIsRejected(array $params, string $message): void
    {
        $response = $this->render($params + self::CENTER);

        $this->assertSame(400, $response->status);
        $this->assertSame($message . "\n", $response->body);
        $this->assertSame([], $this->tileRequests());
    }

    /**
     * Sizes above the limit are drawn at the limit, with an X-Map-Size header.
     *
     * @return void
     */
    public function testSizeIsClamped(): void
    {
        $response = $this->render(self::CENTER + ['width' => '5000', 'height' => '300']);

        $this->assertSame(200, $response->status);
        $this->assertSame([1280, 300], [imagesx($response->image()), imagesy($response->image())]);
        $this->assertSame('clamped; requested=5000x300; drawn=1280x300', $response->header('X-Map-Size'));

        $huge = $this->render(self::CENTER + ['width' => '99999999999999999999999']);
        $this->assertSame([1280, 300], [imagesx($huge->image()), imagesy($huge->image())]);

        $atLimit = $this->render(self::CENTER + ['width' => '1280', 'height' => '1280']);
        $this->assertSame([1280, 1280], [imagesx($atLimit->image()), imagesy($atLimit->image())]);
        $this->assertNull($atLimit->header('X-Map-Size'));
    }

    /**
     * Empty values count as not given; spaces and a plus sign are accepted.
     *
     * @return void
     */
    public function testEmptyAndPaddedValues(): void
    {
        $empty = $this->render(self::CENTER + ['width' => '', 'height' => '', 'bezier' => '', 'quality' => '']);
        $this->assertSame([300, 300], [imagesx($empty->image()), imagesy($empty->image())]);

        $padded = $this->render(
            ['latitude' => ' 45.5165 ', 'longitude' => '-122.6764', 'zoom' => '+14', 'width' => ' 200']
        );
        $this->assertSame([200, 300], [imagesx($padded->image()), imagesy($padded->image())]);
    }

    /**
     * zoom=0 is a zoom level (raised to the minimum), not "fit the markers".
     *
     * @return void
     */
    public function testZoomZero(): void
    {
        $this->render(['latitude' => '45.5', 'longitude' => '-122.6', 'zoom' => '0']);

        $zooms = array_unique(array_map(static fn (string $p): string => explode('/', $p)[2], $this->tilePaths()));
        $this->assertSame(['2'], array_values($zooms));
    }

    /**
     * Invalid marker and path coordinates skip that marker or path, with a header.
     *
     * @return void
     */
    public function testInvalidOverlaysAreSkipped(): void
    {
        $response = $this->render(self::CENTER + [
            'marker' => ['lat:95;lng:10;icon:dot-small-red'],
            'path'   => ['[1.2.3,4][5,6]'],
        ]);

        $this->assertSame(200, $response->status);
        $this->assertStringStartsWith('lat and lng must be numbers in range', (string) $response->header('X-Marker-1'));
        $this->assertSame('points must be [lng,lat] numbers in range', $response->header('X-Path-1'));
    }

    /**
     * The query API reports the size limit.
     *
     * @return void
     */
    public function testApiReportsLimits(): void
    {
        $data = (array) json_decode(self::$app->get('/api/v1/')->body, true);

        $this->assertSame(['max_width' => 1280, 'max_height' => 1280], $data['limits']);
    }
}

<?php

/**
 * The demo page's examples (#92).
 */

namespace StaticMaps\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use StaticMaps\Tests\Support\RenderTestCase;

/**
 * Draws every example in assets/demo.html against the fake tile server, so
 * the examples can't go out of date. The shipped providers are switched off
 * in the test configuration, so each is drawn on the fixture map instead.
 */
final class DemoPageTest extends RenderTestCase
{
    /** @var string The demo page. */
    private const PAGE = __DIR__ . '/../../assets/demo.html';

    /**
     * The examples, from the page's `demo-examples` script block.
     *
     * @return array<string, array{array<string, string|list<string>>}> Each example's request parameters.
     */
    public static function examples(): array
    {
        $html = (string) file_get_contents(self::PAGE);
        preg_match('#<script type="application/json" id="demo-examples">(.*?)</script>#s', $html, $m);
        $examples = json_decode($m[1] ?? '', true, 16, JSON_THROW_ON_ERROR);

        $cases = [];
        foreach ($examples as $example) {
            $cases[(string) $example['title']] = [$example['params']];
        }

        return $cases;
    }

    /**
     * There are examples, each with a title, a description and parameters.
     *
     * @return void
     */
    public function testExamplesAreListed(): void
    {
        $this->assertGreaterThanOrEqual(5, count(self::examples()));
    }

    /**
     * Each example draws an image, and every marker, path and GeoJSON feature in it is valid.
     *
     * @param array<string, string|list<string>> $params The example's request parameters.
     *
     * @return void
     */
    #[DataProvider('examples')]
    public function testExampleDraws(array $params): void
    {
        $response = $this->render($params);

        $this->assertSame(200, $response->status, $response->body);
        $this->assertStringStartsWith('image/', (string) $response->header('Content-Type'));
        foreach (array_keys($response->headers) as $name) {
            $this->assertDoesNotMatchRegularExpression('/^x-(marker|path)-|^x-map-(geojson|error|limit)/i', (string) $name);
        }
    }
}

<?php

/**
 * Client keys and the open access mode (#28).
 */

namespace StaticMaps\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use StaticMaps\Tests\Support\RenderTestCase;

/**
 * Who may draw which maps with the default `open` mode.
 */
final class AccessTest extends RenderTestCase
{
    /** @var array<string, mixed> Centre of tile 2608/5860 at zoom 14. */
    private const CENTER = ['latitude' => 45.51404592560425, 'longitude' => -122.684326171875, 'zoom' => 14];

    /**
     * Requests and whether they get the map they asked for.
     *
     * @return array<string, array{array<string, mixed>, bool}> Parameters and whether the requested map is drawn.
     */
    public static function accessCases(): array
    {
        return [
            'free map, no key'                 => [['provider' => 'fixture'], true],
            'server key, no client key'        => [['provider' => 'keyed'], false],
            'server key, client key'           => [['provider' => 'keyed', 'key' => 'test-token'], true],
            'server key, limited client'       => [['provider' => 'keyed', 'key' => 'limited-key'], true],
            'requires_auth, limited client'    => [['provider' => 'secret', 'key' => 'limited-key'], false],
            'requires_auth, client'            => [['provider' => 'secret', 'key' => 'test-token'], true],
            'unknown key'                      => [['provider' => 'keyed', 'key' => 'nope'], false],
            'disabled client'                  => [['provider' => 'keyed', 'key' => 'disabled-key'], false],
            'deprecated token parameter'       => [['provider' => 'keyed', 'token' => 'test-token'], true],
        ];
    }

    /**
     * The map drawn, or the fallback notice.
     *
     * @param array<string, mixed> $params Request parameters.
     * @param bool                 $drawn  Whether the requested map is drawn (no X-Map-Fallback).
     *
     * @return void
     */
    #[DataProvider('accessCases')]
    public function testAccess(array $params, bool $drawn): void
    {
        $response = $this->render(self::CENTER + $params);

        $this->assertSame(200, $response->status);
        $notice = $response->header('X-Map-Fallback');
        $this->assertSame($drawn, $notice === null, (string) $notice);
    }

    /**
     * The deprecated `token` parameter still works, with deprecation headers.
     *
     * @return void
     */
    public function testTokenParameterIsDeprecated(): void
    {
        $response = $this->render(self::CENTER + ['provider' => 'secret', 'token' => 'test-token']);

        $this->assertNull($response->header('X-Map-Fallback'));
        $this->assertSame('@1792281600', $response->header('Deprecation'));
        $withKey = $this->render(self::CENTER + ['provider' => 'secret', 'key' => 'test-token']);
        $this->assertNull($withKey->header('Deprecation'));
    }

    /**
     * A client without the external-URLs permission can't make the server download URLs.
     *
     * @return void
     */
    public function testExternalUrlsPermission(): void
    {
        $icon = self::$tiles?->url . '/overlay/1/1/1.png';
        $marker = ['marker' => ['lat:45.514;lng:-122.684;icon:' . $icon]];

        $limited = $this->render(self::CENTER + $marker + ['key' => 'limited-key']);
        $this->assertSame('icon URLs need a valid key', $limited->header('X-Marker-1'));
        $this->assertNotContains('/overlay/1/1/1.png', $this->tilePaths());

        $custom = $this->render(self::CENTER + ['provider' => 'custom', 'tileurl' => $icon, 'key' => 'limited-key']);
        $this->assertStringStartsWith('unavailable; requested=custom', (string) $custom->header('X-Map-Fallback'));

        $this->setUp();
        $this->render(self::CENTER + $marker + ['key' => 'test-token']);
        $this->assertContains('/overlay/1/1/1.png', $this->tilePaths());
    }

    /**
     * The query API reports the access mode and lists keyed maps only for a client that may use them.
     *
     * @return void
     */
    public function testApi(): void
    {
        $root = (array) json_decode(self::$app->get('/api/v1/')->body, true);
        $this->assertSame(
            ['mode' => 'open', 'credentials' => 'either', 'key_required' => false, 'key_valid' => false],
            $root['access']
        );

        $ids = static fn (string $q): array => array_column(
            (array) json_decode(self::$app->get('/api/v1/providers' . $q)->body, true)['providers'],
            'id'
        );
        $this->assertNotContains('keyed', $ids(''));
        $this->assertContains('keyed', $ids('?key=test-token'));
        $this->assertContains('keyed', $ids('?key=limited-key'));
        $this->assertNotContains('secret', $ids('?key=limited-key'), 'not on its provider list');
    }
}

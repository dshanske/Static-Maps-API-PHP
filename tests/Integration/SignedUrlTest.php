<?php

/**
 * Signed URLs end to end (#12).
 */

namespace StaticMaps\Tests\Integration;

use StaticMaps\Clients;
use StaticMaps\Tests\Support\RenderTestCase;

/**
 * Requests signed with the fixture clients' secrets.
 */
final class SignedUrlTest extends RenderTestCase
{
    /** @var string A map that needs a client key (its provider has a server-held key). */
    private const MAP = '/?latitude=45.5165&longitude=-122.6764&zoom=12&provider=keyed';

    /** @var string The `test` client's signing secret. */
    private const SECRET = 'test-signing-secret';

    /**
     * Sign a path and query with a secret.
     *
     * @param string $uri    Path and query string.
     * @param string $secret Signing secret.
     *
     * @return string The URL with `&signature=` appended.
     */
    private static function sign(string $uri, string $secret): string
    {
        return $uri . '&signature=' . Clients::sign($uri, $secret);
    }

    /**
     * A signed URL draws the keyed map, and may be cached publicly.
     *
     * @return void
     */
    public function testSignedUrlDrawsTheMap(): void
    {
        $response = self::$app->get(self::sign(self::MAP . '&key=test', 'test-signing-secret'));

        $this->assertSame(200, $response->status);
        $this->assertSame('image/png', $response->header('Content-Type'));
        $this->assertNull($response->header('X-Map-Fallback'));
        $this->assertSame('public, max-age=604800', $response->header('Cache-Control'));
        $this->assertSame([], self::$app->errors());

        $plain = self::$app->get(self::MAP . '&key=test-token');
        $this->assertSame('private, max-age=604800', $plain->header('Cache-Control'), 'a plain key stays private');
    }

    /**
     * Bad signatures and plain keys of must-sign clients get 403 with a reason, and no tiles.
     *
     * @return void
     */
    public function testRefused(): void
    {
        $cases = [
            self::sign(self::MAP . '&key=test', 'wrong-secret')       => 'The signature is not valid',
            self::sign(self::MAP . '&key=test', 'test-signing-secret') . 'x' => 'The signature is not valid',
            self::MAP . '&key=signer-key' => 'This key must be used in a signed URL',
            self::sign(self::MAP . '&expires=1000&key=test', self::SECRET) => 'This signed URL has expired',
        ];
        foreach ($cases as $url => $message) {
            $response = self::$app->get($url);
            $this->assertSame(403, $response->status, $url);
            $this->assertSame("$message\n", $response->body, $url);
            $this->assertSame('no-store', $response->header('Cache-Control'));
        }
        $this->assertSame([], $this->tileRequests());

        $signer = self::$app->get(self::sign(self::MAP . '&key=signer', 'signer-signing-secret'));
        $this->assertSame(200, $signer->status, 'a must-sign client with a signed URL');
    }

    /**
     * Signed URLs also work for the deprecated entry point and the query API.
     *
     * @return void
     */
    public function testOtherPaths(): void
    {
        $uri = '/img.php?latitude=45.5&longitude=-122.6&zoom=12&provider=keyed&key=test';
        $legacy = self::$app->get(self::sign($uri, 'test-signing-secret'));
        $this->assertSame(200, $legacy->status);
        $this->assertNull($legacy->header('X-Map-Fallback'));

        $api = self::$app->get(self::sign('/api/v1/providers?key=test', 'test-signing-secret'));
        $this->assertContains('keyed', array_column((array) json_decode($api->body, true)['providers'], 'id'));
        $this->assertSame(403, self::$app->get(self::sign('/api/v1/providers?key=test', 'nope'))->status);
    }
}

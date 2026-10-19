<?php

/**
 * Tests for include/Access.php: plain keys and signed URLs (#28, #12).
 */

namespace StaticMaps\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StaticMaps\Access;
use StaticMaps\Clients;
use StaticMaps\Request;

/**
 * Which client a request comes from, and when it is refused.
 */
final class AccessTest extends TestCase
{
    /**
     * The clients used by the tests.
     *
     * @return Clients `plain` (key only), `both` (key and secret), `strict` (must sign), `off` (disabled).
     */
    private static function clients(): Clients
    {
        $h = static fn (string $key): string => Clients::hashKey($key);

        return new Clients([
            'plain'  => ['key_sha256' => $h('plain-key')],
            'both'   => ['key_sha256' => $h('both-key'), 'secret' => 'both-secret-123456'],
            'strict' => ['key_sha256' => $h('strict-key'), 'secret' => 'strict-secret-1234', 'must_sign' => true],
            'off'    => ['key_sha256' => $h('off-key'), 'secret' => 'off-secret-1234567', 'enabled' => false],
        ]);
    }

    /**
     * A request for a URL, as PHP would parse it.
     *
     * @param string $uri    Path and query string.
     * @param string $method HTTP method.
     *
     * @return Request The request.
     */
    private static function request(string $uri, string $method = 'GET'): Request
    {
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $params);

        return new Request($params, $uri, $method);
    }

    /**
     * Sign a URL with a secret.
     *
     * @param string $uri    Path and query string.
     * @param string $secret Signing secret.
     *
     * @return string The URL with `&signature=` appended.
     */
    private static function signed(string $uri, string $secret): string
    {
        return $uri . '&signature=' . Clients::sign($uri, $secret);
    }

    /**
     * Requests and their outcome.
     *
     * @return array<string, array{string, string, string|null, string|null}> Credentials setting, URL,
     *         expected client id (or null) and expected error fragment (or null).
     */
    public static function cases(): array
    {
        $s = static fn (string $uri, string $secret = 'both-secret-123456'): string => self::signed($uri, $secret);
        $future = time() + 3600;
        $changed = str_replace('zoom=3', 'zoom=9', $s('/?zoom=3&key=both'));

        return [
            'plain key'               => ['either', '/?zoom=3&key=plain-key', 'plain', null],
            'unknown plain key'       => ['either', '/?key=nope', null, null],
            'deprecated token'        => ['either', '/?token=plain-key', 'plain', null],
            'signed URL'              => ['either', $s('/?zoom=3&key=both'), 'both', null],
            'signed, other path'      => ['either', $s('/img.php?zoom=3&key=both'), 'both', null],
            'signed with expiry'      => ['either', $s("/?zoom=3&expires=$future&key=both"), 'both', null],
            'expired'                 => ['either', $s('/?expires=1000&key=both'), null, 'expired'],
            'expires not a time'      => ['either', $s('/?expires=soon&key=both'), null, 'Unix time'],
            'wrong secret'            => ['either', $s('/?zoom=3&key=both', 'not-the-secret'), null, 'not valid'],
            'changed after signing'   => ['either', $changed, null, 'not valid'],
            'unknown client'          => ['either', $s('/?key=ghost'), null, 'not valid'],
            'client without secret'   => ['either', $s('/?key=plain', ''), null, 'not valid'],
            'disabled client'         => ['either', $s('/?key=off', 'off-secret-1234567'), null, 'not valid'],
            'signature not last'      => ['either', '/?signature=abc&key=both', null, 'last parameter'],
            'must sign, plain key'    => ['either', '/?key=strict-key', null, 'signed URL'],
            'must sign, signed'       => ['either', $s('/?key=strict', 'strict-secret-1234'), 'strict', null],
            'keys only: signed URL'   => ['key', $s('/?key=both'), null, 'not accepted'],
            'keys only: plain key'    => ['key', '/?key=both-key', 'both', null],
            'signed only: plain key'  => ['signed', '/?key=both-key', null, 'signed URL'],
            'signed only: signed URL' => ['signed', $s('/?key=both'), 'both', null],
        ];
    }

    /**
     * The client found, or the error.
     *
     * @param string      $credentials The access.credentials setting.
     * @param string      $uri         Request URL.
     * @param string|null $client      Expected client id.
     * @param string|null $error       Expected part of the error, or null for none.
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testClient(string $credentials, string $uri, ?string $client, ?string $error): void
    {
        [$found, , $problem] = (new Access(self::clients(), false, $credentials))->client(self::request($uri));

        $this->assertSame($client, $found?->id);
        if ($error === null) {
            $this->assertNull($problem);
        } else {
            $this->assertStringContainsString($error, (string) $problem);
        }
    }

    /**
     * A signed client is marked as signed; a plain key isn't.
     *
     * @return void
     */
    public function testSignedFlag(): void
    {
        $access = new Access(self::clients(), false, 'either');

        $signed = self::request(self::signed('/?key=both', 'both-secret-123456'));
        $this->assertTrue($access->client($signed)[0]?->signed);
        $this->assertFalse($access->client(self::request('/?key=both-key'))[0]?->signed);
    }

    /**
     * Signed URLs must be GET (or HEAD) requests.
     *
     * @return void
     */
    public function testSignedUrlsMustBeGet(): void
    {
        $uri = self::signed('/?key=both', 'both-secret-123456');
        $access = new Access(self::clients(), false, 'either');

        $this->assertStringContainsString('GET', (string) $access->client(self::request($uri, 'POST'))[2]);
        $this->assertNull($access->client(self::request($uri, 'HEAD'))[2]);
    }

    /**
     * The signature is base64url HMAC-SHA256, as documented in the README.
     *
     * @return void
     */
    public function testSignatureFormat(): void
    {
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', '/?key=a', 's', true)), '+/', '-_'), '=');

        $this->assertSame($expected, Clients::sign('/?key=a', 's'));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', Clients::generateSecret());
    }
}

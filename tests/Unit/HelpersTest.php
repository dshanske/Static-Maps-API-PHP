<?php

/**
 * Tests for include/helpers.php.
 */

namespace StaticMaps\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * request() and is_authenticated().
 */
final class HelpersTest extends TestCase
{
    /** @var array<string, mixed> $_REQUEST before the test, restored afterwards. */
    private array $savedRequest;

    /**
     * Save the request superglobal.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->savedRequest = $_REQUEST;
    }

    /**
     * Restore the request superglobal.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $_REQUEST = $this->savedRequest;
    }

    /**
     * request() returns the parameter, or the default (false unless given) when it is missing.
     *
     * @return void
     */
    public function testRequest(): void
    {
        $_REQUEST = ['zoom' => '14', 'empty' => '', 'marker' => ['a', 'b']];

        $this->assertSame('14', request('zoom'));
        $this->assertSame('', request('empty', 'default'));
        $this->assertSame(['a', 'b'], request('marker'));
        $this->assertFalse(request('missing'));
        $this->assertSame(300, request('missing', 300));
    }

    /**
     * An empty or missing token is never authenticated.
     *
     * @return void
     */
    public function testEmptyTokenIsNotAuthenticated(): void
    {
        $this->assertFalse(is_authenticated(false));
        $this->assertFalse(is_authenticated(''));
        $this->assertFalse(is_authenticated(null));
    }

    /**
     * Without data/apikeys.txt no token is valid.
     *
     * The token file path is fixed in helpers.php, so tests with a token file
     * come with the refactor in #14.
     *
     * @return void
     */
    public function testNoTokenFile(): void
    {
        if (is_file(__DIR__ . '/../../data/apikeys.txt')) {
            $this->markTestSkipped('data/apikeys.txt exists in this checkout');
        }

        $this->assertFalse(is_authenticated('any-token'));
    }
}

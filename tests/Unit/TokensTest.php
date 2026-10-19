<?php

/**
 * Tests for include/Tokens.php.
 */

namespace StaticMaps\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StaticMaps\Tokens;

/**
 * Checking access tokens against the token file.
 */
final class TokensTest extends TestCase
{
    /** @var string Temporary token file. */
    private string $file;

    /**
     * Write a token file with surrounding whitespace and a blank line.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'static-maps-tokens-');
        file_put_contents($this->file, "first-token\n  second-token  \r\n\nthird\n");
    }

    /**
     * Delete the token file.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        unlink($this->file);
    }

    /**
     * Listed tokens are valid, with surrounding whitespace ignored.
     *
     * @return void
     */
    public function testListedTokensAreValid(): void
    {
        $tokens = new Tokens($this->file);

        $this->assertTrue($tokens->isValid('first-token'));
        $this->assertTrue($tokens->isValid('second-token'));
        $this->assertTrue($tokens->isValid('third'));
    }

    /**
     * Unlisted, partial, empty and missing tokens are not valid.
     *
     * @return void
     */
    public function testOtherTokensAreNotValid(): void
    {
        $tokens = new Tokens($this->file);

        $this->assertFalse($tokens->isValid('fourth'));
        $this->assertFalse($tokens->isValid('first'));
        $this->assertFalse($tokens->isValid(''));
        $this->assertFalse($tokens->isValid(false));
        $this->assertFalse($tokens->isValid(['first-token']));
    }

    /**
     * Without a token file no token is valid.
     *
     * @return void
     */
    public function testMissingFile(): void
    {
        $this->assertFalse((new Tokens('/nonexistent/tokens.txt'))->isValid('first-token'));
    }
}

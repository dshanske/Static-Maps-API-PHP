<?php

/**
 * Tests for include/Request.php.
 */

namespace StaticMaps\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StaticMaps\Request;

/**
 * Reading request parameters.
 */
final class RequestTest extends TestCase
{
    /**
     * get() returns the parameter, or the default (false unless given) when it is missing.
     *
     * @return void
     */
    public function testGet(): void
    {
        $request = new Request(['zoom' => '14', 'empty' => '', 'marker' => ['a', 'b']]);

        $this->assertSame('14', $request->get('zoom'));
        $this->assertSame('', $request->get('empty', 'default'));
        $this->assertSame(['a', 'b'], $request->get('marker'));
        $this->assertFalse($request->get('missing'));
        $this->assertSame(300, $request->get('missing', 300));
    }

    /**
     * isEmpty() is true only without any parameter, even an empty one.
     *
     * @return void
     */
    public function testIsEmpty(): void
    {
        $this->assertTrue((new Request([]))->isEmpty());
        $this->assertFalse((new Request(['x' => '']))->isEmpty());
    }

    /**
     * fromGlobals() reads $_REQUEST.
     *
     * @return void
     */
    public function testFromGlobals(): void
    {
        $saved = $_REQUEST;
        $_REQUEST = ['width' => '640'];
        try {
            $this->assertSame('640', Request::fromGlobals()->get('width'));
        } finally {
            $_REQUEST = $saved;
        }
    }
}

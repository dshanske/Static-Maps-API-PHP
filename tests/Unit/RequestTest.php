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

    /**
     * A JSON body: values like a query string's, geojson kept, query parameters overridden (#38).
     *
     * @return void
     */
    public function testJsonBody(): void
    {
        $body = json_encode([
            'latitude' => 45.5,
            'zoom'     => 12,
            'bezier'   => true,
            'marker'   => ['lat:45.5;lng:-122.6;icon:pin'],
            'format'   => null,
            'geojson'  => ['type' => 'Point', 'coordinates' => [1, 2]],
        ]);
        $request = Request::fromJson((string) $body, ['zoom' => '3', 'width' => '200'], '/?zoom=3', 1000);

        $this->assertNull($request->error);
        $this->assertSame('POST', $request->method);
        $this->assertSame('45.5', $request->get('latitude'));
        $this->assertSame('12', $request->get('zoom'), 'the body wins');
        $this->assertSame('200', $request->get('width'));
        $this->assertSame('true', $request->get('bezier'));
        $this->assertSame(['lat:45.5;lng:-122.6;icon:pin'], $request->get('marker'));
        $this->assertSame('', $request->get('format'));
        $this->assertSame(['type' => 'Point', 'coordinates' => [1, 2]], $request->get('geojson'));
    }

    /**
     * Too large a body is 413; one that isn't a JSON object is 400.
     *
     * @return void
     */
    public function testJsonBodyErrors(): void
    {
        $this->assertSame(413, Request::fromJson(str_repeat(' ', 11) . '{}', [], '/', 10)->error[0] ?? null);
        $this->assertSame(413, Request::fromJson(null, [], '/', 10)->error[0] ?? null);
        foreach (['{nope', '[1,2]', '"text"'] as $body) {
            $this->assertSame(400, Request::fromJson($body, [], '/', 100)->error[0] ?? null, $body);
        }
        $this->assertNull(Request::fromJson('{}', [], '/', 100)->error);
    }
}

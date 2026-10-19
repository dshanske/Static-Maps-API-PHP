<?php

/**
 * Tests for include/Clients.php and include/Client.php.
 */

namespace StaticMaps\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StaticMaps\Client;
use StaticMaps\Clients;

/**
 * Finding clients by key, from every source.
 */
final class ClientsTest extends TestCase
{
    /** @var string Temporary directory. */
    private string $dir;

    /**
     * Make a temporary directory.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/static-maps-clients-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    /**
     * Remove the temporary directory.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /**
     * A configuration with clients from all three sources.
     *
     * @return array<string, mixed> Configuration (only the parts Clients reads).
     */
    private function config(): array
    {
        file_put_contents("$this->dir/tokens.txt", "old-token\n\n");
        Clients::writeFile("$this->dir/clients.json", [
            'issued' => ['key_sha256' => Clients::hashKey('issued-key'), 'providers' => ['stadia']],
            'off'    => ['key_sha256' => Clients::hashKey('off-key'), 'enabled' => false],
        ]);

        return [
            'token_file' => "$this->dir/tokens.txt",
            'access'     => ['clients_file' => "$this->dir/clients.json"],
            'clients'    => ['by-hand' => ['key' => 'hand-key', 'external_urls' => false]],
        ];
    }

    /**
     * Keys from the config, the clients file and the token file all find their client.
     *
     * @return void
     */
    public function testFind(): void
    {
        $clients = Clients::fromConfig($this->config());

        $this->assertSame(1, $clients->legacy);
        $this->assertSame('token-1', $clients->find('old-token')?->id);

        $issued = $clients->find('issued-key');
        $this->assertNotNull($issued);
        $this->assertSame('issued', $issued->id);
        $this->assertSame(['stadia'], $issued->providers);
        $this->assertTrue($issued->mayUse('stadia'));
        $this->assertFalse($issued->mayUse('mapbox'));
        $this->assertTrue($issued->externalUrls);

        $hand = $clients->find('hand-key');
        $this->assertNotNull($hand);
        $this->assertSame('by-hand', $hand->id);
        $this->assertFalse($hand->externalUrls);
        $this->assertTrue($hand->mayUse('anything'), 'no provider list: all providers');
    }

    /**
     * Unknown, empty, non-string and disabled keys find nobody; matching is exact.
     *
     * @return void
     */
    public function testNoMatch(): void
    {
        $clients = Clients::fromConfig($this->config());

        foreach (['nope', '', false, null, ['issued-key'], 'ISSUED-KEY', 'issued-key ', 'off-key'] as $key) {
            $this->assertNull($clients->find($key), var_export($key, true));
        }
    }

    /**
     * A clients file that isn't valid is an error, so access never silently opens up.
     *
     * @return void
     */
    public function testInvalidFile(): void
    {
        file_put_contents("$this->dir/clients.json", '{not json');

        $this->expectException(RuntimeException::class);
        Clients::readFile("$this->dir/clients.json");
    }

    /**
     * Generated keys are long, random and URL-safe; the file is not world-readable.
     *
     * @return void
     */
    public function testKeysAndFile(): void
    {
        $a = Clients::generateKey();
        $this->assertMatchesRegularExpression('/^sm_[A-Za-z0-9_-]{32}$/', $a);
        $this->assertNotSame($a, Clients::generateKey());

        Clients::writeFile("$this->dir/c.json", ['x' => ['key_sha256' => Clients::hashKey($a)]]);
        $this->assertSame(0640, fileperms("$this->dir/c.json") & 0777);
        $this->assertStringNotContainsString($a, (string) file_get_contents("$this->dir/c.json"));
        $this->assertSame('x', (new Clients(Clients::readFile("$this->dir/c.json")))->find($a)?->id);
    }

    /**
     * Client::mayUse() with and without a provider list.
     *
     * @return void
     */
    public function testClientMayUse(): void
    {
        $this->assertTrue((new Client('a'))->mayUse('esri'));
        $this->assertFalse((new Client('a', true, []))->mayUse('esri'));
    }
}

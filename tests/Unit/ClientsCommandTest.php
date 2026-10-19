<?php

/**
 * Tests for include/ClientsCommand.php (bin/clients.php).
 */

namespace StaticMaps\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StaticMaps\Clients;
use StaticMaps\ClientsCommand;

/**
 * Issuing, listing, changing, rotating, revoking and importing client keys.
 */
final class ClientsCommandTest extends TestCase
{
    /** @var string Temporary directory. */
    private string $dir;

    /** @var string Output of the last run. */
    private string $output = '';

    /**
     * Make a temporary directory.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/static-maps-clients-cmd-' . bin2hex(random_bytes(4));
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
     * Run a command against the temporary clients file.
     *
     * @param string ...$args Command-line arguments.
     *
     * @return int Exit status.
     */
    private function command(string ...$args): int
    {
        $out = fopen('php://memory', 'w+');
        $config = [
            'access'     => ['clients_file' => "$this->dir/clients.json"],
            'token_file' => "$this->dir/apikeys.txt",
            'providers'  => ['esri' => [], 'stadia' => []],
        ];
        $status = (new ClientsCommand($config, $out, $out))->run($args);
        rewind($out);
        $this->output = (string) stream_get_contents($out);

        return $status;
    }

    /**
     * The clients in the file.
     *
     * @return array<string, array<string, mixed>> Clients.
     */
    private function clients(): array
    {
        return Clients::readFile("$this->dir/clients.json");
    }

    /**
     * add issues a key that works, shown once and stored only as a hash.
     *
     * @return void
     */
    public function testAdd(): void
    {
        $this->assertSame(0, $this->command('add', 'blog', '--providers=stadia', '--no-external-urls'));
        preg_match('/(sm_[A-Za-z0-9_-]{32})/', $this->output, $m);
        $key = $m[1] ?? '';

        $client = (new Clients($this->clients()))->find($key);
        $this->assertNotNull($client);
        $this->assertSame('blog', $client->id);
        $this->assertSame(['stadia'], $client->providers);
        $this->assertFalse($client->externalUrls);
        $this->assertStringNotContainsString($key, (string) file_get_contents("$this->dir/clients.json"));

        $this->assertSame(1, $this->command('add', 'blog'), 'already exists');
        $this->assertSame(2, $this->command('add', 'Bad Id'));
        $this->assertSame(1, $this->command('add', 'x', '--providers=nope'), 'unknown provider');
    }

    /**
     * set, rotate, revoke and list.
     *
     * @return void
     */
    public function testManage(): void
    {
        $this->command('add', 'blog');
        preg_match('/(sm_[A-Za-z0-9_-]{32})/', $this->output, $m);
        $old = $m[1] ?? '';

        $status = $this->command('set', 'blog', '--providers=esri,stadia', '--external-urls=no', '--disable');
        $this->assertSame(0, $status);
        $this->assertSame(['esri', 'stadia'], $this->clients()['blog']['providers']);
        $this->assertFalse($this->clients()['blog']['external_urls']);
        $this->assertNull((new Clients($this->clients()))->find($old), 'disabled');

        $this->command('set', 'blog', '--enable', '--providers=all');
        $this->assertNull($this->clients()['blog']['providers']);

        $this->assertSame(0, $this->command('rotate', 'blog'));
        preg_match('/(sm_[A-Za-z0-9_-]{32})/', $this->output, $m);
        $clients = new Clients($this->clients());
        $this->assertNull($clients->find($old), 'old key stops working');
        $this->assertSame('blog', $clients->find($m[1] ?? '')?->id);

        $this->assertSame(0, $this->command('list'));
        $this->assertStringContainsString('blog', $this->output);

        $this->assertSame(0, $this->command('revoke', 'blog'));
        $this->assertSame([], $this->clients());
        $this->assertSame(1, $this->command('revoke', 'blog'));
    }

    /**
     * import moves the old token file's tokens in, once.
     *
     * @return void
     */
    public function testImport(): void
    {
        file_put_contents("$this->dir/apikeys.txt", "one\n\ntwo\n");

        $this->assertSame(0, $this->command('import'));
        $this->assertStringContainsString('Imported 2 token(s)', $this->output);
        $clients = new Clients($this->clients());
        $this->assertSame('token-1', $clients->find('one')?->id);
        $this->assertSame('token-2', $clients->find('two')?->id);

        $this->command('import');
        $this->assertStringContainsString('Imported 0 token(s)', $this->output, 'not twice');
        $this->assertSame(1, $this->command('import', "$this->dir/missing.txt"));
    }

    /**
     * help and unknown commands.
     *
     * @return void
     */
    public function testHelp(): void
    {
        $this->assertSame(0, $this->command('help'));
        $this->assertStringContainsString('Usage: php bin/clients.php', $this->output);
        $this->assertSame(2, $this->command('frobnicate'));
    }
}

<?php

/**
 * A PHP built-in web server (`php -S`) run as a child process for tests.
 */

namespace StaticMaps\Tests\Support;

use RuntimeException;

/**
 * Starts `php -S` on a free local port and stops it again.
 *
 * PHP errors raised while serving requests go to a log file (display_errors
 * is off, so they never end up inside an image), which tests read with
 * errors().
 */
final class PhpServer
{
    /** @var resource|null The `proc_open()` handle of the server process. */
    private $process;

    /** @var string Base URL of the server, e.g. `http://127.0.0.1:41234`. */
    public readonly string $url;

    /** @var string File that receives PHP errors from the served scripts. */
    private string $errorLog;

    /** @var int Byte offset in the error log up to which errors() has already reported. */
    private int $errorOffset = 0;

    /**
     * Start a server and wait until it accepts connections.
     *
     * @param string                $docroot Document root (`-t`).
     * @param string|null           $router  Router script, or null for none.
     * @param array<string, string> $env     Extra environment variables for the server.
     *
     * @throws RuntimeException If the server does not start within 10 seconds.
     */
    public function __construct(string $docroot, ?string $router = null, array $env = [])
    {
        $port = self::freePort();
        $this->url = "http://127.0.0.1:$port";
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'static-maps-errors-');

        $command = [
            PHP_BINARY,
            '-d', 'display_errors=0',
            '-d', 'log_errors=1',
            '-d', 'error_reporting=' . E_ALL,
            '-d', 'error_log=' . $this->errorLog,
            '-S', "127.0.0.1:$port",
            '-t', $docroot,
        ];
        if ($router !== null) {
            $command[] = $router;
        }

        // The server's own access log goes to stderr; discard it.
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open(
            $command,
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            $docroot,
            $env + getenv()
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start php -S');
        }
        $this->process = $process;

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(50000);
        }
        $this->stop();
        throw new RuntimeException("php -S did not start on port $port");
    }

    /**
     * Stop the server and remove its error log.
     *
     * @return void
     */
    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
        if (is_file($this->errorLog)) {
            unlink($this->errorLog);
        }
    }

    /**
     * Send a GET request to the server.
     *
     * @param string $path Path and query string, starting with `/`.
     *
     * @return Response The status, headers and body.
     */
    public function get(string $path): Response
    {
        return $this->request('GET', $path);
    }

    /**
     * Send a request with any method to the server.
     *
     * @param string $method HTTP method, e.g. `POST` or `HEAD`.
     * @param string $path   Path and query string, starting with `/`.
     *
     * @return Response The status, headers and body.
     */
    public function request(string $method, string $path): Response
    {
        $ch = curl_init($this->url . $path);
        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_NOBODY         => $method === 'HEAD',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    // Repeated headers (e.g. several Link lines) are joined, as HTTP allows.
                    $name = strtolower(trim($parts[0]));
                    $value = trim($parts[1]);
                    $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return new Response($status, $headers, is_string($body) ? $body : '');
    }

    /**
     * PHP errors logged since the previous call.
     *
     * @return list<string> One entry per logged line, without the timestamp.
     */
    public function errors(): array
    {
        clearstatcache(true, $this->errorLog);
        $contents = (string) file_get_contents($this->errorLog, false, null, $this->errorOffset);
        $this->errorOffset += strlen($contents);

        $lines = array_filter(explode("\n", $contents), static fn (string $l): bool => trim($l) !== '');

        return array_values(array_map(
            static fn (string $l): string => (string) preg_replace('/^\[[^\]]+\]\s*/', '', $l),
            $lines
        ));
    }

    /**
     * Pick a TCP port on 127.0.0.1 that is currently free.
     *
     * @return int The port number.
     *
     * @throws RuntimeException If no port could be reserved.
     */
    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException("Could not find a free port: $errstr");
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}

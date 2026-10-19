<?php

/**
 * Downloading from other servers with limits.
 */

namespace StaticMaps;

use CurlHandle;

/**
 * Makes every download from another server (tiles, icon images, attribution
 * images) the same way: http(s) only, a limited number of redirects, time
 * limits, a size limit and the configured User-Agent. See the `remote`
 * setting in config/defaults.php.
 */
final class RemoteFetcher
{
    /**
     * @param int    $timeout         Seconds a whole download may take.
     * @param int    $connectTimeout  Seconds to wait for a connection.
     * @param int    $maxBytes        Largest response body accepted, in bytes.
     * @param int    $maxRedirects    Most redirects followed.
     * @param string $userAgent       User-Agent header sent.
     */
    public function __construct(
        private int $timeout,
        private int $connectTimeout,
        private int $maxBytes,
        private int $maxRedirects,
        private string $userAgent
    ) {
    }

    /**
     * Build a fetcher from the configuration.
     *
     * @param array<string, mixed> $config Configuration from Config::load() (`remote`, `user_agent`).
     *
     * @return self The fetcher.
     */
    public static function fromConfig(array $config): self
    {
        $remote = $config['remote'];

        return new self(
            (int) $remote['timeout'],
            (int) $remote['connect_timeout'],
            (int) $remote['max_bytes'],
            (int) $remote['max_redirects'],
            (string) $config['user_agent']
        );
    }

    /**
     * A curl handle for one download, with all limits set.
     *
     * The body is returned by curl_exec() or curl_multi_getcontent(). A download
     * that grows past the size limit is aborted (curl then reports an error).
     *
     * @param string        $url            The URL to download.
     * @param list<string>  $requestHeaders Extra request header lines, e.g. `If-None-Match: "x"`.
     * @param callable|null $onHeader       Called with each response header line (curl's
     *                                      CURLOPT_HEADERFUNCTION, without the handle).
     * @param bool          $redirects      Whether to follow redirects. Off for requests whose
     *                                      headers carry a secret, because curl would send
     *                                      them to whatever server a redirect points at.
     *
     * @return CurlHandle The handle.
     */
    public function handle(
        string $url,
        array $requestHeaders = [],
        ?callable $onHeader = null,
        bool $redirects = true
    ): CurlHandle {
        $ch = curl_init();
        $maxBytes = $this->maxBytes;
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $redirects,
            CURLOPT_MAXREDIRS      => $this->maxRedirects,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_MAXFILESIZE    => $maxBytes,
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_HTTPHEADER     => $requestHeaders,
        ]);
        if ($onHeader !== null) {
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $line) use ($onHeader): int {
                $onHeader($line);
                return strlen($line);
            });
        }
        // Abort as soon as more than the limit has arrived (also when the server
        // doesn't announce the size). The newer XFERINFO option needs PHP 8.2,
        // so it's looked up by name; PROGRESSFUNCTION takes the same arguments.
        $progress = defined('CURLOPT_XFERINFOFUNCTION')
            ? (int) constant('CURLOPT_XFERINFOFUNCTION')
            : CURLOPT_PROGRESSFUNCTION;
        curl_setopt($ch, $progress, static fn ($ch, int $total, int $now): int => $now > $maxBytes ? 1 : 0);

        // Only http and https, for the first request and for redirects. The
        // *_STR options (PHP 8.3+) replace the bitmask ones, which libcurl deprecated.
        if (defined('CURLOPT_PROTOCOLS_STR') && defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            curl_setopt($ch, (int) constant('CURLOPT_PROTOCOLS_STR'), 'http,https');
            curl_setopt($ch, (int) constant('CURLOPT_REDIR_PROTOCOLS_STR'), 'http,https');
        } else {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }

        return $ch;
    }

    /**
     * Download one URL.
     *
     * @param string $url The URL to download.
     *
     * @return string|null The body, or null if the download failed, broke a limit,
     *                     or the final response was not a 2xx status.
     */
    public function fetch(string $url): ?string
    {
        $ch = $this->handle($url);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (!is_string($body) || curl_errno($ch) !== 0 || $status < 200 || $status > 299) {
            return null;
        }

        return $body;
    }
}

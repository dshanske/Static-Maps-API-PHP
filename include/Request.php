<?php

/**
 * The parameters of one map request.
 */

namespace StaticMaps;

/**
 * Read-only access to request parameters (query string and POST body).
 */
final class Request
{
    /**
     * @param array<string, mixed> $params Parameters, normally `$_REQUEST`.
     * @param string               $uri    The request's path and query string exactly as sent
     *                                     (for checking signed URLs).
     * @param string               $method HTTP method.
     */
    public function __construct(
        private array $params,
        public readonly string $uri = '',
        public readonly string $method = 'GET'
    ) {
    }

    /**
     * Build a request from the current PHP request.
     *
     * @return self The request for `$_REQUEST`, with REQUEST_URI and REQUEST_METHOD.
     */
    public static function fromGlobals(): self
    {
        return new self(
            $_REQUEST,
            (string) ($_SERVER['REQUEST_URI'] ?? ''),
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))
        );
    }

    /**
     * Whether the request has no parameters at all.
     *
     * @return bool True for e.g. a plain `GET /`.
     */
    public function isEmpty(): bool
    {
        return $this->params === [];
    }

    /**
     * One parameter, exactly as received.
     *
     * A parameter that is present but empty is returned as the empty string;
     * the default is only used when the parameter is missing.
     *
     * @param string $key     Parameter name.
     * @param mixed  $default Returned when the parameter is missing.
     *
     * @return mixed The value (string, or array for `name[]` parameters), or $default.
     */
    public function get(string $key, mixed $default = false): mixed
    {
        return array_key_exists($key, $this->params) ? $this->params[$key] : $default;
    }
}

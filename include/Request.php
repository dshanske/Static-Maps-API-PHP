<?php

/**
 * The parameters of one map request.
 */

namespace StaticMaps;

/**
 * Read-only access to request parameters: the query string, and a POST body
 * as form data or JSON (#38).
 */
final class Request
{
    /**
     * @param array<string, mixed> $params Parameters, normally `$_REQUEST`.
     * @param string               $uri    The request's path and query string exactly as sent
     *                                     (for checking signed URLs).
     * @param string               $method HTTP method.
     * @param array{int, string}|null $error  Why the body couldn't be read: HTTP status and message,
     *                                       or null.
     */
    public function __construct(
        private array $params,
        public readonly string $uri = '',
        public readonly string $method = 'GET',
        public readonly ?array $error = null
    ) {
    }

    /**
     * Build a request from the current PHP request.
     *
     * A POST with `Content-Type: application/json` is read as a JSON object of
     * parameters, merged over the query string (see fromJson()).
     *
     * @param int $maxBodyBytes Largest JSON body read (`limits.max_body_bytes`); a larger one is
     *                          an error (413).
     *
     * @return self The request for `$_REQUEST` (or the JSON body), with REQUEST_URI and REQUEST_METHOD.
     */
    public static function fromGlobals(int $maxBodyBytes = 1048576): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if ($method !== 'POST' || !str_starts_with($type, 'application/json')) {
            return new self($_REQUEST, $uri, $method);
        }

        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $body = $length > $maxBodyBytes ? false : file_get_contents('php://input', false, null, 0, $maxBodyBytes + 1);

        return self::fromJson(is_string($body) ? $body : null, $_GET, $uri, $maxBodyBytes);
    }

    /**
     * Build a request from a JSON body (#38).
     *
     * The body is an object with the same names as the query parameters, e.g.
     * `{"latitude": 45.5, "longitude": -122.6, "marker": ["lat:45.5;lng:-122.6;icon:pin"],
     * "geojson": {...}}`. Numbers and booleans become strings and lists become
     * lists of strings, as in a query string, so the same checks apply; only
     * `geojson` keeps its structure. Its values replace query parameters of the
     * same name.
     *
     * @param string|null          $body         The body, or null if it was too large to read.
     * @param array<string, mixed> $query        The query parameters.
     * @param string               $uri          Path and query string.
     * @param int                  $maxBodyBytes Largest body accepted, in bytes.
     *
     * @return self The request; with an `error` if the body is too large or not a JSON object.
     */
    public static function fromJson(?string $body, array $query, string $uri, int $maxBodyBytes): self
    {
        if ($body === null || strlen($body) > $maxBodyBytes) {
            return new self($query, $uri, 'POST', [413, "The request body is over the limit of $maxBodyBytes bytes"]);
        }
        $data = json_decode($body, true, 64);
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            return new self($query, $uri, 'POST', [400, 'The request body must be a JSON object of parameters']);
        }

        $params = $query;
        foreach ($data as $name => $value) {
            $params[(string) $name] = $name === 'geojson' ? $value : self::flatten($value);
        }

        return new self($params, $uri, 'POST');
    }

    /**
     * A JSON value as a query parameter value.
     *
     * @param mixed $value A JSON value.
     *
     * @return mixed A string, a list (of strings or nested lists), or '' for null.
     */
    private static function flatten(mixed $value): mixed
    {
        return match (true) {
            is_bool($value)  => $value ? 'true' : 'false',
            is_int($value)   => (string) $value,
            is_float($value) => rtrim(rtrim(sprintf('%.10F', $value), '0'), '.'),
            is_array($value) => array_map(self::flatten(...), array_is_list($value) ? $value : array_values($value)),
            is_string($value) => $value,
            default          => '',
        };
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

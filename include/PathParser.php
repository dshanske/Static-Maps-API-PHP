<?php

/**
 * Parsing of `path[]` and `polyline` parameters.
 */

namespace StaticMaps;

/**
 * Turns `path[]` values, or a `polyline`, into paths to draw.
 *
 * A path is a string of `key:value;` pairs (`color`, `weight`) followed by
 * points written as `[lng,lat]`, e.g. `color:ff0000;weight:4;[-122.6,45.5][-122.7,45.6]`.
 * `polyline` is used only when there is no `path[]`; it holds one path as
 * `enc:<encoded polyline>;color:...;weight:...`, in Google's Encoded Polyline
 * format (lat,lng pairs, precision 5), as produced by Google Maps, Mapbox,
 * OSRM and other routing services (#43).
 */
final class PathParser
{
    /**
     * @param mixed $defaultColor  Stroke colour when a path has none: hex RGB without `#`.
     * @param mixed $defaultWeight Stroke width in pixels when a path has none.
     */
    public function __construct(private mixed $defaultColor, private mixed $defaultWeight)
    {
    }

    /**
     * Parse the paths of a request.
     *
     * A path whose points can't be read, or are out of range, is left out and
     * reported in an `X-Path-<n>` header (n counting from 1, the polyline is 1).
     *
     * @param mixed  $paths    The `path` parameter: a string, a list of strings, or false.
     * @param mixed  $polyline The `polyline` parameter, or false.
     * @param Bounds $bounds   Box to widen with every point.
     *
     * @return array{paths: list<array<string, mixed>>, headers: list<string>} The paths
     *         (`color`, `weight` and `path`, a list of [lng, lat] points, plus any other
     *         `key:value` pairs) and headers to send.
     */
    public function parse(mixed $paths, mixed $polyline, Bounds $bounds): array
    {
        $result = array();
        $headers = array();
        if ($paths) {
            if (!is_array($paths)) {
                $paths = array($paths);
            }

            foreach (array_values($paths) as $n => $path) {
                if (!is_string($path)) {
                    $headers[] = 'X-Path-' . ($n + 1) . ': not a path';
                    continue;
                }
                $properties = $this->styleProperties($path);

                // Now parse the points into an array
                if (preg_match_all('/(?P<point>\[[0-9\.-]+,[0-9\.-]+\])/', $path, $matches)) {
                    $points = json_decode('[' . implode(',', $matches['point']) . ']');
                    if (!self::validPoints($points)) {
                        $headers[] = 'X-Path-' . ($n + 1) . ': points must be [lng,lat] numbers in range';
                        continue;
                    }
                    $properties['path'] = $points;
                    // Adjust the bounds to fit the path
                    foreach ($properties['path'] as $point) {
                        $bounds->extend($point[1], $point[0]);
                    }
                }
                if (array_key_exists('path', $properties)) {
                    $result[] = $properties;
                }
            }
        } elseif ($polyline) {
            if (!is_string($polyline)) {
                return ['paths' => [], 'headers' => ['X-Path-1: not a polyline']];
            }
            $properties = $this->styleProperties($polyline);
            if (array_key_exists('enc', $properties)) {
                // Google's encoding stores each point as lat,lng (#43); paths use [lng, lat].
                $points = array_map(
                    static fn (array $pair): array => count($pair) === 2 ? [$pair[1], $pair[0]] : $pair,
                    Polyline::pair(Polyline::decode($properties['enc']))
                );
                if (!self::validPoints($points)) {
                    return ['paths' => [], 'headers' => ['X-Path-1: the polyline has points out of range']];
                }
                $properties['path'] = $points;
                foreach ($properties['path'] as $point) {
                    $bounds->extend($point[1], $point[0]);
                }

                $result[] = $properties;
            }
        }

        return ['paths' => $result, 'headers' => $headers];
    }

    /**
     * Whether a decoded list of points is usable.
     *
     * @param mixed $points Decoded points: a list of [lng, lat] pairs.
     *
     * @return bool True if it is a non-empty list of valid pairs.
     */
    private static function validPoints(mixed $points): bool
    {
        if (!is_array($points) || $points === []) {
            return false;
        }
        foreach ($points as $point) {
            if (!is_array($point) || count($point) !== 2 || !Parameters::isValidPoint($point[1], $point[0])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Read the `key:value` pairs of a path and fill in the default style.
     *
     * @param string $value A `path[]` or `polyline` value.
     *
     * @return array<string, mixed> The pairs, with `color` and `weight` always set.
     */
    private function styleProperties(string $value): array
    {
        $properties = array();
        if (preg_match_all('/(?P<k>[a-z]+):(?P<v>[^;]+)/', $value, $matches)) {
            foreach ($matches['k'] as $j => $key) {
                $properties[$key] = $matches['v'][$j];
            }
        }

        // Set default color and weight if none specified
        if (!array_key_exists('color', $properties)) {
            $properties['color'] = $this->defaultColor;
        }
        if (!array_key_exists('weight', $properties)) {
            $properties['weight'] = $this->defaultWeight;
        }

        return $properties;
    }
}

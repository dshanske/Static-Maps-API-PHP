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
     * @param int   $maxPoints     Most points in all paths together (`limits.max_points`); a
     *                             path that would go over is left out, with an `X-Map-Limit` header.
     */
    public function __construct(
        private mixed $defaultColor,
        private mixed $defaultWeight,
        private int $maxPoints = PHP_INT_MAX
    ) {
    }

    /**
     * Parse the paths of a request.
     *
     * A path whose points can't be read, or are out of range, is left out and
     * reported in an `X-Path-<n>` header (n counting from 1, the polyline is 1).
     * A path that would take the total number of points over `$maxPoints` is
     * left out (not drawn, not in the bounds) and counted in an `X-Map-Limit` header.
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
        $total = 0;
        $over = 0;
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
                    if (!$this->withinLimit($total, count($points))) {
                        $over++;
                        continue;
                    }
                    $properties['path'] = $points;
                    // Adjust the bounds to fit the path (each segment the short way round, #35)
                    $bounds->extendPath($properties['path']);
                }
                if (array_key_exists('path', $properties)) {
                    $result[] = $properties;
                } else {
                    $headers[] = 'X-Path-' . ($n + 1) . ': no points (expected [lng,lat] pairs)';
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
                if (!$this->withinLimit($total, count($points))) {
                    return ['paths' => [], 'headers' => [$this->limitHeader(1)]];
                }
                $properties['path'] = $points;
                $bounds->extendPath($properties['path']);

                $result[] = $properties;
            } else {
                $headers[] = 'X-Path-1: no points (expected enc:<encoded polyline>)';
            }
        }
        if ($over > 0) {
            $headers[] = $this->limitHeader($over);
        }

        return ['paths' => $result, 'headers' => $headers];
    }

    /**
     * Count a path's points against the limit.
     *
     * @param int $total  Points accepted so far; increased if this path fits.
     * @param int $points Points in this path.
     *
     * @return bool True if the path fits within `$maxPoints`.
     */
    private function withinLimit(int &$total, int $points): bool
    {
        if ($total + $points > $this->maxPoints) {
            return false;
        }
        $total += $points;

        return true;
    }

    /**
     * The header for paths left out over the point limit.
     *
     * @param int $count How many paths were left out.
     *
     * @return string The `X-Map-Limit` header line.
     */
    private function limitHeader(int $count): string
    {
        return "X-Map-Limit: left out $count path(s) over the limit of $this->maxPoints points";
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

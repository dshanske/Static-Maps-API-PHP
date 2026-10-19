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
 * `enc:<encoded polyline>;color:...;weight:...`, whose decoded pairs are
 * read in the same [lng, lat] order as path[] (see #43).
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
     * @param mixed  $paths    The `path` parameter: a string, a list of strings, or false.
     * @param mixed  $polyline The `polyline` parameter, or false.
     * @param Bounds $bounds   Box to widen with every point.
     *
     * @return list<array<string, mixed>> The paths: `color`, `weight` and `path`, a list of
     *                                    [lng, lat] points (plus any other `key:value` pairs).
     */
    public function parse(mixed $paths, mixed $polyline, Bounds $bounds): array
    {
        $result = array();
        if ($paths) {
            if (!is_array($paths)) {
                $paths = array($paths);
            }

            foreach ($paths as $path) {
                $properties = $this->styleProperties($path);

                // Now parse the points into an array
                if (preg_match_all('/(?P<point>\[[0-9\.-]+,[0-9\.-]+\])/', $path, $matches)) {
                    $properties['path'] = json_decode('[' . implode(',', $matches['point']) . ']');
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
            $properties = $this->styleProperties($polyline);
            if (array_key_exists('enc', $properties)) {
                $properties['path'] = Polyline::pair(Polyline::decode($properties['enc']));
                foreach ($properties['path'] as $point) {
                    $bounds->extend($point[1], $point[0]);
                }

                $result[] = $properties;
            }
        }

        return $result;
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

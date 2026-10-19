<?php

/**
 * Parsing of `marker[]` parameters.
 */

namespace StaticMaps;

/**
 * Turns `marker[]` values into markers with loaded icons.
 *
 * A marker is a string of `key:value;` pairs, e.g.
 * `lat:45.5;lng:-122.6;icon:small-blue-cutout`. It needs an `icon`, `lat` and
 * `lng`. (`location:`, an address to geocode, is no longer supported, #16.)
 * The icon is a file name in
 * public/images/ (without `.png`) or, for requests with a valid token, an
 * http(s) URL.
 */
final class MarkerParser
{
    /** @var string Directory with the built-in marker icons. */
    public const ICON_DIR = __DIR__ . '/../public/images';

    /** @var RemoteFetcher Downloads icon URLs. */
    private RemoteFetcher $remote;

    /**
     * @param RemoteFetcher|null $remote Downloads icon URLs; null for one with the shipped limits.
     */
    public function __construct(?RemoteFetcher $remote = null)
    {
        $this->remote = $remote ?? new RemoteFetcher(10, 5, 5000000, 3, 'Static Maps API');
    }

    /**
     * Parse the markers of a request.
     *
     * Every valid marker definition widens $bounds, even if its icon can't be
     * loaded (the marker is then left out). Invalid definitions, including
     * markers with only a `location` or with invalid coordinates, and icons that
     * can't be used are reported as `X-Marker-<n>` headers, n counting from 1.
     * `lat` and `lng` are converted to floats.
     *
     * @param mixed  $value         The `marker` parameter: a string, a list of strings, or false.
     * @param bool   $mayFetchUrls  Whether the request's client may use icon URLs.
     * @param Bounds $bounds        Box to widen with each marker's position.
     *
     * @return array{markers: list<array<string, mixed>>, headers: list<string>, deprecated: bool}
     *         The markers (their `key:value` pairs plus `iconImg`, a GdImage), response
     *         headers to send, and whether any marker used the removed `location`.
     */
    public function parse(mixed $value, bool $mayFetchUrls, Bounds $bounds): array
    {
        $markers = array();
        $headers = array();
        $deprecated = false;
        if (!$value) {
            return ['markers' => $markers, 'headers' => $headers, 'deprecated' => $deprecated];
        }
        if (!is_array($value)) {
            $value = array($value);
        }

        foreach ($value as $i => $m) {
            if (!preg_match_all('/(?P<k>[a-z]+):(?P<v>[^;]+)/', $m, $matches)) {
                continue;
            }
            $properties = array();
            foreach ($matches['k'] as $j => $key) {
                $properties[$key] = $matches['v'][$j];
            }

            // Geocoding was removed (#16): `location` is ignored, and a marker that
            // has only a location is skipped.
            if (array_key_exists('location', $properties)) {
                $deprecated = true;
            }

            // Skip invalid marker definitions, show error in a header
            if (
                !array_key_exists('icon', $properties)
                || !array_key_exists('lat', $properties)
                || !array_key_exists('lng', $properties)
            ) {
                $headers[] = 'X-Marker-' . ($i + 1) . ': ' . (
                    array_key_exists('icon', $properties) && array_key_exists('location', $properties)
                        ? 'location is no longer supported, use lat and lng'
                        : 'missing icon, or lat/lng parameters'
                );
                continue;
            }

            if (!Parameters::isValidPoint($properties['lat'], $properties['lng'])) {
                $headers[] = 'X-Marker-' . ($i + 1) . ': lat and lng must be numbers in range (lat ±85.0511, lng ±180)';
                continue;
            }
            $properties['lat'] = (float) $properties['lat'];
            $properties['lng'] = (float) $properties['lng'];

            [$properties['iconImg'], $problem] = $this->loadIcon($properties['icon'], $mayFetchUrls);
            if ($properties['iconImg'] !== null) {
                $markers[] = $properties;
            } else {
                $headers[] = 'X-Marker-' . ($i + 1) . ': ' . $problem;
            }

            $bounds->extend($properties['lat'], $properties['lng']);
        }

        return ['markers' => $markers, 'headers' => $headers, 'deprecated' => $deprecated];
    }

    /**
     * Load a marker icon.
     *
     * @param string $icon          Built-in icon name (letters, digits, `-`, `_`), or an http(s) URL.
     * @param bool   $mayFetchUrls  Whether URLs may be fetched.
     *
     * @return array{\GdImage|null, string|null} The icon, or null and the reason it
     *         couldn't be used (for the `X-Marker-<n>` header).
     */
    private function loadIcon(string $icon, bool $mayFetchUrls): array
    {
        if (preg_match('/https?:\/\/(.+)/', $icon)) {
            if (!$mayFetchUrls) {
                return [null, 'icon URLs need a valid key'];
            }
            // Looks like an external image, attempt to download it
            $img = $this->remote->fetch($icon);
            $image = $img !== null && $img !== '' ? @imagecreatefromstring($img) : false;

            return $image === false ? [null, 'icon could not be loaded'] : [$image, null];
        }

        // Only plain names: no paths, so no other files can be reached.
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $icon)) {
            return [null, 'unknown icon'];
        }
        $file = self::ICON_DIR . '/' . $icon . '.png';
        if (!is_file($file)) {
            return [null, 'unknown icon'];
        }
        $image = @imagecreatefrompng($file);

        return $image === false ? [null, 'icon could not be loaded'] : [$image, null];
    }
}

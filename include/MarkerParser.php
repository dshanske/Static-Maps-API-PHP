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

    /**
     * Parse the markers of a request.
     *
     * Every valid marker definition widens $bounds, even if its icon can't be
     * loaded (the marker is then left out). Invalid definitions, including
     * markers with only a `location`, and icons that can't be used are reported
     * as `X-Marker-<n>` headers, n counting from 1.
     *
     * @param mixed  $value         The `marker` parameter: a string, a list of strings, or false.
     * @param bool   $authenticated Whether the request has a valid token (allows icon URLs).
     * @param Bounds $bounds        Box to widen with each marker's position.
     *
     * @return array{markers: list<array<string, mixed>>, headers: list<string>, deprecated: bool}
     *         The markers (their `key:value` pairs plus `iconImg`, a GdImage), response
     *         headers to send, and whether any marker used the removed `location`.
     */
    public function parse(mixed $value, bool $authenticated, Bounds $bounds): array
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

            [$properties['iconImg'], $problem] = $this->loadIcon($properties['icon'], $authenticated);
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
     * @param string $icon          Built-in icon name, or an http(s) URL.
     * @param bool   $authenticated Whether URLs may be fetched.
     *
     * @return array{\GdImage|null, string|null} The icon, or null and the reason it
     *         couldn't be used (for the `X-Marker-<n>` header).
     */
    private function loadIcon(string $icon, bool $authenticated): array
    {
        if (preg_match('/https?:\/\/(.+)/', $icon)) {
            if (!$authenticated) {
                return [null, 'icon URLs need a valid token'];
            }
            // Looks like an external image, attempt to download it
            $ch = curl_init($icon);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $img = curl_exec($ch);
            $image = is_string($img) && $img !== '' ? @imagecreatefromstring($img) : false;

            return $image === false ? [null, 'icon could not be loaded'] : [$image, null];
        }

        $file = self::ICON_DIR . '/' . $icon . '.png';
        if (!is_file($file)) {
            return [null, 'unknown icon'];
        }
        $image = @imagecreatefrompng($file);

        return $image === false ? [null, 'icon could not be loaded'] : [$image, null];
    }
}

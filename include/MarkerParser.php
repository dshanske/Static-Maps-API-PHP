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
 * The icon is a file name in public/images/ (without `.png`); for requests
 * with a valid key, an http(s) URL; or a generated icon (#37): `pin` or `dot`
 * with `color`, `size` and an optional short `label`, or `none` for a text
 * `label` alone. Any icon can have an `anchor`, `scale`, `rotation` and
 * `opacity`.
 */
final class MarkerParser
{
    /** @var string Directory with the built-in marker icons. */
    public const ICON_DIR = __DIR__ . '/../public/images';

    /** @var RemoteFetcher Downloads icon URLs. */
    private RemoteFetcher $remote;

    /** @var array<string, array{\GdImage|null, float, string|null}> Icon URLs loaded so far: image, density, problem. */
    private array $urlIcons = [];

    /**
     * @param RemoteFetcher|null $remote     Downloads icon URLs; null for one with default limits.
     * @param int                $maxMarkers Most markers drawn (`limits.max_markers`); later
     *                                       ones are left out, with an `X-Map-Limit` header.
     * @param int                $density    The request's `scale` (#10): generated icons are drawn
     *                                       at this many pixels per CSS pixel, so they stay sharp.
     * @param int                $maxUrls    Most different icon URLs per request (`limits.icon_urls`).
     * @param int                $maxBytes   Largest icon download in bytes (`limits.icon_bytes`).
     * @param int                $maxPixels  Icons from URLs larger than this many CSS pixels wide or
     *                                       high are drawn smaller (`limits.icon_pixels`).
     */
    public function __construct(
        ?RemoteFetcher $remote = null,
        private int $maxMarkers = PHP_INT_MAX,
        private int $density = 1,
        private int $maxUrls = 5,
        int $maxBytes = 262144,
        private int $maxPixels = 256
    ) {
        $this->remote = ($remote ?? new RemoteFetcher(10, 5, 5000000, 3, 'Static Maps API'))->withMaxBytes($maxBytes);
    }

    /**
     * Parse the markers of a request.
     *
     * Every valid marker definition widens $bounds, even if its icon can't be
     * loaded (the marker is then left out). Invalid definitions, including
     * markers with only a `location` or with invalid coordinates, and icons that
     * can't be used are reported as `X-Marker-<n>` headers, n counting from 1.
     * Valid markers over `$maxMarkers` are left out (not drawn, not in the bounds)
     * and counted in one `X-Map-Limit` header.
     * `lat` and `lng` are converted to floats.
     *
     * @param mixed  $value         The `marker` parameter: a string, a list of strings, or false.
     * @param bool   $mayFetchUrls  Whether the request's client may use icon URLs.
     * @param Bounds $bounds        Box to widen with each marker's position.
     *
     * @return array{markers: list<array<string, mixed>>, headers: list<string>, deprecated: bool}
     *         The markers (their `key:value` pairs plus `iconImg`, a GdImage, and `density`,
     *         `anchor`, `scale`, `rotation`, `opacity` for MarkerIcon::place()), response
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

        $valid = 0;
        $over = 0;
        foreach (array_values($value) as $i => $m) {
            if (!is_string($m) || !preg_match_all('/(?P<k>[a-z]+):(?P<v>[^;]+)/', $m, $matches)) {
                $headers[] = 'X-Marker-' . ($i + 1) . ': not a marker (key:value pairs separated by ;)';
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

            // Over the limit: left out before its icon is loaded, and not counted in the bounds.
            if (++$valid > $this->maxMarkers) {
                $over++;
                continue;
            }

            [$icon, $problem] = $this->icon($properties, $mayFetchUrls);
            if ($icon !== null) {
                $markers[] = $icon + $properties;
            } else {
                $headers[] = 'X-Marker-' . ($i + 1) . ': ' . $problem;
            }

            $bounds->extend($properties['lat'], $properties['lng']);
        }
        if ($over > 0) {
            $headers[] = "X-Map-Limit: left out $over marker(s) over the limit of $this->maxMarkers";
        }

        return ['markers' => $markers, 'headers' => $headers, 'deprecated' => $deprecated];
    }

    /**
     * The icon of a marker and how it is placed.
     *
     * @param array<string, mixed> $p            The marker's `key:value` pairs.
     * @param bool                 $mayFetchUrls Whether icon URLs may be fetched.
     *
     * @return array{array<string, mixed>|null, string|null} `iconImg`, `density`, `anchor`, `scale`,
     *         `rotation` and `opacity`; or null and the reason the marker can't be drawn.
     */
    private function icon(array $p, bool $mayFetchUrls): array
    {
        $scale = $p['scale'] ?? '1';
        if (!is_numeric($scale) || (float) $scale < 0.25 || (float) $scale > 4) {
            return [null, 'scale must be a number from 0.25 to 4'];
        }
        $rotation = $p['rotation'] ?? '0';
        if (!is_numeric($rotation)) {
            return [null, 'rotation must be a number of degrees'];
        }
        $opacity = $p['opacity'] ?? '1';
        if (!is_numeric($opacity) || (float) $opacity < 0 || (float) $opacity > 1) {
            return [null, 'opacity must be a number from 0 to 1'];
        }
        foreach (['color', 'labelcolor'] as $key) {
            if (isset($p[$key]) && !preg_match('/^[0-9a-fA-F]{6}$/', (string) $p[$key])) {
                return [null, "$key must be RRGGBB (hex)"];
            }
        }

        $type = (string) $p['icon'];
        $label = isset($p['label']) ? (string) $p['label'] : null;
        if (in_array($type, MarkerIcon::TYPES, true)) {
            $size = (string) ($p['size'] ?? 'medium');
            if (!isset(MarkerIcon::PIN_SIZES[$size])) {
                return [null, 'size must be small, medium or large'];
            }
            $max = $type === 'none' ? MarkerIcon::MAX_TEXT_LABEL : MarkerIcon::MAX_PIN_LABEL;
            if ($type === 'none' && ($label === null || trim($label) === '')) {
                return [null, 'icon:none needs a label'];
            }
            if ($label !== null && mb_strlen($label) > $max) {
                return [null, "label must be at most $max characters on icon:$type"];
            }
            [$image, $anchor] = MarkerIcon::generate(
                $type,
                (string) ($p['color'] ?? MarkerIcon::DEFAULT_COLOR),
                $size,
                $label,
                isset($p['labelcolor']) ? (string) $p['labelcolor'] : null,
                $this->density
            );
            $density = (float) $this->density;
        } else {
            if ($label !== null) {
                return [null, 'label works with icon:pin, dot or none'];
            }
            [$image, $density, $problem] = $this->loadIcon($type, $mayFetchUrls);
            if ($image === null) {
                return [null, $problem];
            }
            $anchor = str_starts_with($type, 'dot-') ? ['frac', 0.5, 0.5] : ['frac', 0.5, 1.0];
        }

        if (isset($p['anchor'])) {
            $anchor = self::anchor((string) $p['anchor'], $density);
            if ($anchor === null) {
                $names = implode(', ', array_keys(MarkerIcon::ANCHORS));

                return [null, "anchor must be $names, or x,y in pixels"];
            }
        }

        return [[
            'iconImg'  => $image,
            'density'  => $density,
            'anchor'   => $anchor,
            'scale'    => (float) $scale,
            'rotation' => (float) $rotation,
            'opacity'  => (float) $opacity,
        ], null];
    }

    /**
     * Parse an `anchor` value.
     *
     * @param string $value   A name from MarkerIcon::ANCHORS, or `x,y` in CSS pixels from the icon's
     *                        top-left corner.
     * @param float  $density The icon image's pixels per CSS pixel.
     *
     * @return array{string, float, float}|null ['frac', x, y] fractions of the icon size, or
     *         ['px', x, y] in image pixels; null if invalid.
     */
    private static function anchor(string $value, float $density): ?array
    {
        if (isset(MarkerIcon::ANCHORS[$value])) {
            return ['frac', ...MarkerIcon::ANCHORS[$value]];
        }
        $parts = array_map('trim', explode(',', $value));
        if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
            return ['px', (float) $parts[0] * $density, (float) $parts[1] * $density];
        }

        return null;
    }

    /**
     * Load a marker icon.
     *
     * Each icon URL is downloaded once per request; at most `$maxUrls` different
     * ones, each at most `limits.icon_bytes`. An image larger than `$maxPixels`
     * is drawn at that size.
     *
     * @param string $icon          Built-in icon name (letters, digits, `-`, `_`), or an http(s) URL.
     * @param bool   $mayFetchUrls  Whether URLs may be fetched.
     *
     * @return array{\GdImage|null, float, string|null} The icon, its pixels per CSS pixel, and the
     *         reason it couldn't be used (for the `X-Marker-<n>` header) or null.
     */
    private function loadIcon(string $icon, bool $mayFetchUrls): array
    {
        if (preg_match('/https?:\/\/(.+)/', $icon)) {
            if (!$mayFetchUrls) {
                return [null, 1.0, 'icon URLs need a valid key'];
            }
            if (!isset($this->urlIcons[$icon])) {
                if (count($this->urlIcons) >= $this->maxUrls) {
                    return [null, 1.0, "at most $this->maxUrls different icon URLs per map"];
                }
                // Looks like an external image, attempt to download it
                $img = $this->remote->fetch($icon);
                $image = $img !== null && $img !== '' ? @imagecreatefromstring($img) : false;
                $this->urlIcons[$icon] = $image === false
                    ? [null, 1.0, 'icon could not be loaded']
                    : [$image, max(1.0, max(imagesx($image), imagesy($image)) / $this->maxPixels), null];
            }

            return $this->urlIcons[$icon];
        }

        // Only plain names: no paths, so no other files can be reached.
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $icon)) {
            return [null, 1.0, 'unknown icon'];
        }
        $file = self::ICON_DIR . '/' . $icon . '.png';
        if (!is_file($file)) {
            return [null, 1.0, 'unknown icon'];
        }
        $image = @imagecreatefrompng($file);

        return $image === false ? [null, 1.0, 'icon could not be loaded'] : [$image, 1.0, null];
    }
}

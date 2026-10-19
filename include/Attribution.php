<?php

/**
 * Drawing of the attribution logo.
 */

namespace StaticMaps;

use GdImage;

/**
 * Draws an attribution logo in the bottom-right corner of the map.
 */
final class Attribution
{
    /** @var string Directory with the attribution logos (not web-served). */
    public const LOGO_DIR = __DIR__ . '/../assets/attribution';

    /** @var list<string> Attribution names draw() understands, besides an http(s) URL. */
    public const NAMES = ['osm', 'esri', 'mapbox', 'mapbox-small', 'none'];

    /**
     * Whether an attribution value is one draw() understands.
     *
     * @param mixed $attribution The value, e.g. from the `attribution` parameter.
     *
     * @return bool True for a name in NAMES or an http(s) URL.
     */
    public static function isKnown(mixed $attribution): bool
    {
        return is_string($attribution)
            && (in_array($attribution, self::NAMES, true) || preg_match('/https?:\/\/(.+)/', $attribution) === 1);
    }

    /**
     * Draw the logo for an attribution value.
     *
     * - An http(s) URL: the image at that URL (nothing if it isn't an image).
     * - `mapbox`: the Mapbox logo. `mapbox-small`: the Mapbox logo at half size.
     * - `esri`: "Powered by Esri", 4px from the corner; half size on images
     *   120-219px wide, left out on images 120px wide or less.
     * - `none`: no logo.
     * - Anything else: the OpenStreetMap logo at quarter size.
     *
     * @param GdImage $im          The map image.
     * @param mixed   $attribution The attribution value.
     * @param mixed   $width       Image width in pixels.
     * @param mixed   $height      Image height in pixels.
     *
     * @return void
     */
    public function draw(GdImage $im, mixed $attribution, mixed $width, mixed $height): void
    {
        if ('none' === $attribution) {
            return;
        }

        if (is_string($attribution) && preg_match('/https?:\/\/(.+)/', $attribution)) {
            // Looks like an external image, attempt to download it
            $ch = curl_init($attribution);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $img = curl_exec($ch);
            $logo = is_string($img) && $img !== '' ? @imagecreatefromstring($img) : false;
            if ($logo !== false) {
                $this->drawFullSize($im, $logo, 0, $width, $height);
            }
            return;
        }

        $file = match ($attribution) {
            'mapbox', 'mapbox-small' => 'mapbox-attribution.png',
            'esri'                   => 'powered-by-esri.png',
            default                  => 'osm-attribution.png',
        };
        $logo = imagecreatefrompng(self::LOGO_DIR . '/' . $file);
        if ($logo === false) {
            return;
        }

        if ('mapbox' === $attribution) {
            $this->drawFullSize($im, $logo, 0, $width, $height);
        } elseif ('mapbox-small' === $attribution) {
            $this->drawShrunk($im, $logo, 2, 0, $width, $height);
        } elseif ('esri' === $attribution) {
            // Shrink the esri logo if the image is small
            if ($width > 120) {
                if ($width < 220) {
                    $this->drawShrunk($im, $logo, 2, 4, $width, $height);
                } else {
                    $this->drawFullSize($im, $logo, 4, $width, $height);
                }
            }
        } else {
            $this->drawShrunk($im, $logo, 4, 0, $width, $height);
        }
    }

    /**
     * Copy a logo at its own size into the bottom-right corner.
     *
     * @param GdImage       $im     The map image.
     * @param GdImage       $logo   The logo.
     * @param int           $margin Distance from the right and bottom edges in pixels.
     * @param mixed         $width  Image width in pixels.
     * @param mixed         $height Image height in pixels.
     *
     * @return void
     */
    private function drawFullSize(GdImage $im, GdImage $logo, int $margin, mixed $width, mixed $height): void
    {
        imagecopy(
            $im,
            $logo,
            (int) ($width - imagesx($logo) - $margin),
            (int) ($height - imagesy($logo) - $margin),
            0,
            0,
            imagesx($logo),
            imagesy($logo)
        );
    }

    /**
     * Copy a logo, scaled down, into the bottom-right corner.
     *
     * @param GdImage       $im           The map image.
     * @param GdImage       $logo         The logo.
     * @param int           $shrinkFactor Divide the logo's width and height by this.
     * @param int           $margin       Distance from the right and bottom edges in pixels.
     * @param mixed         $width        Image width in pixels.
     * @param mixed         $height       Image height in pixels.
     *
     * @return void
     */
    private function drawShrunk(
        GdImage $im,
        GdImage $logo,
        int $shrinkFactor,
        int $margin,
        mixed $width,
        mixed $height
    ): void {
        imagecopyresampled(
            $im,
            $logo,
            (int) ($width - round(imagesx($logo) / $shrinkFactor) - $margin),
            (int) ($height - round(imagesy($logo) / $shrinkFactor) - $margin),
            0,
            0,
            (int) round(imagesx($logo) / $shrinkFactor),
            (int) round(imagesy($logo) / $shrinkFactor),
            imagesx($logo),
            imagesy($logo)
        );
    }
}

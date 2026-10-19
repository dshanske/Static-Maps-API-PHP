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

    /**
     * Draw the logo for an attribution value.
     *
     * - An http(s) URL: the image at that URL.
     * - `mapbox`: the Mapbox logo. `mapbox-small`: the Mapbox logo at half size
     *   (currently broken, see #18: it loads `/mapbox-attribution.png`).
     * - `esri`: "Powered by Esri", 4px from the corner; half size on images
     *   120-219px wide, left out on images 120px wide or less.
     * - Anything else: the OpenStreetMap logo at quarter size (also for `none`, #18).
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
        if (preg_match('/https?:\/\/(.+)/', $attribution)) {
            // Looks like an external image, attempt to download it
            $ch = curl_init($attribution);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $img = curl_exec($ch);
            $logo = @imagecreatefromstring($img);
            $this->drawFullSize($im, $logo, 0, $width, $height);
        } elseif ('mapbox' === $attribution) {
            $logo = imagecreatefrompng(self::LOGO_DIR . '/mapbox-attribution.png');
            $this->drawFullSize($im, $logo, 0, $width, $height);
        } elseif ('mapbox-small' === $attribution) {
            // Bug kept from the original code (#18): the directory is missing from the path.
            $logo = imagecreatefrompng('/mapbox-attribution.png');
            $this->drawShrunk($im, $logo, 2, 0, $width, $height);
        } elseif ('esri' === $attribution) {
            $logo = imagecreatefrompng(self::LOGO_DIR . '/powered-by-esri.png');

            // Shrink the esri logo if the image is small
            if ($width > 120) {
                if ($width < 220) {
                    $this->drawShrunk($im, $logo, 2, 4, $width, $height);
                } else {
                    $this->drawFullSize($im, $logo, 4, $width, $height);
                }
            }
        } else {
            $logo = imagecreatefrompng(self::LOGO_DIR . '/osm-attribution.png');
            $this->drawShrunk($im, $logo, 4, 0, $width, $height);
        }
    }

    /**
     * Copy a logo at its own size into the bottom-right corner.
     *
     * @param GdImage       $im     The map image.
     * @param GdImage|false $logo   The logo.
     * @param int           $margin Distance from the right and bottom edges in pixels.
     * @param mixed         $width  Image width in pixels.
     * @param mixed         $height Image height in pixels.
     *
     * @return void
     */
    private function drawFullSize(GdImage $im, GdImage|false $logo, int $margin, mixed $width, mixed $height): void
    {
        imagecopy(
            $im,
            $logo,
            $width - imagesx($logo) - $margin,
            $height - imagesy($logo) - $margin,
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
     * @param GdImage|false $logo         The logo.
     * @param int           $shrinkFactor Divide the logo's width and height by this.
     * @param int           $margin       Distance from the right and bottom edges in pixels.
     * @param mixed         $width        Image width in pixels.
     * @param mixed         $height       Image height in pixels.
     *
     * @return void
     */
    private function drawShrunk(
        GdImage $im,
        GdImage|false $logo,
        int $shrinkFactor,
        int $margin,
        mixed $width,
        mixed $height
    ): void {
        imagecopyresampled(
            $im,
            $logo,
            $width - round(imagesx($logo) / $shrinkFactor) - $margin,
            $height - round(imagesy($logo) / $shrinkFactor) - $margin,
            0,
            0,
            round(imagesx($logo) / $shrinkFactor),
            round(imagesy($logo) / $shrinkFactor),
            imagesx($logo),
            imagesy($logo)
        );
    }
}

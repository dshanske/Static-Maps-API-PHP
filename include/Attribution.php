<?php

/**
 * Drawing of the attribution: credit text and logos.
 */

namespace StaticMaps;

use GdImage;

/**
 * Draws a map's attribution in one corner: the credit text the tile provider
 * requires, on a light strip at the edge, and an optional logo next to it.
 */
final class Attribution
{
    /** @var string Directory with the attribution logos (not web-served). */
    public const LOGO_DIR = __DIR__ . '/../assets/attribution';

    /** @var string Font for attribution text (DejaVu Sans, see assets/fonts/LICENSE-DejaVu.txt). */
    public const FONT = __DIR__ . '/../assets/fonts/DejaVuSans.ttf';

    /** @var list<string> Attribution names draw() understands, besides an http(s) URL. */
    public const NAMES = ['osm', 'esri', 'mapbox', 'mapbox-small', 'text', 'none'];

    /** @var list<string> Values of the `attribution_position` parameter. */
    public const POSITIONS = ['bottomright', 'bottomleft', 'topright', 'topleft'];

    /** @var int Most lines of credit text; longer text is cut short with an ellipsis. */
    private const MAX_LINES = 2;

    /** @var int Space between the text and the edge of its strip, in pixels. */
    private const PADDING = 3;

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
     * Draw the attribution for a map.
     *
     * - `none`: nothing at all (the client must then show the attribution itself).
     * - `text`: only the credit text.
     * - A logo (`osm`, `esri`, `mapbox`, `mapbox-small`) or an http(s) URL of an
     *   image: that logo, plus the credit text if the map has one. `esri` is 4px
     *   from the corner, half size on images 120-219px wide and left out at
     *   120px or less; `mapbox-small` is half size, `osm` a quarter.
     * - Anything else: the OpenStreetMap logo.
     *
     * The text sits on a strip at the edge of the image and the logo next to
     * it, towards the middle of the image.
     *
     * @param GdImage     $im          The map image.
     * @param mixed       $attribution The attribution value (see above).
     * @param string|null $text        The map's credit text, or null for none.
     * @param string      $position    Corner: one of POSITIONS.
     * @param int         $width       Image width in pixels.
     * @param int         $height      Image height in pixels.
     *
     * @return void
     */
    public function draw(
        GdImage $im,
        mixed $attribution,
        ?string $text,
        string $position,
        int $width,
        int $height
    ): void {
        if ('none' === $attribution) {
            return;
        }

        $offset = ($text !== null && $text !== '') ? $this->drawText($im, $text, $position, $width, $height) : 0;
        if ('text' === $attribution) {
            return;
        }

        $logo = $this->loadLogo($attribution);
        if ($logo === null) {
            return;
        }

        $logoWidth = imagesx($logo);
        $logoHeight = imagesy($logo);
        $margin = 0;
        if ('esri' === $attribution) {
            // Shrink the esri logo if the image is small, and leave it out if tiny
            if ($width <= 120) {
                return;
            }
            $margin = 4;
            $shrink = $width < 220 ? 2 : 1;
        } else {
            $isUrl = is_string($attribution) && preg_match('/https?:\/\/(.+)/', $attribution) === 1;
            $shrink = match (true) {
                $isUrl, 'mapbox' === $attribution => 1,
                'mapbox-small' === $attribution  => 2,
                default                          => 4,
            };
        }
        $w = (int) round($logoWidth / $shrink);
        $h = (int) round($logoHeight / $shrink);

        $x = str_ends_with($position, 'left') ? $margin : $width - $w - $margin;
        $y = str_starts_with($position, 'top') ? $margin + $offset : $height - $h - $margin - $offset;

        if ($shrink === 1) {
            imagecopy($im, $logo, $x, $y, 0, 0, $logoWidth, $logoHeight);
        } else {
            imagecopyresampled($im, $logo, $x, $y, 0, 0, $w, $h, $logoWidth, $logoHeight);
        }
    }

    /**
     * The logo image for an attribution value.
     *
     * @param mixed $attribution A logo name or an http(s) URL.
     *
     * @return GdImage|null The logo, or null if it can't be loaded.
     */
    private function loadLogo(mixed $attribution): ?GdImage
    {
        if (is_string($attribution) && preg_match('/https?:\/\/(.+)/', $attribution)) {
            // Looks like an external image, attempt to download it
            $ch = curl_init($attribution);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $img = curl_exec($ch);
            $logo = is_string($img) && $img !== '' ? @imagecreatefromstring($img) : false;

            return $logo === false ? null : $logo;
        }

        $file = match ($attribution) {
            'mapbox', 'mapbox-small' => 'mapbox-attribution.png',
            'esri'                   => 'powered-by-esri.png',
            default                  => 'osm-attribution.png',
        };
        $logo = imagecreatefrompng(self::LOGO_DIR . '/' . $file);

        return $logo === false ? null : $logo;
    }

    /**
     * Draw credit text on a light strip in a corner.
     *
     * The text is wrapped to the image width, at most MAX_LINES lines; anything
     * longer is cut short with an ellipsis. It is smaller on images under 250px wide.
     *
     * @param GdImage $im       The map image.
     * @param string  $text     The credit text.
     * @param string  $position Corner: one of POSITIONS.
     * @param int     $width    Image width in pixels.
     * @param int     $height   Image height in pixels.
     *
     * @return int Height of the strip in pixels (0 if nothing was drawn).
     */
    private function drawText(GdImage $im, string $text, string $position, int $width, int $height): int
    {
        $size = $width < 250 ? 6.0 : 7.0;
        $lines = $this->wrap($text, $size, $width - 2 * self::PADDING);
        if ($lines === []) {
            return 0;
        }

        $box = (array) imagettfbbox($size, 0, self::FONT, 'Ag');
        $ascent = (int) -$box[7];
        $lineHeight = (int) ceil(($box[1] - $box[7]) * 1.15);
        $textWidth = max(array_map(fn (string $line): int => $this->textWidth($line, $size), $lines));

        $stripWidth = min($width, $textWidth + 2 * self::PADDING);
        $stripHeight = min($height, count($lines) * $lineHeight + self::PADDING);
        $x = str_ends_with($position, 'left') ? 0 : $width - $stripWidth;
        $y = str_starts_with($position, 'top') ? 0 : $height - $stripHeight;

        // 75% opaque white strip, dark gray text
        $background = (int) imagecolorallocatealpha($im, 255, 255, 255, 32);
        $color = (int) imagecolorallocate($im, 51, 51, 51);
        imagefilledrectangle($im, $x, $y, $x + $stripWidth - 1, $y + $stripHeight - 1, $background);
        foreach ($lines as $i => $line) {
            imagettftext(
                $im,
                $size,
                0,
                $x + self::PADDING,
                $y + 1 + $ascent + $i * $lineHeight,
                $color,
                self::FONT,
                $line
            );
        }

        return $stripHeight;
    }

    /**
     * Break text into lines that fit a width.
     *
     * @param string $text     The text.
     * @param float  $size     Font size in points.
     * @param int    $maxWidth Widest line allowed, in pixels.
     *
     * @return list<string> At most MAX_LINES lines; the last ends in "…" if text was cut.
     */
    private function wrap(string $text, float $size, int $maxWidth): array
    {
        if ($maxWidth < 10) {
            return [];
        }

        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $candidate = $line === '' ? $word : "$line $word";
            if ($this->textWidth($candidate, $size) <= $maxWidth || $line === '') {
                $line = $candidate;
                continue;
            }
            $lines[] = $line;
            $line = $word;
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        $cut = count($lines) > self::MAX_LINES;
        $lines = array_slice($lines, 0, self::MAX_LINES);
        $last = count($lines) - 1;
        // Shorten the last line (a long line, or the one before cut-off text) until it fits with "…".
        if ($cut || $this->textWidth($lines[$last], $size) > $maxWidth) {
            $shortened = $lines[$last];
            while ($shortened !== '' && $this->textWidth($shortened . '…', $size) > $maxWidth) {
                $shortened = mb_substr($shortened, 0, -1);
            }
            $lines[$last] = rtrim($shortened, " ,;") . '…';
        }

        return $lines;
    }

    /**
     * Width of a line of text in pixels.
     *
     * @param string $text The text.
     * @param float  $size Font size in points.
     *
     * @return int Width in pixels.
     */
    private function textWidth(string $text, float $size): int
    {
        $box = (array) imagettfbbox($size, 0, self::FONT, $text);

        return (int) ($box[2] - $box[0]);
    }
}

<?php

/**
 * Downloading of map tiles.
 */

namespace StaticMaps;

use GdImage;

/**
 * Downloads a block of tiles for a base layer and an optional overlay layer,
 * all in parallel with curl_multi.
 *
 * A tile that fails to download or decode is replaced by a gray tile.
 */
final class TileFetcher
{
    /**
     * @param RemoteFetcher $remote Makes the downloads (limits, User-Agent).
     */
    public function __construct(private RemoteFetcher $remote)
    {
    }

    /**
     * Download the tiles from $sw to $ne (inclusive) at one zoom level.
     *
     * @param string               $tileURL    Base layer URL template.
     * @param string|false         $overlayURL Overlay layer URL template, or false for none.
     * @param string|null          $key        API key for {key}, or null.
     * @param array{x: float|int, y: float|int} $sw  Top-left tile (lowest column and row).
     * @param array{x: float|int, y: float|int} $ne  Bottom-right tile (highest column and row).
     * @param mixed                $zoom       Zoom level.
     * @param GdImage              $im         The map image; the gray fallback colour is allocated on it.
     *
     * @return array{tiles: array<int, array<int, GdImage>>, overlays: array<int, array<int, GdImage>>}
     *         Tiles indexed by column, then row. `overlays` has an empty row array for each
     *         column when there is no overlay layer.
     */
    public function fetch(
        string $tileURL,
        string|false $overlayURL,
        ?string $key,
        array $sw,
        array $ne,
        mixed $zoom,
        GdImage $im
    ): array {
        $tiles = array();
        $overlays = array();
        $chs = array();
        $ochs = array();
        $mh = curl_multi_init();

        for ($x = $sw['x']; $x <= $ne['x']; $x++) {
            $x = (int)$x;
            if (!array_key_exists($x, $tiles)) {
                $tiles[$x] = array();
                $overlays[$x] = array();
                $chs[$x] = array();
                $ochs[$x] = array();
            }

            for ($y = $sw['y']; $y <= $ne['y']; $y++) {
                $y = (int)$y;
                $url = Config::tileUrl($tileURL, $x, $y, $zoom, $key);
                $tiles[$x][$y] = false;
                $chs[$x][$y] = $this->remote->handle($url);
                curl_multi_add_handle($mh, $chs[$x][$y]);
                if ($overlayURL) {
                    $url = Config::tileUrl($overlayURL, $x, $y, $zoom, $key);
                    $overlays[$x][$y] = false;
                    $ochs[$x][$y] = $this->remote->handle($url);
                    curl_multi_add_handle($mh, $ochs[$x][$y]);
                }
            }
        }

        $running = null;
        // Execute the handles. Blocks until all are finished.
        do {
            curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running > 0);

        // In case any of the tiles fail, they will be grey instead of throwing an error
        $blank = imagecreatetruecolor(256, 256);
        $grey = imagecolorallocate($im, 224, 224, 224);
        imagefill($blank, 0, 0, $grey);

        foreach ($chs as $x => $yTiles) {
            foreach ($yTiles as $y => $ch) {
                $content = curl_multi_getcontent($ch);
                $tiles[$x][$y] = @imagecreatefromstring($content);
                if (!$tiles[$x][$y]) {
                    $tiles[$x][$y] = $blank;
                }
            }
        }

        if ($overlayURL) {
            foreach ($ochs as $x => $yTiles) {
                foreach ($yTiles as $y => $ch) {
                    $content = curl_multi_getcontent($ch);
                    $overlays[$x][$y] = @imagecreatefromstring($content);
                    if (!$overlays[$x][$y]) {
                        $overlays[$x][$y] = $blank;
                    }
                }
            }
        }

        return ['tiles' => $tiles, 'overlays' => $overlays];
    }
}

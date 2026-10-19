<?php

/**
 * Fake tile server for the integration tests (router script for `php -S`).
 *
 * Serves deterministic PNG tiles so renders never touch a real tile host.
 * URL layout: `/<layer>/<z>/<x>/<y>.png`, where `<layer>` is one of:
 *
 * - `base`    Opaque tile filled with a colour derived from x and y (see
 *             tileColor()), so neighbouring tiles are told apart in a render.
 * - `overlay` Transparent tile with an opaque 16x16 white square in its centre.
 * - `broken`  Always answers 404, so the renderer has to fall back to a gray tile.
 *
 * Every request is appended to the file named by the TILE_LOG environment
 * variable as one JSON object per line: path, query string and User-Agent.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$log = getenv('TILE_LOG');
if (is_string($log) && $log !== '') {
    file_put_contents($log, json_encode([
        'path'  => $path,
        'query' => $_SERVER['QUERY_STRING'] ?? '',
        'ua'    => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]) . "\n", FILE_APPEND | LOCK_EX);
}

if (!preg_match('#^/(base|overlay|broken)/(\d+)/(\d+)/(\d+)\.png$#', (string) $path, $m)) {
    http_response_code(404);
    exit;
}
[, $layer, $z, $x, $y] = $m;

if ($layer === 'broken') {
    http_response_code(404);
    exit;
}

$im = imagecreatetruecolor(256, 256);
if ($layer === 'base') {
    [$r, $g, $b] = tileColor((int) $x, (int) $y);
    imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
} else {
    imagesavealpha($im, true);
    imagealphablending($im, false);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagefilledrectangle($im, 120, 120, 135, 135, imagecolorallocate($im, 255, 255, 255));
}

header('Content-Type: image/png');
imagepng($im);

/**
 * The fill colour of a base tile.
 *
 * Tests compute the same colour to check which tile was drawn where.
 *
 * @param int $x Tile column.
 * @param int $y Tile row.
 *
 * @return array{int, int, int} Red, green and blue, 0-255.
 */
function tileColor(int $x, int $y): array
{
    return [($x * 37) % 200 + 40, ($y * 53) % 200 + 40, (($x + $y) * 11) % 200 + 40];
}

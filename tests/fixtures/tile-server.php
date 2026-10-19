<?php

/**
 * Fake tile server for the integration tests (router script for `php -S`).
 *
 * Serves deterministic PNG tiles so renders never touch a real tile host.
 * URL layout: `/<layer>/<z>/<x>/<y>.png`, where `<layer>` is one of:
 *
 * - `base`    Opaque tile filled with a colour derived from x and y (see
 *             RenderTestCase::tileColor()), so neighbouring tiles are told apart.
 * - `overlay` Transparent tile with an opaque 16x16 white square in its centre.
 * - `broken`  Always answers 404, so the renderer has to fall back to a gray tile.
 *
 * - `cached`  Like base, with `Cache-Control: max-age=<max_age query>` and an
 *             ETag; answers 304 to a matching If-None-Match.
 * - `nostore` Like base, with `Cache-Control: no-store`.
 * - `flaky`   Like cached, but answers 500 while the file named by the
 *             TILE_FAIL_FILE environment variable exists.
 * - `header`  Like base, but only with the request header `X-Api-Key: fixture-header-key`;
 *             otherwise 403.
 * - `bounce`  Redirects to the same tile of `base` (for "headers don't follow redirects").
 *
 * A few fixed paths (`/big.png`, `/slow.png`, `/redirect-…`, `/not-found.png`)
 * test the download limits.
 *
 * Every request is appended to the file named by the TILE_LOG environment
 * variable as one JSON object per line: path, query string, User-Agent and
 * If-None-Match.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$log = getenv('TILE_LOG');
if (is_string($log) && $log !== '') {
    file_put_contents($log, json_encode([
        'path'  => $path,
        'query' => $_SERVER['QUERY_STRING'] ?? '',
        'ua'    => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'inm'   => $_SERVER['HTTP_IF_NONE_MATCH'] ?? null,
        'key'   => $_SERVER['HTTP_X_API_KEY'] ?? null,
    ]) . "\n", FILE_APPEND | LOCK_EX);
}

// Routes for the download-limit tests (RemoteFetcherTest, #security hardening).
switch ($path) {
    case '/big.png':
        // A body larger than the test config's remote.max_bytes (200000).
        header('Content-Type: image/png');
        echo str_repeat('x', 300000);
        exit;
    case '/slow.png':
        // Answers after the test config's remote.timeout (2 seconds).
        sleep(4);
        header('Content-Type: image/png');
        echo 'late';
        exit;
    case '/redirect-loop':
        header('Location: /redirect-loop', true, 302);
        exit;
    case '/redirect-once':
        header('Location: /overlay/1/1/1.png', true, 302);
        exit;
    case '/redirect-file':
        header('Location: file:///etc/hostname', true, 302);
        exit;
    case '/not-found.png':
        // A real image with an error status.
        http_response_code(404);
        header('Content-Type: image/png');
        $im = imagecreatetruecolor(4, 4);
        imagepng($im);
        exit;
}

$layers = 'base|overlay|broken|cached|nostore|flaky|header|bounce';
if (!preg_match('#^/(' . $layers . ')/(\d+)/(\d+)/(\d+)\.png$#', (string) $path, $m)) {
    http_response_code(404);
    exit;
}
[, $layer, $z, $x, $y] = $m;

if ($layer === 'broken') {
    http_response_code(404);
    exit;
}

// Provider request headers (#28): the key comes in a header, not the URL.
if ($layer === 'header') {
    if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== 'fixture-header-key') {
        http_response_code(403);
        exit;
    }
    $layer = 'base';
}
if ($layer === 'bounce') {
    header("Location: /base/$z/$x/$y.png", true, 302);
    exit;
}

// Tile-cache test layers (TileCacheTest). They draw like `base`.
if (in_array($layer, ['cached', 'nostore', 'flaky'], true)) {
    $fail = getenv('TILE_FAIL_FILE');
    if ($layer === 'flaky' && is_string($fail) && $fail !== '' && is_file($fail)) {
        http_response_code(500);
        exit;
    }
    $etag = "\"$z-$x-$y\"";
    if ($layer === 'nostore') {
        header('Cache-Control: no-store');
    } else {
        header('Cache-Control: max-age=' . (int) ($_GET['max_age'] ?? 0));
        header("ETag: $etag");
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            exit;
        }
    }
    $layer = 'base';
}

$im = imagecreatetruecolor(256, 256);
if ($layer === 'base') {
    // Same formula as RenderTestCase::tileColor(), which tests use to check placement.
    $x = (int) $x;
    $y = (int) $y;
    $color = imagecolorallocate($im, ($x * 37) % 200 + 40, ($y * 53) % 200 + 40, (($x + $y) * 11) % 200 + 40);
    imagefill($im, 0, 0, $color);
} else {
    imagesavealpha($im, true);
    imagealphablending($im, false);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagefilledrectangle($im, 120, 120, 135, 135, imagecolorallocate($im, 255, 255, 255));
}

header('Content-Type: image/png');
imagepng($im);

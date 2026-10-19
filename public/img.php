<?php

/**
 * Deprecated entry point, kept so existing `/img.php?...` map URLs keep working.
 *
 * The renderer moved to index.php (#24); request `/?...` instead. This file
 * renders exactly the same map and adds `Deprecation` (RFC 9745) and `Link`
 * headers. It will be removed in a future major version, 2.0.0 at the earliest.
 * See "Versioning and deprecation" in README.md.
 */

header('Deprecation: @1792281600'); // 2026-10-18
header('Link: <https://github.com/dshanske/Static-Maps-API-PHP#deprecated>; rel="deprecation"');

require __DIR__ . '/index.php';

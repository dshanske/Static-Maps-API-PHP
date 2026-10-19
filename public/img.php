<?php

/**
 * Deprecated entry point, kept so existing `/img.php?...` map URLs keep working.
 *
 * The renderer moved to index.php (#24); request `/?...` instead. This file
 * renders exactly the same map and adds `Deprecation` (RFC 9745) and `Link`
 * headers. It will be removed in a future major version, 2.0.0 at the earliest.
 * See "Versioning and deprecation" in README.md.
 */

// index.php sees that the request came in through this file and adds the
// Deprecation and Link headers to the response.
require __DIR__ . '/index.php';

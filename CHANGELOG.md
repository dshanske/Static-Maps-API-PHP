# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
See [Versioning and deprecation](README.md#versioning-and-deprecation) for what
counts as a breaking change and how long deprecated features keep working.

Every pull request that changes something users or installs can notice (request
parameters, responses, configuration settings, requirements) adds a line under
`Unreleased`, in the matching section, with its issue or PR number.

## [Unreleased]

Work towards 1.0.0, the first tagged release. Until then `main` is not a
stable interface; pin a commit if you need a fixed point.

### Added

- Settings files: `config/defaults.php` holds every default, documented, and
  `data/config.php` overrides it per install (template:
  `data/config.example.php`; another path can be set with the
  `STATIC_MAPS_CONFIG` environment variable). The defaults cover image size,
  path style, output format and quality, the default basemap and attribution,
  the zoom range, the User-Agent sent to tile servers, and the basemaps.
  Basemaps can be switched off, aliased, limited to a zoom range, restricted to
  requests with a token, given their own attribution, or use an API key via
  `{key}` in the tile URL. A broken settings file makes every request return
  HTTP 500 and logs the reason. (#23, #27)
- `token_file` setting: where the access tokens are read from. The default is
  `data/apikeys.txt`, as before. (#14, #44)
- Query API: `GET /api/v1/`, `/api/v1/providers` and `/api/v1/icons` return
  JSON describing the service, the maps it can draw (with zoom ranges and
  attribution) and its marker icons, without tile URLs or keys. Token-only
  maps are listed only for requests with a valid token. (#25)
- `provider` and `style` request parameters to pick a map, for example
  `provider=carto&style=dark`. Style names are shared between providers where
  they mean the same thing (`streets`, `light`, `dark`, `satellite`, `hybrid`,
  `topo`, `oceans`, `-nolabels`). `provider=custom` with `tileurl` replaces
  `basemap=custom`. (#40)
- `providers` setting: tile providers with their styles. Settings such as the
  API key, attribution, zoom range, `requires_auth` and `enabled` are set once
  per provider and inherited by its styles, which can override them. (#40)
- `X-Map-Fallback` response header when the requested provider, style or
  basemap can't be drawn and the default map is drawn instead: `removed` (with
  a `Link` to the README's list of removed maps) or `unavailable`, plus the
  requested and drawn ids. The `removed` setting lists removed ids; installs
  can add their own. (#52)
- Tile cache (#11): downloaded tiles are kept on disk (on by default, in
  `data/cache/tiles/`) for as long as the provider's cache headers allow, or
  7 days when it sends none; expired tiles are checked again with conditional
  requests, and a cached tile stands in for a failed download for up to 7
  days. Providers with `cache => 'off'` are never cached. New `cache.tiles`
  settings, and `bin/prune-cache.php` to run from cron or a systemd timer,
  which keeps the cache under `cache.tiles.max_size` (1 GB by default).
- Client keys and access modes (#28). Callers send a client key as the new
  `key` parameter. `access.mode = 'open'` (default) keeps free maps open to
  everyone and requires a client key for maps whose tiles use a provider key
  the install holds, `requires_auth` maps and the URL features;
  `access.mode = 'restricted'` requires one for every map (403 without it;
  the home page and query API stay open). Keys are issued with the new
  `bin/clients.php` (`add`, `list`, `set`, `rotate`, `revoke`, `import`),
  which stores only their SHA-256 hashes in `data/clients.json`; clients can
  be limited to some providers and kept from external URLs. The query API
  reports the access mode and lists keyed maps only to clients that may use
  them.
- Cache headers on map images (#11): `Cache-Control` (7 days by default, the
  new `cache.max_age` setting; `private` for requests with a token and for
  providers with `cache => 'off'`; 5 minutes for fallback maps; `no-store` on
  errors) and an `ETag`, with `304 Not Modified` for a matching
  `If-None-Match`. The README shows how to cache maps in nginx.
- Text attribution (#29): each provider or style can set `attribution_text`,
  the credit its terms require, drawn on a light strip in the attribution
  corner (wrapped to two lines, cut short with "…" on small images), with the
  logo, if any, next to it. New `attribution=text` (text only) and
  `attribution_position=bottomright|bottomleft|topright|topleft`. The text on
  the image is kept to what the provider requires there; the full credit, as
  HTML with links (`attribution_html`), comes from the new
  `GET /api/v1/attribution`, which takes the same parameters as the image, so
  a page can show it next to the image. A font (DejaVu Sans, free licence) is
  bundled in `assets/fonts/`.
- `limits.max_width` / `limits.max_height` settings (default 1280 each): the
  largest image a request can get. Larger `width`/`height` values are drawn at
  the limit, and the response has an `X-Map-Size` header saying so. Reported
  by `/api/v1/`. (#9)
- `zoom.max_limit` setting: an optional cap on the zoom of every map, whatever
  its own maximum. Not set by default. (#54)
- A request without any parameters (for example opening the service's address
  in a browser) returns an HTML page that describes the service. Installs can
  use their own page with the `home_page` setting. (#47)

### Changed

- Maps whose tiles use a provider API key held by the install (for example
  CARTO once a key is set) now need a client key in the default `open` mode,
  so the install's provider allowance can't be spent by anyone who finds the
  URL. (#28)

- The shipped maps draw the credit their providers require: OpenStreetMap,
  OpenTopoMap and CARTO draw their credit text instead of the OpenStreetMap
  logo, and Esri maps draw their main data sources under the "Powered by Esri"
  logo (the full list is in `/api/v1/attribution`).
  (#29)

- **Breaking:** `polyline=enc:...` now reads each point in latitude, longitude
  order, as Google's Encoded Polyline Algorithm defines it and as Google Maps,
  Mapbox, OSRM and other encoders produce it. It used to read longitude,
  latitude, so a standard polyline was drawn far from where it belonged.
  If you encoded points as longitude, latitude to match the old behaviour,
  re-encode them as latitude, longitude. (#43)

- PHP 8.1 or later is required. (#13)
- Basemaps are defined in the settings files instead of in `public/img.php`.
  If you edited the basemap list in `img.php`, move your changes to
  `data/config.php`, as `providers` (#23, #27, #40).
- Attribution logos moved from `public/images/` to `assets/attribution/`, which
  is not served to the web. (#27)
- The code is now organised as classes in the `StaticMaps` namespace in
  `include/`; the entry point only loads the settings and runs the renderer.
  It no longer depends on being run from `public/`. Rendered maps are
  unchanged. (#14, #44)
- The renderer is now `public/index.php`, so maps are served from the site
  root: `/?latitude=...&longitude=...`. In nginx, change
  `try_files $uri /img.php?$args;` to `try_files $uri /index.php?$args;`.
  (#24)

- Each shipped provider and style now has its own maximum zoom, checked by
  sampling tiles: OpenStreetMap and most Esri styles 19, Esri Light Gray Canvas
  and National Geographic 16, Esri Oceans 13, OpenTopoMap 17, CARTO 20.
  Requests above a style's maximum are drawn at it, instead of getting
  placeholder tiles (e.g. Esri Light Gray Canvas at 17 and 18), and maps that go
  deeper than the old cap of 18 can now be requested at those levels.
  `zoom.min` / `zoom.max` remain the range for providers and styles without
  their own. (#54)

### Deprecated

- The `token` parameter (use `key`) and the token file `data/apikeys.txt` /
  `token_file` (run `php bin/clients.php import`). Both still work: requests
  with `token` get `Deprecation` headers, and an install with a token file
  logs a message per request. Removed no earlier than 2.0.0. (#28)

- The `basemap` request parameter. Every existing id keeps working as an
  alias of a provider and style (listed in the README), with `Deprecation`
  and `Link` headers. New providers and styles get no `basemap` id. Removed
  no earlier than 2.0.0. (#40)
- The `basemaps` and `defaults.basemap` settings, replaced by `providers` and
  `defaults.provider` / `defaults.style`. Install files that use them keep
  working: they are translated on load, and each request logs a deprecation
  message. Removed no earlier than 2.0.0. (#40)
- The `location` parameter and `location:` in markers. They are ignored and
  the response gets `Deprecation` and `Link` headers. A request that has
  only a `location` gets HTTP 400 saying so, and a marker that has only a
  `location` is skipped with an `X-Marker-<n>` header. Use
  `latitude`/`longitude` and `lat`/`lng`. (#16)
- `/img.php`. It still renders the same map, with `Deprecation` and `Link`
  headers; use `/` with the same parameters. Removed no earlier than 2.0.0.
  (#24)

### Fixed

- Numeric parameters are checked (#9). A `width`, `height`, `zoom`,
  `maxzoom`, `quality`, `bezier`, `latitude` or `longitude` that isn't a
  number, or is out of range, gets HTTP 400 with the reason, instead of a PHP
  error (`width=abc` was a 500) or a huge image (`width=50000`). Markers and
  paths with invalid coordinates are left out with an `X-Marker-<n>` /
  `X-Path-<n>` header. An empty value counts as not given, and `zoom=0` is now
  zoom level 0 (raised to the map's minimum) instead of "fit the markers".

- PHP 8 errors and warnings (#18):
  - `attribution=mapbox-small` was a fatal error; it now draws the Mapbox
    logo at half size.
  - `attribution=none` drew the OpenStreetMap logo; it now draws no logo. An
    unknown `attribution` value counts as none given, so the map's own
    attribution is used.
  - An unknown `format` returned an empty page; it now returns HTTP 400. The
    `defaults.format` setting is checked when the configuration loads.
  - A marker whose icon can't be used (unknown icon name, icon URL without a
    valid token, or a URL that isn't an image) is skipped with an
    `X-Marker-<n>` header saying why, instead of a PHP warning or a fatal
    error. An attribution URL that isn't an image is skipped instead of
    failing.
  - Overlay tiles (labels) are requested with the configured User-Agent, like
    base tiles.
  - No more deprecation notices on PHP 8.1-8.5 (`imagedestroy()`, float to
    int conversions in GD calls, `ord()` in polyline decoding). Rendered maps
    are unchanged.

- CARTO maps (`provider=carto`, old `basemap=carto-*`) drew CARTO's "API KEY
  REQUIRED" image across the whole map, because CARTO stopped serving raster
  tiles without a key on 2026-09-25. The CARTO styles now use CARTO's keyed
  tile URLs and need `'providers' => ['carto' => ['key' => '...']]`; until a
  key is set, requests get the default map with `X-Map-Fallback:
  unavailable`. More generally, any style whose tile URLs use `{key}` is
  unavailable until a key is set. (#17)

- Esri maps draw the "Powered by Esri" logo by default instead of the
  OpenStreetMap logo. The OpenStreetMap, OpenTopoMap and CARTO providers set
  the OpenStreetMap logo themselves, so changing `defaults.attribution` no
  longer changes their credit. A request's `attribution` still overrides
  both. (#50)
- The Esri `oceans` style (old `basemap=oceans`) draws tiles again. Esri
  retired the Ocean_Basemap service; the style now uses World Ocean Base with
  World Ocean Reference labels on top, up to zoom 13 (where Esri's detailed
  ocean data ends). (#17)
- A request that doesn't say where the map is (no `latitude` and
  `longitude`, no marker or path) returns HTTP 400 with the
  reason, instead of a meaningless map centred on 0,0. So does a request with
  only one of `latitude` / `longitude`, which used to treat the missing one as
  0. (#47)

### Removed

- Geocoding (`include/ArcGISGeocoder.php`). Turning addresses into
  coordinates is the client's job, and it had been broken since ArcGIS
  stopped accepting HTTP: every lookup failed. The `X-Geocode` and
  `X-Geocode-Result` headers are gone too. (#16)

- The Stamen basemaps (`stamen-toner`, `stamen-toner-background`,
  `stamen-toner-lite`, `stamen-terrain`, `stamen-terrain-background`,
  `stamen-watercolor`). Stamen's tile hosting shut down in 2023, so they only
  drew gray tiles; requests for them now get the default map. (#17, #40)

- `include/helpers.php` (`request()` and `is_authenticated()`), replaced by the
  `StaticMaps\Request` and `StaticMaps\Tokens` classes. (#14, #44)

### Security

- Attribution image URLs (`attribution=https://…`) now need a valid `token`,
  like marker icon URLs and custom tile URLs. Without one the map's own
  attribution is drawn and an `X-Map-Attribution` header says why.
- Every download from another server (tiles, icon and attribution images) is
  limited by the new `remote` setting: http and https only, at most 3
  redirects, 10 seconds and 5 MB by default. Icon and attribution images also
  send the configured User-Agent now.
- Tokens are compared exactly and in constant time.
- Built-in icon names are limited to letters, digits, `-` and `_`.
- `SECURITY.md` explains how to report vulnerabilities privately.

## History before versioning

From 2013 to 2014 this was Aaron Parecki's Esri Static Maps API: tile
compositing, markers with built-in and remote icons, paths drawn with
ImageMagick, automatic zoom, `maxzoom`, and POST requests. He continued the
project as [Atlas](https://github.com/aaronpk/atlas). David Shanske later took
over maintenance, and between 2020 and 2023 it gained OpenStreetMap,
OpenTopoMap and CARTO basemaps, HTTPS tile URLs, a User-Agent for tile
requests, custom tile URLs, encoded polylines, bezier paths, Mapbox and
OpenStreetMap attribution logos and custom attribution images. It also took
token protection for features that fetch external URLs from Atlas, along with
syntax fixes. None of this was released under a version number.

[Unreleased]: https://github.com/dshanske/Static-Maps-API-PHP/commits/main

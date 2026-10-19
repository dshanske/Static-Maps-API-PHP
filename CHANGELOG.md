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
- A request without any parameters (for example opening the service's address
  in a browser) returns an HTML page that describes the service. Installs can
  use their own page with the `home_page` setting. (#47)

### Changed

- PHP 8.1 or later is required. (#13)
- Basemaps are defined in the settings files instead of in `public/img.php`.
  If you edited the basemap list in `img.php`, move your changes to
  `data/config.php`. (#23, #27)
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

### Deprecated

- `/img.php`. It still renders the same map, with `Deprecation` and `Link`
  headers; use `/` with the same parameters. Removed no earlier than 2.0.0.
  (#24)

### Fixed

- A request that doesn't say where the map is (no `latitude` and
  `longitude`, no marker or path, no `location`) returns HTTP 400 with the
  reason, instead of a meaningless map centred on 0,0. So does a request with
  only one of `latitude` / `longitude`, which used to treat the missing one as
  0. (#47)

### Removed

- `include/helpers.php` (`request()` and `is_authenticated()`), replaced by the
  `StaticMaps\Request` and `StaticMaps\Tokens` classes. (#14, #44)

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

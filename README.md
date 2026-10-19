Static Maps API
===============

[![CI](https://github.com/dshanske/Static-Maps-API-PHP/actions/workflows/ci.yml/badge.svg)](https://github.com/dshanske/Static-Maps-API-PHP/actions/workflows/ci.yml)


## Parameters

Parameters can be sent in either the query string or in the POST body.

Every map needs a location: `latitude` **and** `longitude`, or at least one valid marker or path (the map is then fitted to them), or a `location` to geocode. A request without one gets HTTP 400 with the reason as plain text, as does a request with only one of `latitude` / `longitude`. A request with no parameters at all, such as opening the service's address in a browser, gets an HTML page describing the service (see `home_page` under Configuration).

* `zoom` - optional - Set the zoom level for the map. If not specified, a zoom level will be chosen that contains all markers on the map.
* `maxzoom` - optional - When a zoom level is chosen automatically, this sets an upper limit on the zoom level that will be chosen. Useful if you know your maps don't have imagery past a certain zoom level.
* `width` - default 300 - Width in pixels of the final image
* `height` - default 300 - Height in pixels of the final image
* `provider` - default "osm" - The tile provider. See [Providers and styles](#providers-and-styles).
* `style` - default: the provider's default style - The map style of that provider. With only `style`, the default provider's style of that name is used. An unknown, disabled or token-only (without a token) provider or style gets the default map.
* `tileurl` - With `provider=custom` and a valid `token`: a tile URL template (`{z}`, `{x}`, `{y}`) to draw instead of a provider.
* `basemap` - **deprecated** - The old single-id way to pick a map; see [Deprecated basemap ids](#deprecated-basemap-ids). Ignored when `provider` or `style` is given.
* `attribution` - default: the provider's, else "osm" - "esri", "osm", "mapbox" or "none" or specify a full URL to a png image - If you add attribution on the image in some other way, you can set this to "none" to hide all logos.
* `latitude` - Latitude to center the map at. Not needed if using the location parameter, or if specifying one or more markers or paths. Must be given together with `longitude`.
* `longitude` - Longitude to center the map at. Must be given together with `latitude`.
* `location` - optional - Free-form text that will be geocoded to center the map. Not needed if specifying a location with the latitude and longitude parameters, or if a marker is specified.
* `marker[]` - Specify one or more markers to overlay on the map. Parameters are specified as: `key:value;`. See below for the full list of parameters. 
* `path[]` - Specify one or more paths to draw on the map. See below for the full list of parameters to draw a path.
* `polyline` - Alternative to path, this allows for a path to be specified as an encoded polyline, allowing for shorter URLs.
* `bezier` - Specify a bezier curve to your path. 25 will give you a nicely curved line. [More Info](https://aaronparecki.com/2017/01/02/6/day-13-curved-map-lines)

## Providers and styles

Pick a map with `provider` and `style`, for example `provider=carto&style=dark`. Style names are shared between providers where they mean the same thing, so `style=topo` or `style=light` work on any provider that has one:

| Style | Meaning |
|---|---|
| `streets` | General reference map |
| `light` / `dark` | Muted light or dark canvas |
| `satellite` | Imagery without labels |
| `hybrid` | Imagery with labels |
| `topo` | Topographic map |
| `oceans` | Ocean floor (bathymetry) |
| `…-nolabels` | The same style without labels |

Shipped providers (the first style is the provider's default):

| `provider` | Styles |
|---|---|
| `osm` | `streets`: [OpenStreetMap](https://www.openstreetmap.org/) |
| `esri` | `streets`: [World Street Map](https://www.arcgis.com/home/webmap/viewer.html?webmap=7990d7ea55204450b8110d57e20c99ab) · `satellite`: [World Imagery](https://www.arcgis.com/home/webmap/viewer.html?webmap=d802f08316e84c6592ef681c50178f17&center=-71.055499,42.364247&level=15) · `hybrid` · `topo`: [World Topographic Map](https://www.arcgis.com/home/webmap/viewer.html?webmap=a72b0766aea04b48bf7a0e8c27ccc007) · `light`: Light Gray Canvas · `light-nolabels`: [Light Gray Canvas](https://www.arcgis.com/home/webmap/viewer.html?webmap=8b3d38c0819547faa83f7b7aca80bd76) without labels · `oceans`: [World Ocean Base](https://server.arcgisonline.com/ArcGIS/rest/services/Ocean/World_Ocean_Base/MapServer) with labels, up to zoom 13 · `natgeo`: [National Geographic](https://www.arcgis.com/home/webmap/viewer.html?webmap=d94dcdbe78e141c2b2d3a91d5ca8b9c9) |
| `opentopomap` | `topo`: [OpenTopoMap](https://www.opentopomap.org/) |
| `carto` | `light`: Positron · `dark`: Dark Matter · `streets`: Voyager. [CARTO](https://carto.com/basemaps/): free for non-commercial use; CARTO now requires an API key (#17). |
| `custom` | Draws `tileurl`; requires a valid `token`. |

Esri styles draw the "Powered by Esri" logo by default; the OpenStreetMap, OpenTopoMap and CARTO styles draw the OpenStreetMap logo. Each shipped provider sets this itself, so changing `defaults.attribution` only affects providers you add. The `attribution` parameter overrides either.

Installs can switch providers or styles off, add their own, and set API keys; see [Configuration](#configuration).

### Deprecated basemap ids

`basemap=<id>` still works for these ids, with `Deprecation` and `Link` response headers. New providers and styles have no `basemap` id. The Stamen ids (`stamen-toner`, `stamen-terrain`, `stamen-watercolor` and their variants) were removed because Stamen's tile hosting shut down in 2023; requests for them get the default map.

| `basemap` | Same as |
|---|---|
| `osm` | `provider=osm&style=streets` |
| `streets`, `satellite`, `hybrid`, `topo` | `provider=esri&style=` the same name |
| `gray` | `provider=esri&style=light` |
| `gray-background` | `provider=esri&style=light-nolabels` |
| `oceans` | `provider=esri&style=oceans` |
| `national-geographic` | `provider=esri&style=natgeo` |
| `otm` | `provider=opentopomap&style=topo` |
| `carto-light`, `carto-dark` | `provider=carto&style=light` / `dark` |
| `carto-voyager` | `provider=carto&style=streets` |
| `custom` | `provider=custom` |

## Markers

* `location` - Free-form text that will be geocoded to place the pin
* `lat` - If a `location` is not provided, you can specify the location with the `lat` and `lng` parameters.
* `lng` - See above
* `icon` - Icon to use for the marker. Must choose one of the icons provided in this library, or specify a full URL to a png image. If an invalid icon is specified, the marker will not be rendered. Using a URL required authentication.


### Built-In Marker Images

* ![dot-large-blue](public/images/dot-large-blue.png) `dot-large-blue`
* ![dot-large-gray](public/images/dot-large-gray.png) `dot-large-gray`
* ![dot-large-green](public/images/dot-large-green.png) `dot-large-green`
* ![dot-large-orange](public/images/dot-large-orange.png) `dot-large-orange`
* ![dot-large-pink](public/images/dot-large-pink.png) `dot-large-pink`
* ![dot-large-purple](public/images/dot-large-purple.png) `dot-large-purple`
* ![dot-large-red](public/images/dot-large-red.png) `dot-large-red`
* ![dot-large-yellow](public/images/dot-large-yellow.png) `dot-large-yellow`
* ![dot-small-blue](public/images/dot-small-blue.png) `dot-small-blue`
* ![dot-small-gray](images/dot-small-gray.png) `dot-small-gray`
* ![dot-small-green](images/dot-small-green.png) `dot-small-green`
* ![dot-small-orange](images/dot-small-orange.png) `dot-small-orange`
* ![dot-small-pink](images/dot-small-pink.png) `dot-small-pink`
* ![dot-small-purple](images/dot-small-purple.png) `dot-small-purple`
* ![dot-small-red](images/dot-small-red.png) `dot-small-red`
* ![dot-small-yellow](images/dot-small-yellow.png) `dot-small-yellow`
* ![fb](images/fb.png) `fb`
* ![google](images/google.png) `google`
* ![large-blue-blank](images/large-blue-blank.png) `large-blue-blank`
* ![large-blue-cutout](images/large-blue-cutout.png) `large-blue-cutout`
* ![large-gray-blank](images/large-gray-blank.png) `large-gray-blank`
* ![large-gray-cutout](images/large-gray-cutout.png) `large-gray-cutout`
* ![large-gray-user](images/large-gray-user.png) `large-gray-user`
* ![large-green-blank](images/large-green-blank.png) `large-green-blank`
* ![large-green-cutout](images/large-green-cutout.png) `large-green-cutout`
* ![large-orange-blank](images/large-orange-blank.png) `large-orange-blank`
* ![large-orange-cutout](images/large-orange-cutout.png) `large-orange-cutout`
* ![large-pink-blank](images/large-pink-blank.png) `large-pink-blank`
* ![large-pink-cutout](images/large-pink-cutout.png) `large-pink-cutout`
* ![large-purple-blank](images/large-purple-blank.png) `large-purple-blank`
* ![large-purple-cutout](images/large-purple-cutout.png) `large-purple-cutout`
* ![large-red-blank](images/large-red-blank.png) `large-red-blank`
* ![large-red-cutout](images/large-red-cutout.png) `large-red-cutout`
* ![large-yellow-blank](images/large-yellow-blank.png) `large-yellow-blank`
* ![large-yellow-cutout](images/large-yellow-cutout.png) `large-yellow-cutout`
* ![large-yellow-message](images/large-yellow-message.png) `large-yellow-message`
* ![large-yellow-user](images/large-yellow-user.png) `large-yellow-user`
* ![small-blue-blank](images/small-blue-blank.png) `small-blue-blank`
* ![small-blue-cutout](images/small-blue-cutout.png) `small-blue-cutout`
* ![small-gray-blank](images/small-gray-blank.png) `small-gray-blank`
* ![small-gray-cutout](images/small-gray-cutout.png) `small-gray-cutout`
* ![small-gray-message](images/small-gray-message.png) `small-gray-message`
* ![small-gray-user](images/small-gray-user.png) `small-gray-user`
* ![small-green-blank](images/small-green-blank.png) `small-green-blank`
* ![small-green-cutout](images/small-green-cutout.png) `small-green-cutout`
* ![small-green-user](images/small-green-user.png) `small-green-user`
* ![small-orange-blank](images/small-orange-blank.png) `small-orange-blank`
* ![small-orange-cutout](images/small-orange-cutout.png) `small-orange-cutout`
* ![small-pink-blank](images/small-pink-blank.png) `small-pink-blank`
* ![small-pink-cutout](images/small-pink-cutout.png) `small-pink-cutout`
* ![small-pink-user](images/small-pink-user.png) `small-pink-user`
* ![small-purple-blank](images/small-purple-blank.png) `small-purple-blank`
* ![small-purple-cutout](images/small-purple-cutout.png) `small-purple-cutout`
* ![small-red-blank](images/small-red-blank.png) `small-red-blank`
* ![small-red-cutout](images/small-red-cutout.png) `small-red-cutout`
* ![small-yellow-blank](images/small-yellow-blank.png) `small-yellow-blank`
* ![small-yellow-cutout](images/small-yellow-cutout.png) `small-yellow-cutout`
* ![small-yellow-user](images/small-yellow-user.png) `small-yellow-user`


## Authentication

To be able to use externally-referenced icons or tile URLs, you will need to configure API keys and provide a token in the request. This locks down the ability to fetch external resources to only trusted users of the system.

Create a file data/apikeys.txt and generate a random string with a tool of your choosing, and with one API key per line. Any value passed in the parameter token that matches the text in a line in this file will enable the request to use the restricted features that reference external URLs. To keep the file somewhere else, set `token_file` in `data/config.php` (see Configuration).

## Configuration

Settings such as the providers and styles, default image size, zoom limits and the User-Agent sent to tile servers are in two PHP files that each return an array:

| File | Purpose |
|---|---|
| `config/defaults.php` | The shipped defaults. Every setting is documented here. **Don't edit this file**: it's part of the code and is replaced when you update. |
| `data/config.php` | Your install's settings. Not part of the repository, so it survives updates. Start by copying `data/config.example.php`. |

```
cp data/config.example.php data/config.php
```

Put only the settings you want to change in `data/config.php`. It is merged over the defaults: arrays with named keys (such as `defaults`, `zoom`, `providers`, each provider and each style) are merged key by key, and everything else, including lists such as a style's `layers`, replaces the default. For example:

```php
<?php
return [
    'defaults'   => ['width' => 600, 'height' => 400, 'provider' => 'carto', 'style' => 'light'],
    'user_agent' => 'Static Maps API/ maps.example.com (admin@example.com)',
    'providers'  => [
        // Switch a style off, or a whole provider
        'esri'        => ['styles' => ['natgeo' => ['enabled' => false]]],
        'carto'       => ['enabled' => false],
        // Limit a provider's zoom range
        'opentopomap' => ['maxzoom' => 17],
        // Add a provider that needs an API key ({key} is replaced with 'key')
        'stadia' => [
            'name'   => 'Stadia Maps',
            'key'    => 'your-stadia-api-key',
            'styles' => [
                'toner' => [
                    'name'   => 'Stamen Toner',
                    'layers' => ['https://tiles.stadiamaps.com/tiles/stamen_toner/{z}/{x}/{y}.png?api_key={key}'],
                ],
            ],
        ],
    ],
];
```

Each provider has a `name`, an optional `default_style`, and `styles`. Each style has a `name` and a list of one or two tile URL `layers` (a base map and an optional overlay drawn on top). A provider can set a default `attribution`, `minzoom` / `maxzoom`, `requires_auth` (only usable with a valid token), `enabled` and an API `key`; its styles inherit these and can override any of them. See `config/defaults.php` for details.

Install files written for the older format (`basemaps` and `defaults.basemap`) keep working: they are translated to providers and styles, and each request logs a deprecation message until you update the file.

A request without any parameters returns the HTML page in `home_page` (default `assets/home.html`, which names the service and links to this documentation). To describe your own install, point `home_page` at your own HTML file. It is sent as-is and is not run as PHP.

To keep the file somewhere else, set the `STATIC_MAPS_CONFIG` environment variable to its full path, for example with `env[STATIC_MAPS_CONFIG] = /etc/static-maps/config.php` in your PHP-FPM pool.

If the configuration is invalid, every request returns HTTP 500 and the reason is written to the PHP error log.

## Paths

A path is specified as a list of longitude and latitudes, as well as optional properties to specify the weight and color of the path.

The coordinates of the path are the first value of the property, specified as a list of coordinates similar to GeoJSON.

### Examples

Simple path with default color and weight.

```
path[]=[-122.651082,45.508543],[-122.653617,45.506468],[-122.654183,45.506756]
```

Specifying the color and weight of the path.

```
path[]=[-122.651082,45.508543],[-122.653617,45.506468],[-122.654183,45.506756];weight:6;color:0033ff
```

## Polylines

A polyline is an encoded string representing a set of points, as well as optional properties to specify the weight and color of the path.

The encoded polyline first value of the property, followed by the optional color and weight.

### Examples

Simple path with default color and weight.

```
polyline=enc:abcdethtihwithieht3
```

Specifying the color and weight of the path.

```
polyline=enc:abcdefghghgk;weight:6;color:0033ff
```

## Examples

### Simple map centered at a location

```
http://static-maps.pdx.esri.com/?provider=esri&style=light&width=400&height=240&zoom=14&latitude=45.5165&longitude=-122.6764
```

<img src="http://static-maps.pdx.esri.com/?provider=esri&style=light&width=400&height=240&zoom=14&latitude=45.5165&longitude=-122.6764">

### Map with a marker centered at an address

```
http://static-maps.pdx.esri.com/?marker[]=location:920%20SW%203rd%20Ave,%20Portland,%20OR;icon:small-blue-cutout&provider=esri&style=light&width=400&height=240&zoom=14
```

<img src="http://static-maps.pdx.esri.com/?marker[]=location:920%20SW%203rd%20Ave,%20Portland,%20OR;icon:small-blue-cutout&provider=esri&style=light&width=400&height=240&zoom=14">

### Map with an external marker image

```
http://static-maps.pdx.esri.com/?provider=esri&style=light&width=400&height=240&zoom=14&marker[]=lat:45.5165;lng:-122.6764;icon:http://aaronparecki.com/images/map-pin-green.png
```

<img src="http://static-maps.pdx.esri.com/?provider=esri&style=light&width=400&height=240&zoom=14&marker[]=lat:45.5165;lng:-122.6764;icon:http://aaronparecki.com/images/map-pin-green.png">


## How to install

How to install on a Debian or Ubuntu Server

### Install base packages

```
sudo apt update
sudo apt install git build-essential make bison flex gcc patch autoconf locate libssl-dev curl cmake libjpeg-dev libpng-dev libgif-dev libfreetype6 libfreetype6-dev imagemagick libmagickwand-dev libyaml-dev lynx htop nginx
```


### Install and Configure PHP

```
sudo apt update
sudo apt install php-fpm php-cli php-curl php-memcache php-dev php-imagick php-gd
```

Edit `/etc/php7.4/fpm/pool.d/www.conf` and uncomment the line `listen.mode = 0660`.

You may have to replace 7.4 with installed version

Restart PHP `sudo service php7.4-fpm restart`

### Configure the virtual host for nginx

Add the below server block to /usr/local/nginx/conf/nginx.conf and remove the default server block.

```
    server {
        listen 80;
        root /var/www/static-maps-api/public;
	try_files $uri /index.php?$args;

	location ~ \.php$ {
	       fastcgi_pass    unix:/var/run/php7.4-fpm.sock;
	       fastcgi_index   index.php;
               fastcgi_split_path_info ^(.+\.php)(.*)$;
               include fastcgi_params;
               fastcgi_param   SCRIPT_FILENAME $document_root$fastcgi_script_name;
        }
    }
```

### Clone the source

```
sudo mkdir -p /var/www/static-maps-api
sudo chown -R ubuntu: /var/www
cd /var/www/static-maps-api
git clone git@github.com:dshanske/Static-Maps-API-PHP.git .
```

## Versioning and deprecation

Releases follow [Semantic Versioning](https://semver.org/). The version numbers cover what users and installs depend on:

- the request parameters and what they do,
- the response: image formats, status codes and headers,
- the install settings in `data/config.php`,
- the query API (once it exists, #25), which also has its own version in its URL (`/api/v1/`). That number only changes when the query API itself breaks.

A **major** release (2.0.0) removes or changes any of these. A **minor** release (1.1.0) adds parameters, providers, styles or settings, or deprecates something; the old way keeps working. A **patch** release (1.0.1) fixes bugs without changing documented behaviour.

The first release will be **1.0.0**, once the first phase of the rewrite is complete (the [1.0 milestone](https://github.com/dshanske/Static-Maps-API-PHP/milestone/1)). There are no releases before it; `main` may change at any time until then. Every change is listed in [CHANGELOG.md](CHANGELOG.md).

**Deprecation.** A deprecated feature keeps working for at least the **three releases after the one that deprecates it** (minor and major releases count, patch releases don't), and is removed only in a major release. For example, something deprecated in 1.0.0 still works in 1.1, 1.2 and 1.3, and can be removed in 2.0.0 at the earliest. While it still works:

- responses that use it carry a `Deprecation` header ([RFC 9745](https://www.rfc-editor.org/rfc/rfc9745)) and a `Link` header pointing to this section (`rel="deprecation"`); a `Sunset` header ([RFC 8594](https://www.rfc-editor.org/rfc/rfc8594)) is added once a removal version is set,
- deprecated install settings keep working and log a warning,
- it is listed in the table below with its replacement and the earliest version that can remove it.

### Deprecated

| Deprecated | Use instead | Deprecated in | Removed no earlier than |
|---|---|---|---|
| Requesting `/img.php?...` | `/?...` (or `/index.php?...`) with the same parameters (#24) | 1.0.0 | 2.0.0 |
| The `basemap` parameter | `provider` and `style` ([mapping](#deprecated-basemap-ids), #40) | 1.0.0 | 2.0.0 |
| Settings `basemaps` and `defaults.basemap` in `data/config.php` | `providers` and `defaults.provider` / `defaults.style` (#40) | 1.0.0 | 2.0.0 |

## Development

Requires PHP 8.1 or later with the `curl`, `gd` and `imagick` extensions, and [Composer](https://getcomposer.org/) for the development tools. The service itself has no Composer dependencies at runtime.

```
composer install
composer check     # runs all of the below
composer lint      # syntax check of every PHP file
composer cs        # PSR-12 coding standard and PHP 8.1+ compatibility (PHP_CodeSniffer)
composer cs-fix    # fix coding standard violations automatically
composer stan      # static analysis (PHPStan)
composer test      # PHPUnit test suite
```

The code lives in `include/` as classes in the `StaticMaps` namespace (loaded by `include/autoload.php`). `public/index.php` only loads the configuration and hands the request to `StaticMaps\Renderer`, which runs the stages in order: `MarkerParser` and `PathParser` (overlays and their `Bounds`) → `Viewport` (centre and zoom) → `TileFetcher` → `Compositor` (tiles, paths, markers) → `Attribution` → image encoding.

Known PHPStan errors are recorded in `phpstan-baseline.neon` until they are fixed. When you fix one, regenerate the baseline with `vendor/bin/phpstan analyse --generate-baseline`.

### Changelog

Every pull request that changes something users or installs can notice (request parameters, responses, settings, requirements) adds a line to the `Unreleased` section of [CHANGELOG.md](CHANGELOG.md), with its issue or PR number. Deprecations also add a row to the table under [Deprecated](#deprecated). Internal changes (tests, tooling, refactoring with no visible effect) don't need an entry.

### Tests

`tests/Unit` tests the classes in `include/`. `tests/Integration` renders maps through `public/index.php` (and the deprecated `public/img.php`) with PHP's built-in web server, using a fake tile server (`tests/fixtures/tile-server.php`) and a test configuration (`tests/fixtures/config.php`) that switches off every real basemap, so the tests never contact a real tile host and need no network access.

- Any PHP warning, notice or deprecation fails the tests. Problems that are known and tracked in an issue are listed in `RenderTestCase::knownErrors()` until they are fixed.
- Rendered maps are compared with reference images in `tests/fixtures/expected/`, with a small tolerance for anti-aliasing differences between ImageMagick versions. After a change that is meant to alter the output, run `UPDATE_SNAPSHOTS=1 composer test`, look at the changed images, and commit them with the change. When a comparison fails, the rendered image is saved to the directory in `SNAPSHOT_FAILURES_DIR` (default: `static-maps-snapshots` in the system temp directory); in CI it is attached to the run as an artifact.

```
vendor/bin/phpunit --testsuite unit           # one suite
vendor/bin/phpunit --filter testBasemapChoice # one test
```

To run the service locally:

```
php -S localhost:8000 -t public
```

## License


```

Original version of code was noted copyright 2013 Esri, Inc. The original version was created by [Aaron Parecki](https://github.com/aaronpk) who has moved on to a newer version of the code in his
[Atlas API](https://github.com/aaronpk/Atlas). Maintenance of the original project, including the backporting of features as well as some additional tweaks, was taken on in 2022 by David Shanske.

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

    http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
```

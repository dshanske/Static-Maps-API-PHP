Static Maps API
===============

[![CI](https://github.com/dshanske/Static-Maps-API-PHP/actions/workflows/ci.yml/badge.svg)](https://github.com/dshanske/Static-Maps-API-PHP/actions/workflows/ci.yml)


## Parameters

Parameters can be sent in either the query string or in the POST body.

Every map needs a location: `latitude` **and** `longitude`, or at least one valid marker or path (the map is then fitted to them). A request without one gets HTTP 400 with the reason as plain text, as does a request with only one of `latitude` / `longitude`. A request with no parameters at all, such as opening the service's address in a browser, gets an HTML page describing the service (see `home_page` under Configuration).

Numbers are checked: a value that isn't a number, or is out of the range given below, gets HTTP 400 with the reason (for example `zoom must be between 0 and 30`). An empty value (`width=`) counts as not given.

* `zoom` - optional - Whole number, 0-30. Set the zoom level for the map; it is kept within the map's own zoom range. If not specified, the map uses the highest zoom at which everything fits: the markers (including their icons), paths (including their width and any `bezier` curve), `bbox` and `visible` points, with `padding` around them. Without a centre, the map is centred on them.
* `maxzoom` - optional - Whole number, 0-30. An upper limit on the zoom level, mostly useful when the zoom is chosen automatically.
* `width` - default 300 - Width in pixels of the final image, a whole number of 1 or more. At most 1280 by default (the install's `limits.max_width`): a larger width is drawn at the limit and the response has an `X-Map-Size: clamped; requested=5000x300; drawn=1280x300` header.
* `height` - default 300 - Height in pixels of the final image, a whole number of 1 or more. At most 1280 by default (`limits.max_height`), like `width`.
* `provider` - default "osm" - The tile provider. See [Providers and styles](#providers-and-styles).
* `style` - default: the provider's default style - The map style of that provider. With only `style`, the default provider's style of that name is used. An unknown, disabled or removed provider or style, or one that needs a client key the request doesn't have, gets the default map, and the response says so in an `X-Map-Fallback` header (see [Providers and styles](#providers-and-styles)).
* `tileurl` - With `provider=custom` and a valid `key`: a tile URL template (`{z}`, `{x}`, `{y}`) to draw instead of a provider.
* `basemap` - **deprecated** - The old single-id way to pick a map; see [Deprecated basemap ids](#deprecated-basemap-ids). Ignored when `provider` or `style` is given.
* `attribution` - default: the map's own - What to draw in the attribution corner:
  * `text` - only the map's credit text (for example "© OpenStreetMap contributors"), on a light strip at the edge.
  * `osm`, `esri`, `mapbox`, `mapbox-small`, or a full URL to a PNG image - that logo, together with the map's credit text.
  * `none` - nothing. Only use this if you show the attribution elsewhere, for example under the image: the providers' terms require it. [`/api/v1/attribution`](#query-api) gives the credit to show.
  * Any other value counts as not given.
* `attribution_position` - default "bottomright" - The corner for the attribution: `bottomright`, `bottomleft`, `topright` or `topleft`. Anything else gets HTTP 400.
* `latitude` - Latitude to center the map at, -85.0511 to 85.0511 (the limits of Web Mercator maps). Not needed if specifying one or more markers or paths, a `bbox` or `visible` points. Must be given together with `longitude`.
* `longitude` - Longitude to center the map at, -180 to 180. Must be given together with `latitude`.
* `center` - optional - Shorthand for `latitude` and `longitude`: `center=45.5165,-122.6764` (**latitude first**). Use one or the other, not both.
* `bbox` - optional - An area to show: `bbox=minLng,minLat,maxLng,maxLat` (**longitude first**, as in GeoJSON and Mapbox), e.g. `bbox=-122.70,45.50,-122.65,45.53`. Without a `zoom`, the map zooms to fit it, together with any markers and paths.
* `visible` - optional - Points to keep in view without drawing anything there: `visible=45.50,-122.70|45.53,-122.65` (`lat,lng` pairs separated by `|`, **latitude first**, as in Google's Static Maps API).
* `padding` - default 20 - Space in pixels kept between the fitted content and the edge of the image when no `zoom` is given: one number for all sides, or 2-4 comma-separated values in CSS order (`20,40` for top/bottom 20 and left/right 40; `top,right,bottom,left`), each 0-1000. Room for marker icons and path widths is added automatically. The install's default is `defaults.padding`.
* `location` - **deprecated, ignored** - Geocoding addresses was removed (#16): convert addresses to coordinates in your client and pass `latitude` / `longitude`. A request with `location` gets `Deprecation` and `Link` headers; with nothing else to place the map, it gets HTTP 400.
* `marker[]` - Specify one or more markers to overlay on the map. Parameters are specified as: `key:value;`. See below for the full list of parameters. 
* `path[]` - Specify one or more paths to draw on the map. See below for the full list of parameters to draw a path.
* `polyline` - Alternative to path: one path as a Google encoded polyline (latitude, longitude order), for shorter URLs. See [Polylines](#polylines).
* `format` - default "png" - `png`, `png8` (a 256-colour PNG: much smaller, good for most maps), `jpg` (`jpeg` also works) or `webp` (smaller still; when the server's PHP has no WebP support, the map is sent as PNG with an `X-Map-Format` header, and `/api/v1/` lists the formats available). Anything else gets HTTP 400.
* `quality` - default 75 - JPEG and WebP quality, a whole number from 1 to 100.
* `bezier` - Specify a bezier curve to your path, an angle in degrees between -89 and 89. 25 will give you a nicely curved line. [More Info](https://aaronparecki.com/2017/01/02/6/day-13-curved-map-lines)

Markers and paths that can't be drawn (coordinates that aren't numbers in range, an unknown icon, no points) are left out, with an `X-Marker-<n>` or `X-Path-<n>` header saying why; an install with `errors.strict` answers HTTP 400 instead. A map draws at most 100 markers and 10,000 path points by default (`limits.max_markers`, `limits.max_points`); more are left out, with an `X-Map-Limit` header.

### Responses

| Status | When | Body |
|---|---|---|
| `200` | The map. `Content-Type` is `image/png`, `image/jpeg` or `image/webp`. | The image |
| `200` `text/html` | A request with no parameters at all: the install's home page. | HTML |
| `400` | A parameter is invalid (a number out of range, unknown `format` or `attribution_position`, only one of `latitude`/`longitude`), the request doesn't place the map (no coordinates, markers or paths), or, with `errors.strict`, a marker or path can't be drawn. | One line of text saying why |
| `403` | A key or signature problem: no valid key on a [restricted](#access-and-client-keys) server, or an invalid or expired signature. | One line of text saying why |
| `500` | The install's configuration is invalid (details are in the server log). | One line of text |

Error responses also send the reason as an `X-Map-Error` header (handy for an `<img>` that failed to load: see the browser's network panel) and `Cache-Control: no-store`.

Headers on a map that was drawn, but not quite as requested:

| Header | Meaning |
|---|---|
| `X-Map-Fallback` | The default map was drawn instead of the requested provider or style: `removed` or `unavailable`, with `requested=` and `drawn=` (see [Providers and styles](#providers-and-styles)). |
| `X-Map-Size` | `width` / `height` were over the install's limits and the map was drawn smaller. |
| `X-Map-Limit` | Markers or paths over the install's limits were left out. |
| `X-Map-Format` | The requested `format` isn't available on this server; the map was sent as PNG. |
| `X-Map-Attribution` | The `attribution` image URL wasn't used (it needs a valid key). |
| `X-Marker-<n>`, `X-Path-<n>` | Marker or path number n (counting from 1) was left out, and why. |
| `Deprecation`, `Link` | The request used something deprecated (see [Deprecated](#deprecated)). |

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

Shipped providers (the first style is the provider's default). Max zoom is the highest level with real tiles, checked by sampling; requests above it are drawn at that level. An install can lower every maximum with `zoom.max_limit`:

| `provider` | Styles | Max zoom |
|---|---|---|
| `osm` | `streets`: [OpenStreetMap](https://www.openstreetmap.org/) | 19 |
| `esri` | `streets`: [World Street Map](https://www.arcgis.com/home/webmap/viewer.html?webmap=7990d7ea55204450b8110d57e20c99ab) · `satellite`: [World Imagery](https://www.arcgis.com/home/webmap/viewer.html?webmap=d802f08316e84c6592ef681c50178f17&center=-71.055499,42.364247&level=15) · `hybrid` · `topo`: [World Topographic Map](https://www.arcgis.com/home/webmap/viewer.html?webmap=a72b0766aea04b48bf7a0e8c27ccc007) · `light`: Light Gray Canvas · `light-nolabels`: [Light Gray Canvas](https://www.arcgis.com/home/webmap/viewer.html?webmap=8b3d38c0819547faa83f7b7aca80bd76) without labels · `oceans`: [World Ocean Base](https://server.arcgisonline.com/ArcGIS/rest/services/Ocean/World_Ocean_Base/MapServer) with labels, up to zoom 13 · `natgeo`: [National Geographic](https://www.arcgis.com/home/webmap/viewer.html?webmap=d94dcdbe78e141c2b2d3a91d5ca8b9c9) | 19; `light`, `light-nolabels`, `natgeo`: 16 (NatGeo only 12 outside the US); `oceans`: 13 |
| `opentopomap` | `topo`: [OpenTopoMap](https://www.opentopomap.org/) | 17 |
| `carto` | `light`: Positron · `dark`: Dark Matter · `streets`: Voyager. [CARTO](https://carto.com/basemaps/) **needs an API key**: these styles are unavailable until the install sets `'providers' => ['carto' => ['key' => '...']]`. Keys are [free](https://carto.com/basemaps/apikey) (5 million requests a month non-commercial, 1 million commercial). | 20 |
| `custom` | Draws `tileurl`; requires a valid `key`. | `zoom.max` (18) |

Every shipped map draws the credit its provider requires on the image, kept short: "© OpenStreetMap contributors" on OpenStreetMap, the OpenTopoMap and CARTO credits on theirs, and on Esri maps the "Powered by Esri" logo with the main data sources. Esri's terms allow its full source list near the map rather than on it, so the complete credit, as HTML with links, comes from [`/api/v1/attribution`](#query-api): call it with the same parameters as the image and show the `html` next to it. Each shipped provider sets its own attribution, so changing `defaults.attribution` only affects providers you add.

Installs can switch providers or styles off, add their own, and set API keys; see [Configuration](#configuration).

If the requested map can't be drawn, the default map is drawn instead and the response has an `X-Map-Fallback` header:

```
X-Map-Fallback: removed; requested=stamen-toner; drawn=osm/streets
X-Map-Fallback: unavailable; requested=esri/nope; drawn=osm/streets
```

`removed` means the provider, style or basemap id was taken out because it stopped working (see [Removed providers and styles](#removed-providers-and-styles)); the response then also has `Link: <...#removed-providers-and-styles>; rel="help"`. `unavailable` covers everything else: unknown, switched off on this install, needs a provider API key the install hasn't set, needs a client key the request doesn't have (or isn't allowed with), or `provider=custom` without a key and `tileurl`. `requested` echoes the provider/style or basemap id (or `?` if it isn't a plain id), and `drawn` names the map drawn instead.

### Removed providers and styles

Maps whose tiles no longer exist are removed rather than deprecated, since they can't keep working. Requests for them get the default map with `X-Map-Fallback: removed`.

| Removed | Why | Removed in | Instead |
|---|---|---|---|
| `basemap=stamen-toner`, `stamen-toner-background`, `stamen-toner-lite`, `stamen-terrain`, `stamen-terrain-background`, `stamen-watercolor` | Stamen's tile hosting shut down in 2023 | 1.0.0 | `carto` `light` / `dark` for a similar look |

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

## Query API

A read-only JSON API tells clients what this install supports, so they don't have to hard-code the list of maps and icons. It needs no authentication, allows any origin (`Access-Control-Allow-Origin: *`) and can be cached for 5 minutes. It never returns tile URLs or API keys.

| Endpoint | Returns |
|---|---|
| `GET /api/v1/` | Version, access mode (and whether the request's key is valid), defaults (provider, style, size, format, attribution), size limits, zoom settings, formats, attribution values, whether custom tile URLs are available to this request, and links to the other endpoints |
| `GET /api/v1/providers` | Every provider and style that can be drawn: id, name, `minzoom` / `maxzoom` (after `zoom.max_limit`), default attribution, on-image credit (`attribution_text`) and full credit (`attribution_html`), whether it has a labels layer; the default map; deprecated `basemap` ids with what they map to; removed ids with the reason |
| `GET /api/v1/attribution` | The credit for one map, chosen by the same `provider` / `style` / `basemap` / `key` / `attribution` parameters as the image (so you can pass the image's query string): `provider`, `style`, `fallback` (the `X-Map-Fallback` value if the default map would be drawn instead), `attribution` (the logo setting), `text` (what's drawn on the image), `html` (the full credit with links, for your page) and `plain` (the same without markup) |
| `GET /api/v1/icons` | Every built-in marker icon: name, width and height in pixels, anchor (`bottom-center`, or `center` for `dot-*` icons) and the URL of its image |

Providers or styles that are switched off, need a provider API key the install hasn't set, or need a client key are left out. Maps that need a client key are listed (with `needs_key: true`) when the request includes a valid `key` the client may use them with, and such responses aren't cached (`Cache-Control: private, no-store`). Unknown endpoints get `404`, methods other than `GET`, `HEAD` and `OPTIONS` get `405`; both with a JSON `error`. The `v1` in the path changes only if the response format changes incompatibly.

To credit a map on your page, request the attribution with the image's query string:

```
GET /api/v1/attribution?provider=esri&style=streets&latitude=45.5&longitude=-122.6&zoom=14
```

```json
{
    "provider": "esri",
    "style": "streets",
    "fallback": null,
    "attribution": "esri",
    "text": "Esri, HERE, Garmin, USGS, © OpenStreetMap contributors and others",
    "html": "Powered by <a href=\"https://www.esri.com\">Esri</a> | Sources: Esri, HERE, Garmin, USGS, …",
    "plain": "Powered by Esri | Sources: Esri, HERE, Garmin, USGS, …"
}
```

Example, `GET /api/v1/providers` (shortened):

```json
{
    "default": { "provider": "osm", "style": "streets" },
    "providers": [
        {
            "id": "esri",
            "name": "Esri",
            "default_style": "streets",
            "styles": [
                { "id": "streets", "name": "World Street Map", "minzoom": 2, "maxzoom": 19,
                  "attribution": "esri", "attribution_text": "Sources: Esri, HERE, Garmin, USGS, …",
                  "labels_layer": false, "requires_auth": false },
                { "id": "hybrid", "name": "World Imagery with labels", "minzoom": 2, "maxzoom": 19,
                  "attribution": "esri", "attribution_text": "Source: Esri, Vantor, Earthstar Geographics, …",
                  "labels_layer": true, "requires_auth": false }
            ]
        }
    ],
    "deprecated_basemaps": { "satellite": { "provider": "esri", "style": "satellite" } },
    "removed": [ { "id": "stamen", "reason": "Stamen tile hosting shut down in 2023 (removed in 1.0.0)" } ]
}
```

The API runs from the same `index.php` as the maps; the nginx configuration under [How to install](#how-to-install) already sends `/api/` requests there.

## Markers

* `lat` - Latitude of the pin.
* `lng` - Longitude of the pin.
* (`location`, an address to geocode, is no longer supported. A marker with only a `location` is skipped, with an `X-Marker-<n>` header saying so.)
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


## Caching

The same request always draws the same map until its tiles change, so map images can be cached:

- `Cache-Control: public, max-age=604800` (7 days, the `cache.max_age` setting) on map images, so browsers, CDNs and proxies such as nginx (see [How to install](#cache-rendered-maps-in-nginx-optional)) can keep them.
- `private` instead of `public` for maps drawn for a request with a valid key, and for providers whose terms don't allow caching proxies (`cache => 'off'` in their settings): browsers may keep those, shared caches shouldn't.
- A shorter lifetime (`cache.fallback_max_age`, 5 minutes) when the default map was drawn instead of the requested one (`X-Map-Fallback`), since the requested map may become available later.
- `Cache-Control: no-store` on errors.
- An `ETag` on every map image; a request with a matching `If-None-Match` gets `304 Not Modified` without the image.

The service sends headers that follow the providers' terms; what an install's own caches or CDN do with them is up to the install.

### Tile cache

Downloaded tiles are kept on disk (on by default, in `data/cache/tiles/`), so maps of the same area share them and repeat maps don't download tiles again. This is what OpenStreetMap's tile policy asks of its users.

- Tiles are kept as long as the provider's own `Cache-Control` / `Expires` headers say, or 7 days when it sends none (`cache.tiles.default_ttl`). Expired tiles are checked again with a conditional request (`If-None-Match` / `If-Modified-Since`), so an unchanged tile costs the provider almost nothing.
- If a provider fails, a cached tile up to 7 days past expiry (`cache.tiles.stale_if_error`, or the provider's own `stale-if-error`) is drawn instead of a gray square.
- Providers whose terms forbid caching (`cache => 'off'`) are never cached. API keys are hashed into the cache paths, never written in them.
- The web server's PHP user must be able to write the directory. If it can't, maps are drawn without the cache and the reason is logged. Set `cache.tiles.dir` to keep the cache elsewhere (for example `/var/cache/static-maps/tiles`), or `'enabled' => false` to switch it off.

**Keeping it to size.** The cache only grows by itself; `bin/prune-cache.php` removes tiles that can't be used any more and, if the cache is larger than `cache.tiles.max_size` (1 GB by default), the oldest tiles until it is under 90% of the limit. Run it regularly as the same user as PHP (so it can delete the files), for example once an hour:

With **cron** (`sudo crontab -u www-data -e`):

```
15 * * * * php /var/www/static-maps-api/bin/prune-cache.php --quiet
```

Or with a **systemd timer**: `/etc/systemd/system/static-maps-prune.service`

```
[Unit]
Description=Prune the Static Maps API tile cache

[Service]
Type=oneshot
User=www-data
ExecStart=/usr/bin/php /var/www/static-maps-api/bin/prune-cache.php
```

and `/etc/systemd/system/static-maps-prune.timer`

```
[Unit]
Description=Prune the Static Maps API tile cache hourly

[Timer]
OnCalendar=hourly
RandomizedDelaySec=10m
Persistent=true

[Install]
WantedBy=timers.target
```

then `sudo systemctl daemon-reload && sudo systemctl enable --now static-maps-prune.timer`. Its output goes to the journal (`journalctl -u static-maps-prune`). If your install file isn't `data/config.php`, set `STATIC_MAPS_CONFIG` for the script too (`Environment=STATIC_MAPS_CONFIG=...` in the service). `--dry-run` shows what would be removed without removing anything.

## Access and client keys

Callers that you trust get a **client key**, which they send as the `key` parameter. What needs one depends on `access.mode`:

| `access.mode` | Without a key | With a valid key |
|---|---|---|
| `open` (default) | Free maps. Maps whose tiles use a provider API key your install holds, providers or styles with `requires_auth`, and the URL features (marker icon URLs, attribution image URLs, `provider=custom` with `tileurl`) are left out: the default map is drawn with `X-Map-Fallback: unavailable`, or the marker / attribution is skipped with an `X-Marker-<n>` / `X-Map-Attribution` header | Everything the client is allowed |
| `restricted` | Every map request gets `403 A valid key is required…`. The home page and the query API stay open | Everything the client is allowed |

An install that only offers free maps issues no keys. The server's provider keys never leave it; clients only ever send their own key.

**Issuing keys.** Use `bin/clients.php` (run it as the PHP user, like the prune script). It makes long random keys, shows each one once, and stores only its SHA-256 hash in `data/clients.json` (`access.clients_file`):

```
php bin/clients.php add simple-location                  # prints the new key
php bin/clients.php add blog --providers=carto --no-external-urls
php bin/clients.php list
php bin/clients.php set blog --providers=all --external-urls=yes
php bin/clients.php set blog --disable                   # or --enable
php bin/clients.php rotate simple-location               # new key, old one stops working
php bin/clients.php revoke blog
```

`--providers` limits which providers that need a key the client may use (the default map is always allowed); `--no-external-urls` stops it making the server download URLs. Clients can also be written by hand in the `clients` setting (see `config/defaults.php`).

**From the old token file.** `data/apikeys.txt` (`token_file`) and the `token` parameter still work but are deprecated: run `php bin/clients.php import` to turn each line into a client (`token-1`, …) whose key is the old token, then delete the file and send `key` instead of `token`. Until then each request logs a deprecation message, and requests that send `token` get `Deprecation` headers.

A plain key appears in the URL of every image that uses it, so a key embedded in a public page is visible to anyone who looks. For maps published in pages, use **signed URLs** instead (below). Give each site its own client either way, so it can be revoked on its own. Keys are compared exactly and in constant time.

### Signed URLs

Every client also gets a **signing secret** (shown by `bin/clients.php add`). Instead of the key, a signed URL carries the client's id as `key` and, as its **last** parameter, a signature of everything before it:

```
signature = base64url( HMAC-SHA256( secret, <path and query, exactly as sent, up to "&signature"> ) )
```

base64url means `+` → `-`, `/` → `_` and no `=` padding. The secret never appears in the URL, and changing any parameter breaks the signature, so a signed URL can be published in an `<img>` tag. Signed map images are also cacheable like free maps, whereas maps for plain keys are `private`. In PHP:

```php
function sign_map_url(string $base, string $pathAndQuery, string $secret): string
{
    $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $pathAndQuery, $secret, true)), '+/', '-_'), '=');
    return $base . $pathAndQuery . '&signature=' . $signature;
}

echo sign_map_url(
    'https://maps.example.com',
    '/?latitude=45.5165&longitude=-122.6764&zoom=14&provider=carto&key=my-blog',
    'the-client-secret'
);
```

- Sign the path and query string exactly as you send them, including their URL-encoding; the host isn't signed, so the same URL works behind a proxy or CDN.
- Add `expires=<Unix time>` before signing for a URL that stops working after that time. Without it, signed URLs don't expire, because published posts keep their map URLs indefinitely.
- Signed URLs must be `GET` (or `HEAD`) requests.
- An invalid or expired signature gets `403` with the reason, rather than the free map.
- `access.credentials` decides what is accepted: `key` (plain keys only), `signed` (signed URLs only) or `either` (default). `php bin/clients.php add <id> --must-sign` (or `set <id> --must-sign=yes`) makes one client signed-only, so its plain key is refused. `rotate <id> --secret` replaces just the secret.

Every download from another server, for tiles too, is limited by the `remote` setting: http and https only, at most 3 redirects, 10 seconds and 5 MB by default.

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
        // Add a provider that needs an API key. {key} is replaced with 'key',
        // in the tile URLs or in request headers. Check its terms first (see below).
        'example' => [
            'name'    => 'Example Tiles',
            'key'     => 'your-api-key',
            'headers' => ['Authorization' => 'Bearer {key}'],
            'styles'  => [
                'streets' => [
                    'name'   => 'Streets',
                    'layers' => ['https://tiles.example.com/streets/{z}/{x}/{y}.png'],
                ],
            ],
        ],
    ],
];
```

`limits.max_width` / `limits.max_height` (default 1280 each) are the largest image a request can get. Larger requests are drawn at the limit, with an `X-Map-Size` header. Bigger images download more tiles from the tile providers. `limits.max_markers` (default 100) and `limits.max_points` (default 10,000 points in all paths together) cap the overlays; more are left out, with an `X-Map-Limit` header.

`errors.strict` (default `false`) decides what happens to a marker or path that can't be drawn: left out with an `X-Marker-<n>` / `X-Path-<n>` header, or, when `true`, HTTP 400 with the reason.

`zoom.min` / `zoom.max` (default 2 and 18) is the zoom range for providers and styles that don't set their own `minzoom` / `maxzoom`. `zoom.max_limit` (not set by default) caps every map, whatever its own maximum, for example `'zoom' => ['max_limit' => 17]` to limit how many tiles high-zoom maps download.

The `removed` setting lists removed provider, style and basemap ids for the `X-Map-Fallback` notice; an install can add its own when it takes a map out of service.

Each provider has a `name`, an optional `default_style`, and `styles`. Each style has a `name` and a list of one or two tile URL `layers` (a base map and an optional overlay drawn on top). A provider can set a default `attribution` (`text`, a logo name or `none`), its `attribution_text` (the short credit its terms require on the image), its `attribution_html` (the full credit with links, for `/api/v1/attribution`), `minzoom` / `maxzoom`, `requires_auth` (only usable with a client key), `enabled`, an API `key` and request `headers` (sent with every tile request, for providers that take the key in a header such as `Authorization`); its styles inherit these and can override any of them. A style whose tile URLs or headers contain `{key}` is unavailable until a key is set, and then needs a client key, so a provider that needs a key (such as CARTO) can ship switched on and starts working once the install adds one. See `config/defaults.php` for details.

Tile requests that send `headers` don't follow redirects, so a key in a header is never sent to another server. The query API never shows provider keys or headers.

### Provider terms

This software is Apache-2.0 licensed, but **the maps it draws come from tile providers, each with its own terms, and every install must follow them**, including any limits on commercial use. The software makes a reasonable attempt by default:
- it sends a descriptive User-Agent;
- it follows providers' cache headers, and never caches tiles for providers whose terms forbid it (`cache => 'off'`);
- it draws the attribution each provider requires.

What an install does beyond that is its own responsibility: which providers it adds, how much it uses them, and where its images end up. Before using or adding a provider, read its terms:

| Provider | What its terms say (checked October 2026) | Terms |
|---|---|---|
| OpenStreetMap | No heavy use or bulk downloading. A proxy must send a clear, contactable User-Agent. Commercial services are warned that access may be withdrawn at any time. | [Tile usage policy](https://operations.osmfoundation.org/policies/tiles/) |
| Esri / ArcGIS | "Powered by Esri" and the data sources must be credited. | [Attribution requirements](https://developers.arcgis.com/documentation/esri-and-data-attribution/), [terms of use](https://www.esri.com/en-us/legal/terms/full-master-agreement) |
| CARTO | Needs a free key. Non-commercial use up to 5 million requests a month; commercial use up to 1 million, then a paid plan. | [Basemaps](https://carto.com/basemaps), [legal](https://carto.com/legal) |
| OpenTopoMap | CC-BY-SA, so commercial use is allowed with credit. No mass downloads. | [About](https://opentopomap.org/about) |

Some providers have been looked at and left out because their terms don't allow this kind of use. For example, Stadia Maps' [terms](https://stadiamaps.com/terms-of-service) forbid proxying its tiles, and its free tier is for non-commercial use only.

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

`polyline` draws one path given as an encoded polyline, which makes long paths much shorter than `path[]`. It uses [Google's Encoded Polyline Algorithm](https://developers.google.com/maps/documentation/utilities/polylinealgorithm): points in **latitude, longitude** order, precision 5 (1e-5 degrees). That is what Google Maps, Mapbox, OSRM, Valhalla, GraphHopper and polyline libraries such as `@mapbox/polyline` produce, so their output can be passed straight in. (Note that `path[]` writes each point the other way round, as `[lng,lat]`.)

The value starts with `enc:` and the encoded string, optionally followed by `color` and `weight` like a path. `polyline` is ignored when the request also has `path[]`. A polyline with points out of range is left out with an `X-Path-1` header.

### Examples

Google's example line from California into Oregon, with the default color and weight:

```
polyline=enc:_p~iF~ps|U_ulLnnqC_mqNvxq`@
```

The same line in blue, 6 pixels wide:

```
polyline=enc:_p~iF~ps|U_ulLnnqC_mqNvxq`@;weight:6;color:0033ff
```

Characters such as `|` and `` ` `` must be URL-encoded in a query string (`%7C`, `%60`); most HTTP libraries do this for you.

## Examples

### Simple map centered at a location

```
http://static-maps.pdx.esri.com/?provider=esri&style=light&width=400&height=240&zoom=14&latitude=45.5165&longitude=-122.6764
```

<img src="http://static-maps.pdx.esri.com/?provider=esri&style=light&width=400&height=240&zoom=14&latitude=45.5165&longitude=-122.6764">

### Map centered on a marker

```
http://static-maps.pdx.esri.com/?marker[]=lat:45.5165;lng:-122.6764;icon:small-blue-cutout&provider=esri&style=light&width=400&height=240&zoom=14
```

<img src="http://static-maps.pdx.esri.com/?marker[]=lat:45.5165;lng:-122.6764;icon:small-blue-cutout&provider=esri&style=light&width=400&height=240&zoom=14">

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

Make sure PHP logs errors instead of printing them: a warning printed into the response would corrupt the image. In the pool file (or `php.ini`) set:

```
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
```

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

### Cache rendered maps in nginx (optional)

Map images are sent with `Cache-Control` and `ETag` headers (see [Caching](#caching)), so nginx can keep them and answer repeat requests without running PHP or downloading tiles. Add a cache zone in the `http` block and turn it on for PHP:

```
    # In the http block: up to 1 GB of cached maps, dropped after 7 days unused
    fastcgi_cache_path /var/cache/nginx/static-maps levels=1:2 keys_zone=static_maps:10m
                       max_size=1g inactive=7d use_temp_path=off;

    # In the location ~ \.php$ block above:
    fastcgi_cache           static_maps;
    fastcgi_cache_key       "$request_method$host$request_uri";
    fastcgi_cache_lock      on;
    fastcgi_cache_use_stale error timeout updating;
    add_header              X-Cache-Status $upstream_cache_status;
```

nginx follows the `Cache-Control` the service sends: maps are kept for `cache.max_age`, maps drawn for a request with a key (and maps from providers that don't allow shared caching) are `private` and not stored, and errors (`no-store`) are never stored.

### Clone the source

```
sudo mkdir -p /var/www/static-maps-api
sudo chown -R ubuntu: /var/www
cd /var/www/static-maps-api
git clone git@github.com:dshanske/Static-Maps-API-PHP.git .
```

Let PHP write the tile cache, and set up its cleanup (see [Tile cache](#tile-cache)):

```
sudo mkdir -p data/cache
sudo chown www-data: data/cache
```

## Security

See [SECURITY.md](SECURITY.md) for how to report a vulnerability privately.

## Versioning and deprecation

Releases follow [Semantic Versioning](https://semver.org/). The version numbers cover what users and installs depend on:

- the request parameters and what they do,
- the response: image formats, status codes and headers,
- the install settings in `data/config.php`,
- the query API (once it exists, #25), which also has its own version in its URL (`/api/v1/`). That number only changes when the query API itself breaks.

A **major** release (2.0.0) removes or changes any of these. A **minor** release (1.1.0) adds parameters, providers, styles or settings, or deprecates something; the old way keeps working. A **patch** release (1.0.1) fixes bugs without changing documented behaviour.

The first release will be **1.0.0**, once the first phase of the rewrite is complete (the [1.0 milestone](https://github.com/dshanske/Static-Maps-API-PHP/milestone/1)). There are no releases before it; `main` may change at any time until then. Every change is listed in [CHANGELOG.md](CHANGELOG.md).

**Maps that stop working** are the exception: a provider or style whose tiles no longer exist is removed in the next release (not deprecated, since it can't keep working), and requests for it get the default map with a notice; see [Removed providers and styles](#removed-providers-and-styles). Where the provider offers a working replacement, the style is repaired instead and keeps its id.

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
| The `location` parameter and `location:` in markers (geocoding, now ignored) | `latitude` / `longitude`, and `lat` / `lng` in markers (#16) | 1.0.0 | 2.0.0 |
| The `token` parameter, and the token file `data/apikeys.txt` (`token_file`) | The `key` parameter, and clients from `bin/clients.php` (`php bin/clients.php import` moves the file's tokens over) (#28) | 1.0.0 | 2.0.0 |

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

Both must pass cleanly: PHPStan runs at level 6 with no baseline of ignored errors, and PHP_CodeSniffer reports no warnings.

**Documentation comments are required** on every PHP file, class, function, method and property, and `composer cs` (and CI) fails without them. Describe what each parameter means, including units (pixels, degrees) and the coordinate order (`lat, lng` or `lng, lat`), and give `@return` and `@throws`. Write types the way PHPStan reads them, for example `int`, `list<string>` or `array{x: float, y: float}`.

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

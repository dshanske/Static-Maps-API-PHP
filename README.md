Static Maps API
===============

## Parameters

* `zoom` - default 14 - Set the zoom level for the map
* `width` - default 300 - Width in pixels of the final image
* `height` - default 300 - Height in pixels of the final image
* `basemap` - default "streets" - Select the basemap
  * `streets` - Default [Esri street basemap](http://www.arcgis.com/home/webmap/viewer.html?webmap=7990d7ea55204450b8110d57e20c99ab)
  * `satellite` - Esri's [satellite basemap](http://www.arcgis.com/home/webmap/viewer.html?webmap=d802f08316e84c6592ef681c50178f17&center=-71.055499,42.364247&level=15)
  * `hybrid` - Satellite basemap with labels
  * `topo` - Esri [topographic map](http://www.arcgis.com/home/webmap/viewer.html?webmap=a72b0766aea04b48bf7a0e8c27ccc007)
  * `gray` - Esri gray canvas with labels
  * `gray-background` - Esri [gray canvas](http://www.arcgis.com/home/webmap/viewer.html?webmap=8b3d38c0819547faa83f7b7aca80bd76) without labels
  * `oceans` - Esri [ocean basemap](http://www.arcgis.com/home/webmap/viewer.html?webmap=5ae9e138a17842688b0b79283a4353f6&center=-122.255816,36.573652&level=8)
  * `national-geographic` - [National Geographic basemap](http://www.arcgis.com/home/webmap/viewer.html?webmap=d94dcdbe78e141c2b2d3a91d5ca8b9c9)
  * `osm` - [Open Street Map](http://www.openstreetmap.org/)
  * `stamen-toner` - [Stamen Toner](http://maps.stamen.com/toner/) black and white map with labels
  * `stamen-toner-background` - [Stamen Toner](http://maps.stamen.com/toner-background/) map without labels
  * `stamen-toner-lite` - [Stamen Toner Light](http://maps.stamen.com/toner-lite/) with labels
  * `stamen-terrain` - [Stamen Terrain](http://maps.stamen.com/terrain/) with labels
  * `stamen-terrain-background` - [Stamen Terrain](http://maps.stamen.com/terrain-background/) without labels
  * `stamen-watercolor` - [Stamen Watercolor](http://maps.stamen.com/watercolor/)
* `attribution` - default "esri" - "esri" or "none" - If you add attribution on the image in some other way, you can set this to "none" to hide the Esri logo
* `latitude` - optional - Latitude to center the map at. Not needed if using the location parameter, or if specifying one or more markers.
* `longitude` - optional - Longitude to center the map at.
* `location` - optional - Free-form text that will be geocoded to center the map. Not needed if specifying a location with the latitude and longitude parameters, or if a marker is specified.
* `marker[]` - Specify one or more markers to overlay on the map. Parameters are specified as: `key:value;`
  * `location` - Free-form text that will be geocoded to place the pin
  * `lat` - If a `location` is not provided, you can specify the location with the `lat` and `lng` parameters.
  * `lng` - See above
  * `icon` - Icon to use for the marker. Must choose one of the icons provided in this library. If an invalid icon is specified, the marker will not be rendered.

## Examples

### Simple map centered at a location

```
http://dbx1q8fzf8vx3.cloudfront.net/img.php?basemap=gray&width=400&height=240&zoom=14&latitude=45.5165&longitude=-122.6764
```

### Map with a marker centered at an address

```
http://dbx1q8fzf8vx3.cloudfront.net/img.php?marker[]=location:920%20SW%203rd%20Ave,%20Portland,%20OR;icon:small-blue-cutout&basemap=gray&width=400&height=240&zoom=14
```





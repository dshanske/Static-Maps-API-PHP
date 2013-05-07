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

## Markers

* `dot-large-blue.png` ![dot-large-blue.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/dot-large-blue.png)
* `dot-large-gray.png` ![dot-large-gray.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/dot-large-gray.png)
* `dot-large-green.png` ![dot-large-green.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/dot-large-green.png)
* `dot-large-orange.png` ![dot-large-orange.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/dot-large-orange.png)
* `dot-large-pink.png` ![dot-large-pink.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/dot-large-pink.png)
* `dot-large-purple.png` ![dot-large-purple.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/dot-large-purple.png)
* `dot-large-red.png` ![dot-large-red.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/dot-large-red.png)
* `dot-large-yellow.png` ![dot-large-yellow.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/dot-large-yellow.png)
* `dot-small-blue.png` ![dot-small-blue.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/dot-small-blue.png)
* `dot-small-gray.png` ![dot-small-gray.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/dot-small-gray.png)
* `dot-small-green.png` ![dot-small-green.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/dot-small-green.png)
* `dot-small-orange.png` ![dot-small-orange.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/dot-small-orange.png)
* `dot-small-pink.png` ![dot-small-pink.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/dot-small-pink.png)
* `dot-small-purple.png` ![dot-small-purple.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/dot-small-purple.png)
* `dot-small-red.png` ![dot-small-red.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/dot-small-red.png)
* `dot-small-yellow.png` ![dot-small-yellow.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/dot-small-yellow.png)
* `fb.png` ![fb.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/fb.png)
* `google.png` ![google.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/google.png)
* `large-blue-blank.png` ![large-blue-blank.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/large-blue-blank.png)
* `large-blue-cutout.png` ![large-blue-cutout.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/large-blue-cutout.png)
* `large-gray-blank.png` ![large-gray-blank.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/large-gray-blank.png)
* `large-gray-cutout.png` ![large-gray-cutout.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/large-gray-cutout.png)
* `large-gray-user.png` ![large-gray-user.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/large-gray-user.png)
* `large-green-blank.png` ![large-green-blank.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/large-green-blank.png)
* `large-green-cutout.png` ![large-green-cutout.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/large-green-cutout.png)
* `large-orange-blank.png` ![large-orange-blank.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/large-orange-blank.png)
* `large-orange-cutout.png` ![large-orange-cutout.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/large-orange-cutout.png)
* `large-pink-blank.png` ![large-pink-blank.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/large-pink-blank.png)
* `large-pink-cutout.png` ![large-pink-cutout.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/large-pink-cutout.png)
* `large-purple-blank.png` ![large-purple-blank.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/large-purple-blank.png)
* `large-purple-cutout.png` ![large-purple-cutout.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/large-purple-cutout.png)
* `large-red-blank.png` ![large-red-blank.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/large-red-blank.png)
* `large-red-cutout.png` ![large-red-cutout.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/large-red-cutout.png)
* `large-yellow-blank.png` ![large-yellow-blank.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/large-yellow-blank.png)
* `large-yellow-cutout.png` ![large-yellow-cutout.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/large-yellow-cutout.png)
* `large-yellow-message.png` ![large-yellow-message.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/large-yellow-message.png)
* `large-yellow-user.png` ![large-yellow-user.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/large-yellow-user.png)
* `powered-by-esri.png` ![powered-by-esri.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/powered-by-esri.png)
* `small-blue-blank.png` ![small-blue-blank.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/small-blue-blank.png)
* `small-blue-cutout.png` ![small-blue-cutout.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/small-blue-cutout.png)
* `small-gray-blank.png` ![small-gray-blank.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/small-gray-blank.png)
* `small-gray-cutout.png` ![small-gray-cutout.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/small-gray-cutout.png)
* `small-gray-message.png` ![small-gray-message.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/small-gray-message.png)
* `small-gray-user.png` ![small-gray-user.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/small-gray-user.png)
* `small-green-blank.png` ![small-green-blank.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/small-green-blank.png)
* `small-green-cutout.png` ![small-green-cutout.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/small-green-cutout.png)
* `small-green-user.png` ![small-green-user.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/small-green-user.png)
* `small-orange-blank.png` ![small-orange-blank.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/small-orange-blank.png)
* `small-orange-cutout.png` ![small-orange-cutout.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/small-orange-cutout.png)
* `small-pink-blank.png` ![small-pink-blank.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/small-pink-blank.png)
* `small-pink-cutout.png` ![small-pink-cutout.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/small-pink-cutout.png)
* `small-pink-user.png` ![small-pink-user.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/small-pink-user.png)
* `small-purple-blank.png` ![small-purple-blank.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/small-purple-blank.png)
* `small-purple-cutout.png` ![small-purple-cutout.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/small-purple-cutout.png)
* `small-red-blank.png` ![small-red-blank.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/small-red-blank.png)
* `small-red-cutout.png` ![small-red-cutout.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/small-red-cutout.png)
* `small-yellow-blank.png` ![small-yellow-blank.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/small-yellow-blank.png)
* `small-yellow-cutout.png` ![small-yellow-cutout.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/small-yellow-cutout.png)
* `small-yellow-user.png` ![small-yellow-user.png](https://devtopia/Portland-R-D-Center/static-maps-api/raw/master/images/small-yellow-user.png)


## Examples

### Simple map centered at a location

```
http://dbx1q8fzf8vx3.cloudfront.net/img.php?basemap=gray&width=400&height=240&zoom=14&latitude=45.5165&longitude=-122.6764
```

### Map with a marker centered at an address

```
http://dbx1q8fzf8vx3.cloudfront.net/img.php?marker[]=location:920%20SW%203rd%20Ave,%20Portland,%20OR;icon:small-blue-cutout&basemap=gray&width=400&height=240&zoom=14
```





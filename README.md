Static Maps API
===============

## Parameters

Parameters can be sent in either the query string or in the POST body.

* `zoom` - optional - Set the zoom level for the map. If not specified, a zoom level will be chosen that contains all markers on the map.
* `maxzoom` - optional - When a zoom level is chosen automatically, this sets an upper limit on the zoom level that will be chosen. Useful if you know your basemaps don't have imagery past a certain zoom level.
* `width` - default 300 - Width in pixels of the final image
* `height` - default 300 - Height in pixels of the final image
* `basemap` - default "streets" - Select the basemap
  * `streets` - Default [Esri street basemap](https://www.arcgis.com/home/webmap/viewer.html?webmap=7990d7ea55204450b8110d57e20c99ab)
  * `satellite` - Esri's [satellite basemap](https://www.arcgis.com/home/webmap/viewer.html?webmap=d802f08316e84c6592ef681c50178f17&center=-71.055499,42.364247&level=15)
  * `hybrid` - Satellite basemap with labels
  * `topo` - Esri [topographic map](https://www.arcgis.com/home/webmap/viewer.html?webmap=a72b0766aea04b48bf7a0e8c27ccc007)
  * `gray` - Esri gray canvas with labels
  * `gray-background` - Esri [gray canvas](https://www.arcgis.com/home/webmap/viewer.html?webmap=8b3d38c0819547faa83f7b7aca80bd76) without labels
  * `oceans` - Esri [ocean basemap](https://www.arcgis.com/home/webmap/viewer.html?webmap=5ae9e138a17842688b0b79283a4353f6&center=-122.255816,36.573652&level=8)
  * `national-geographic` - [National Geographic basemap](https://www.arcgis.com/home/webmap/viewer.html?webmap=d94dcdbe78e141c2b2d3a91d5ca8b9c9)
  * `osm` - [Open Street Map](https://www.openstreetmap.org/)
  * `otm` - [OpenTopoMap](https://www.opentopomap.org/)
  * `stamen-toner` - [Stamen Toner](http://maps.stamen.com/toner/) black and white map with labels
  * `stamen-toner-background` - [Stamen Toner](http://maps.stamen.com/toner-background/) map without labels
  * `stamen-toner-lite` - [Stamen Toner Light](http://maps.stamen.com/toner-lite/) with labels
  * `stamen-terrain` - [Stamen Terrain](http://maps.stamen.com/terrain/) with labels
  * `stamen-terrain-background` - [Stamen Terrain](http://maps.stamen.com/terrain-background/) without labels
  * `stamen-watercolor` - [Stamen Watercolor](http://maps.stamen.com/watercolor/)
  * `carto-light` -  [Carto](https://carto.com/location-data-services/basemaps/) Free usage for up to 75,000 mapviews per month, non-commercial services only. 
  * `carto-dark` -  [Carto](https://carto.com/location-data-services/basemaps/) Free usage for up to 75,000 mapviews per month, non-commercial services only.
  * `carto-voyager` - [Carto](https://carto.com/location-data-services/basemaps/) Free usage for up to 75,000 mapviews per month, non-commercial services only. 
  * `custom` - Pass through the tile URL using parameter `tileurl`
* `attribution` - default "osm" - "esri", "osm", "mapbox" or "none" or specify a full URL to a png image - If you add attribution on the image in some other way, you can set this to "none" to hide all logos.
* `latitude` - optional - Latitude to center the map at. Not needed if using the location parameter, or if specifying one or more markers.
* `longitude` - optional - Longitude to center the map at.
* `location` - optional - Free-form text that will be geocoded to center the map. Not needed if specifying a location with the latitude and longitude parameters, or if a marker is specified.
* `marker[]` - Specify one or more markers to overlay on the map. Parameters are specified as: `key:value;`. See below for the full list of parameters. 
* `path[]` - Specify one or more paths to draw on the map. See below for the full list of parameters to draw a path.
* `polyline` - Alternative to path, this allows for a path to be specified as an encoded polyline, allowing for shorter URLs.
* `bezier` - Specify a bezier curve to your path. 25 will give you a nicely curved line. [More Info](https://aaronparecki.com/2017/01/02/6/day-13-curved-map-lines)

## Markers

* `location` - Free-form text that will be geocoded to place the pin
* `lat` - If a `location` is not provided, you can specify the location with the `lat` and `lng` parameters.
* `lng` - See above
* `icon` - Icon to use for the marker. Must choose one of the icons provided in this library, or specify a full URL to a png image. If an invalid icon is specified, the marker will not be rendered.


### Built-In Marker Images

* ![dot-large-blue](images/dot-large-blue.png) `dot-large-blue`
* ![dot-large-gray](images/dot-large-gray.png) `dot-large-gray`
* ![dot-large-green](images/dot-large-green.png) `dot-large-green`
* ![dot-large-orange](images/dot-large-orange.png) `dot-large-orange`
* ![dot-large-pink](images/dot-large-pink.png) `dot-large-pink`
* ![dot-large-purple](images/dot-large-purple.png) `dot-large-purple`
* ![dot-large-red](images/dot-large-red.png) `dot-large-red`
* ![dot-large-yellow](images/dot-large-yellow.png) `dot-large-yellow`
* ![dot-small-blue](images/dot-small-blue.png) `dot-small-blue`
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
http://static-maps.pdx.esri.com/img.php?basemap=gray&width=400&height=240&zoom=14&latitude=45.5165&longitude=-122.6764
```

<img src="http://static-maps.pdx.esri.com/img.php?basemap=gray&width=400&height=240&zoom=14&latitude=45.5165&longitude=-122.6764">

### Map with a marker centered at an address

```
http://static-maps.pdx.esri.com/img.php?marker[]=location:920%20SW%203rd%20Ave,%20Portland,%20OR;icon:small-blue-cutout&basemap=gray&width=400&height=240&zoom=14
```

<img src="http://static-maps.pdx.esri.com/img.php?marker[]=location:920%20SW%203rd%20Ave,%20Portland,%20OR;icon:small-blue-cutout&basemap=gray&width=400&height=240&zoom=14">

### Map with an external marker image

```
http://static-maps.pdx.esri.com/img.php?basemap=gray&width=400&height=240&zoom=14&marker[]=lat:45.5165;lng:-122.6764;icon:http://aaronparecki.com/images/map-pin-green.png
```

<img src="http://static-maps.pdx.esri.com/img.php?basemap=gray&width=400&height=240&zoom=14&marker[]=lat:45.5165;lng:-122.6764;icon:http://aaronparecki.com/images/map-pin-green.png">


## How to install

How to install on a blank Ubuntu 14.10 image on Amazon

### Install base packages

```
sudo apt-get update
sudo apt-get install git build-essential make bison flex gcc patch autoconf locate libssl-dev curl cmake libjpeg-dev libpng-dev libgif-dev libfreetype6 libfreetype6-dev imagemagick libmagickwand-dev libyaml-dev lynx htop
```

### Install nginx

```
wget http://nginx.org/download/nginx-1.7.3.tar.gz
tar -xzf nginx-1.7.3.tar.gz
cd nginx-1.7.3/
./configure --with-http_stub_status_module
make && sudo make install
```

Start nginx when the machine boots

```
sudo sh -c 'cat << ''EOF'' >> /etc/rc.local
/usr/local/nginx/sbin/nginx
EOF'
```

### Install PHP

```
sudo apt-get install python-software-properties
sudo add-apt-repository ppa:ondrej/php5
sudo apt-get update
sudo apt-get install php5-fpm php5-cli php5-curl php5-memcache php5-dev php5-imagick
```

Edit `/etc/php5/fpm/pool.d/www.conf` and uncomment the line `listen.mode = 0660`.

Restart PHP `sudo service php5-fpm restart`

### Configure the virtual host for nginx

Add the below server block to /usr/local/nginx/conf/nginx.conf and remove the default server block.

```
    server {
        listen 80;
        location / {
           root /var/www/static-maps-api;

           location / {
               index index.php;
           }

           location ~ \.php$ {
               fastcgi_pass    unix:/var/run/php5-fpm.sock;
               fastcgi_index   index.php;
               fastcgi_split_path_info ^(.+\.php)(.*)$;
               include fastcgi_params;
               fastcgi_param   SCRIPT_FILENAME $document_root$fastcgi_script_name;
           }
        }
    }
```

Add `user www-data` at the top of `nginx.conf`.

### Clone the source

```
sudo mkdir -p /var/www/static-maps-api
sudo chown -R ubuntu: /var/www
cd /var/www/static-maps-api
git clone git@github.com:aaronpk/Static-Maps-API-PHP.git .
```


## License

```
Copyright 2013 Esri, Inc

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

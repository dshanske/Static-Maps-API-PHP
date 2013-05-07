<?php
include('include/WebMercator.php');
include('include/ArcGISGeocoder.php');

define('TILE_SIZE', 256);

// If any markers are specified, choose a default lat/lng as the center of all the markers

$bounds = array(
  'minLat' => 90,
  'maxLat' => -90,
  'minLng' => 180,
  'maxLng' => -180
);

$markers = array();
if($markersTemp=get('marker')) {
  if(!is_array($markersTemp))
    $markersTemp = array($markersTemp);

  // If no latitude is set, use the center of all the markers
  foreach($markersTemp as $m) {
    if(preg_match_all('/(?P<k>[a-z]+):(?P<v>[^;]+)/', $m, $matches)) {
      $properties = array();
      foreach($matches['k'] as $i=>$key) {
        $properties[$key] = $matches['v'][$i];
      }

      // Skip invalid marker definitions for now, maybe show an error later?
      if(array_key_exists('icon', $properties) && (
          (array_key_exists('lat', $properties) && array_key_exists('lng', $properties))
          || array_key_exists('location', $properties)
        )
      ) {
        $properties['iconFile'] = './images/' . $properties['icon'] . '.png';

        // Geocode the provided location and return lat/lng
        if(array_key_exists('location', $properties)) {
          $result = ArcGISGeocoder::geocode($properties['location']);
          if(!$result->success) {
            continue;
          }

          $properties['lat'] = $result->latitude;
          $properties['lng'] = $result->longitude;
        }

        if(file_exists($properties['iconFile'])) {
          $markers[] = $properties;
        }

        if($properties['lat'] < $bounds['minLat'])
          $bounds['minLat'] = $properties['lat'];

        if($properties['lat'] > $bounds['maxLat'])
          $bounds['maxLat'] = $properties['lat'];

        if($properties['lng'] < $bounds['minLng'])
          $bounds['minLng'] = $properties['lng'];

        if($properties['lng'] > $bounds['maxLng'])
          $bounds['maxLng'] = $properties['lng'];
      }
    }
  }
}

$defaultLatitude = $bounds['minLat'] + (($bounds['maxLat'] - $bounds['minLat']) / 2);
$defaultLongitude = $bounds['minLng'] + (($bounds['maxLng'] - $bounds['minLng']) / 2);


$latitude = get('latitude', $defaultLatitude);
$longitude = get('longitude', $defaultLongitude);
$zoom = get('zoom', 14);
$width = get('width', 300);
$height = get('height', 300);


$tileServices = array(
  'streets' => array(
    'http://services.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{Z}/{Y}/{X}'
  ),
  'satellite' => array(
    'http://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{Z}/{Y}/{X}'
  ),
  'hybrid' => array(
    'http://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{Z}/{Y}/{X}',
    'http://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{Z}/{Y}/{X}'
  ),
  'topo' => array(
    'http://server.arcgisonline.com/ArcGIS/rest/services/World_Topo_Map/MapServer/tile/{Z}/{Y}/{X}'
  ),
  'gray' => array(
    'http://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Base/MapServer/tile/{Z}/{Y}/{X}',
    'http://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Reference/MapServer/tile/{Z}/{Y}/{X}'
  ),
  'oceans' => array(
    'http://server.arcgisonline.com/ArcGIS/rest/services/Ocean_Basemap/MapServer/tile/{Z}/{Y}/{X}'
  ),
  'national-geographic' => array(
    'http://server.arcgisonline.com/ArcGIS/rest/services/NatGeo_World_Map/MapServer/tile/{Z}/{Y}/{X}'
  ),
  'osm' => array(
    'http://tile.openstreetmap.org/{Z}/{X}/{Y}.png'
  ),
  'stamen-toner' => array(
    'http://tile.stamen.com/toner/{Z}/{X}/{Y}.png'
  ),
  'stamen-toner-background' => array(
    'http://tile.stamen.com/toner-background/{Z}/{X}/{Y}.png'
  ),
  'stamen-toner-lite' => array(
    'http://tile.stamen.com/toner-lite/{Z}/{X}/{Y}.png'
  ),
  'stamen-terrain' => array(
    'http://tile.stamen.com/terrain/{Z}/{X}/{Y}.png'
  ),
  'stamen-terrain-background' => array(
    'http://tile.stamen.com/terrain-background/{Z}/{X}/{Y}.png'
  ),
  'stamen-watercolor' => array(
    'http://tile.stamen.com/watercolor/{Z}/{X}/{Y}.png'
  )
);

if(get('basemap')) {
  $tileURL = $tileServices[get('basemap')][0];
  if(array_key_exists(1, $tileServices[get('basemap')]))
    $overlayURL = $tileServices[get('basemap')][1];
  else
    $overlayURL = 0;
} else {
  $tileURL = $tileServices['streets'][0];
  $overlayURL = false;
}

function urlForTile($x, $y, $z, $tileURL) {
  return str_replace(array(
    '{X}', '{Y}', '{Z}'
  ), array(
    $x, $y, $z
  ), $tileURL);
}


$webmercator = new WebMercator();


$im = imagecreatetruecolor($width, $height);


// Find the pixel coordinate of the center of the map
$center = $webmercator->latLngToPixels($latitude, $longitude, $zoom);
// echo '<br />';

$tilePos = $webmercator->pixelsToTile($center['x'], $center['y']);
// print_r($tilePos);
// echo '<br />';

$pos = $webmercator->positionInTile($center['x'], $center['y']);
// print_r($pos);
// echo '<br />';

// For the given number of pixels, determine how many tiles are needed in each direction
$neTile = $webmercator->pixelsToTile($center['x'] + $width/2, $center['y'] + $height/2);
// print_r($neTile);
// echo '<br />';

$swTile = $webmercator->pixelsToTile($center['x'] - $width/2, $center['y'] - $height/2);
// print_r($swTile);
// echo '<br />';

$leftEdge = $center['x'] - $width/2;
$topEdge = $center['y'] - $height/2;


// Now download all the tiles
$tiles = array();
$overlays = array();
$chs = array();
$mh = curl_multi_init();
$numTiles = 0;

for($x = $swTile['x']; $x <= $neTile['x']; $x++) {
  if(!array_key_exists("$x", $tiles)) {
    $tiles["$x"] = array();
    $overlays["$x"] = array();
    $chs["$x"] = array();
    $ochs["$x"] = array();
  }

  for($y = $swTile['y']; $y <= $neTile['y']; $y++) {
    $url = urlForTile($x, $y, $zoom, $tileURL);
    $tiles["$x"]["$y"] = false;
    $chs["$x"]["$y"] = curl_init($url);
    curl_setopt($chs["$x"]["$y"], CURLOPT_RETURNTRANSFER, TRUE);
    curl_multi_add_handle($mh, $chs["$x"]["$y"]);

    if($overlayURL) {
      $url = urlForTile($x, $y, $zoom, $overlayURL);
      $overlays["$x"]["$y"] = false;
      $ochs["$x"]["$y"] = curl_init($url);
      curl_setopt($ochs["$x"]["$y"], CURLOPT_RETURNTRANSFER, TRUE);
      curl_multi_add_handle($mh, $ochs["$x"]["$y"]);
    }

    $numTiles++;
  }
}

$running = null;
// Execute the handles. Blocks until all are finished.
do {
  $mrc = curl_multi_exec($mh, $running);
} while($running > 0);

foreach($chs as $x=>$yTiles) {
  foreach($yTiles as $y=>$ch) {
    $tiles["$x"]["$y"] = imagecreatefromstring(curl_multi_getcontent($ch));
  }
}

if($overlayURL) {
  foreach($ochs as $x=>$yTiles) {
    foreach($yTiles as $y=>$ch) {
      $overlays["$x"]["$y"] = imagecreatefromstring(curl_multi_getcontent($ch));
    }
  }
}

// Assemble all the tiles into a new image positioned as appropriate
foreach($tiles as $x=>$yTiles) {
  foreach($yTiles as $y=>$tile) {
    $x = intval($x);
    $y = intval($y);

    $ox = (($x - $tilePos['x']) * TILE_SIZE) - $pos['x'] + ($width/2);
    $oy = (($y - $tilePos['y']) * TILE_SIZE) - $pos['y'] + ($height/2);

    imagecopy($im, $tile, $ox,$oy, 0,0, imagesx($tile),imagesy($tile));
  }
}

if($overlayURL) {
  foreach($overlays as $x=>$yTiles) {
    foreach($yTiles as $y=>$tile) {
      $x = intval($x);
      $y = intval($y);

      $ox = (($x - $tilePos['x']) * TILE_SIZE) - $pos['x'] + ($width/2);
      $oy = (($y - $tilePos['y']) * TILE_SIZE) - $pos['y'] + ($height/2);

      imagecopy($im, $tile, $ox,$oy, 0,0, imagesx($tile),imagesy($tile));
    }
  }
}


// Add markers
foreach($markers as $marker) {
  // Icons that start with 'dot-' do not have a shadow
  $shadow = !preg_match('/^dot-/', $marker['icon']);

  // Icons with a shadow are centered at the bottom middle pixel.
  // Icons with no shadow are centered in the center pixel.

  $px = $webmercator->latLngToPixels($marker['lat'], $marker['lng'], $zoom);
  $pos = array(
    'x' => $px['x'] - $leftEdge,
    'y' => $px['y'] - $topEdge
  );

  $iconImg = imagecreatefrompng($marker['iconFile']);

  if($shadow) {
    $iconPos = array(
      'x' => $pos['x'] - round(imagesx($iconImg)/2),
      'y' => $pos['y'] - imagesy($iconImg)
    );
  } else {
    $iconPos = array(
      'x' => $pos['x'] - round(imagesx($iconImg)/2),
      'y' => $pos['y'] - round(imagesy($iconImg)/2)
    );
  }

  imagecopy($im, $iconImg, $iconPos['x'], $iconPos['y'], 0,0, imagesx($iconImg),imagesy($iconImg));
}


$logo = imagecreatefrompng('./images/powered-by-esri.png');

// Shrink the esri logo if the image is small
if($width > 120) {
  if($width < 220) {
    $shrinkFactor = 2;
    imagecopyresampled($im, $logo, $width-round(imagesx($logo)/$shrinkFactor)-4, $height-round(imagesy($logo)/$shrinkFactor)-4, 0,0, round(imagesx($logo)/$shrinkFactor),round(imagesy($logo)/$shrinkFactor), imagesx($logo),imagesy($logo));
  } else {
    imagecopy($im, $logo, $width-imagesx($logo)-4, $height-imagesy($logo)-4, 0,0, imagesx($logo),imagesy($logo));
  }
}

// -1 is the default compressions level compiled into the zlib library
$quality = get('quality', -1);

header('X-Tiles-Downloaded: ' . $numTiles);
header('Content-type: image/png');
imagepng($im, null, $quality);
imagedestroy($im);


/**
 * http://msdn.microsoft.com/en-us/library/bb259689.aspx
 * http://derickrethans.nl/php-mapping.html
 */

function pa($a) {
  echo '<pre>';
  print_r($a);
  echo '</pre>';
}

function get($k, $default=false) {
  return array_key_exists($k, $_GET) ? $_GET[$k] : $default;
}


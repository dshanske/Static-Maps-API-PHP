<?php
include('include/WebMercator.php');
include('include/ArcGISGeocoder.php');

define('TILE_SIZE', 256);

$webmercator = new WebMercator();

// If any markers are specified, choose a default lat/lng as the center of all the markers

$bounds = array(
  'minLat' => 90,
  'maxLat' => -90,
  'minLng' => 180,
  'maxLng' => -180
);

$markers = array();
if($markersTemp=request('marker')) {
  if(!is_array($markersTemp))
    $markersTemp = array($markersTemp);

  // If no latitude is set, use the center of all the markers
  foreach($markersTemp as $i=>$m) {
    if(preg_match_all('/(?P<k>[a-z]+):(?P<v>[^;]+)/', $m, $matches)) {
      $properties = array();
      foreach($matches['k'] as $j=>$key) {
        $properties[$key] = $matches['v'][$j];
      }

      // Skip invalid marker definitions, show error in a header
      if(array_key_exists('icon', $properties) && (
          (array_key_exists('lat', $properties) && array_key_exists('lng', $properties))
          || array_key_exists('location', $properties)
        )
      ) {

        // Geocode the provided location and return lat/lng
        if(array_key_exists('location', $properties)) {
          $result = ArcGISGeocoder::geocode($properties['location']);
          if(!$result->success) {
            header('X-Marker-' . ($i+1) . ': error geocoding location "' . $properties['location'] . '"');
            continue;
          }

          $properties['lat'] = $result->latitude;
          $properties['lng'] = $result->longitude;
        }

        if(preg_match('/https?:\/\/(.+)/', $properties['icon'], $match)) {
          // Looks like an external image, attempt to download it
          $properties['iconFile'] = './images/remote/' . str_replace('.', '_', urlencode($match[1])) . '.png';
          $ch = curl_init($properties['icon']);
          curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
          $img = curl_exec($ch);
          file_put_contents($properties['iconFile'], $img);
          $properties['iconImg'] = @imagecreatefrompng($properties['iconFile']);
          if(!$properties['iconImg']) {
            unlink($properties['iconFile']);
            $properties['iconFile'] = false;
            $properties['iconImg'] = false;
          }
        } else {
          $properties['iconFile'] = './images/' . $properties['icon'] . '.png';
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
      } else {
        header('X-Marker-' . ($i+1) . ': missing icon, or lat/lng/location parameters');
      }
    }
  }
}


$paths = array();
if($pathsTemp=request('path')) {
  if(!is_array($pathsTemp))
    $pathsTemp = array($pathsTemp);

  foreach($pathsTemp as $i=>$path) {
    $properties = array();
    if(preg_match_all('/(?P<k>[a-z]+):(?P<v>[^;]+)/', $path, $matches)) {
      foreach($matches['k'] as $j=>$key) {
        $properties[$key] = $matches['v'][$j];
      }
    }

    // Set default color and weight if none specified
    if(!array_key_exists('color', $properties))
      $properties['color'] = '333333';
    if(!array_key_exists('weight', $properties))
      $properties['weight'] = 6;

    // Now parse the points into an array
    if(preg_match_all('/(?P<point>\[[0-9\.-]+,[0-9\.-]+\])/', $path, $matches)) {
      $properties['path'] = json_decode('[' . implode(',', $matches['point']) . ']');
    }

    if(array_key_exists('path', $properties))
      $paths[] = $properties;
  }
}


$defaultLatitude = $bounds['minLat'] + (($bounds['maxLat'] - $bounds['minLat']) / 2);
$defaultLongitude = $bounds['minLng'] + (($bounds['maxLng'] - $bounds['minLng']) / 2);

if(request('latitude') !== false) {
  $latitude = request('latitude');
  $longitude = request('longitude');
} elseif(request('location') !== false) {
  $result = ArcGISGeocoder::geocode(request('location'));
  if(!$result->success) {
    $latitude = $defaultLatitude;
    $longitude = $defaultLongitude;
    header('X-Geocode: error');
    header('X-Geocode-Result: ' . $result->raw);
  } else {
    $latitude = $result->latitude;
    $longitude = $result->longitude;
    header('X-Geocode: success');
    header('X-Geocode-Result: ' . $latitude . ', ' . $longitude);
  }
} else {
  $latitude = $defaultLatitude;
  $longitude = $defaultLongitude;
}



$width = request('width', 300);
$height = request('height', 300);


// If no zoom is specified, choose a zoom level that will fit all the markers
if(request('zoom')) {
  $zoom = request('zoom');
} else {

  // start at max zoom level (20)
  $fitZoom = 20;
  $doesNotFit = true;
  while($fitZoom > 1 && $doesNotFit) {
    $center = $webmercator->latLngToPixels($latitude, $longitude, $fitZoom);

    $leftEdge = $center['x'] - $width/2;
    $topEdge = $center['y'] - $height/2;

    // check if the bounding rectangle fits within width/height
    $sw = $webmercator->latLngToPixels($bounds['minLat'], $bounds['minLng'], $fitZoom);
    $ne = $webmercator->latLngToPixels($bounds['maxLat'], $bounds['maxLng'], $fitZoom);

    $fitHeight = abs($ne['y'] - $sw['y']);
    $fitWidth = abs($ne['x'] - $sw['x']);

    if($fitHeight <= $height && $fitWidth <= $width) {
      $doesNotFit = false;
    }

    $fitZoom--;
  }

  $zoom = $fitZoom;
}



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
  'gray-background' => array(
    'http://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Base/MapServer/tile/{Z}/{Y}/{X}',
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

if(request('basemap')) {
  $tileURL = $tileServices[request('basemap')][0];
  if(array_key_exists(1, $tileServices[request('basemap')]))
    $overlayURL = $tileServices[request('basemap')][1];
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




$im = imagecreatetruecolor($width, $height);

// Find the pixel coordinate of the center of the map
$center = $webmercator->latLngToPixels($latitude, $longitude, $zoom);

$leftEdge = $center['x'] - $width/2;
$topEdge = $center['y'] - $height/2;

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

// In case any of the tiles fail, they will be grey instead of throwing an error
$blank = imagecreatetruecolor(256, 256);
$grey = imagecolorallocate($im, 224, 224, 224);
imagefill($blank, 0,0, $grey);

foreach($chs as $x=>$yTiles) {
  foreach($yTiles as $y=>$ch) {
    $content = curl_multi_getcontent($ch);
    if($content)
      $tiles["$x"]["$y"] = @imagecreatefromstring($content);
    else
      $tiles["$x"]["$y"] = $blank;
  }
}

if($overlayURL) {
  foreach($ochs as $x=>$yTiles) {
    foreach($yTiles as $y=>$ch) {
      $content = curl_multi_getcontent($ch);
      if($content)
        $overlays["$x"]["$y"] = @imagecreatefromstring($content);
      else
        $overlays["$x"]["$y"] = $blank;
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

  if(!array_key_exists('iconImg', $marker))
    $marker['iconImg'] = imagecreatefrompng($marker['iconFile']);

  if($shadow) {
    $iconPos = array(
      'x' => $pos['x'] - round(imagesx($marker['iconImg'])/2),
      'y' => $pos['y'] - imagesy($marker['iconImg'])
    );
  } else {
    $iconPos = array(
      'x' => $pos['x'] - round(imagesx($marker['iconImg'])/2),
      'y' => $pos['y'] - round(imagesy($marker['iconImg'])/2)
    );
  }

  imagecopy($im, $marker['iconImg'], $iconPos['x'], $iconPos['y'], 0,0, imagesx($marker['iconImg']),imagesy($marker['iconImg']));
}

imageantialias($im, true); // should anti-alias lines
$colors = array();
foreach($paths as $path) {
  imagesetthickness($im, $path['weight']);

  $colork = $path['color'];
  if(!array_key_exists($colork, $colors))
    $colors[$colork] = imagecolorallocatealpha($im, 
      hexdec($path['color'][0].$path['color'][1]), 
      hexdec($path['color'][2].$path['color'][3]), 
      hexdec($path['color'][4].$path['color'][5]), 0);
  $theColor = $colors[$colork];

  $previous = false;
  foreach($path['path'] as $point) {
    if($previous) {
      $from = $webmercator->latLngToPixels($previous[1], $previous[0], $zoom);
      $to = $webmercator->latLngToPixels($point[1], $point[0], $zoom);
      imageline($im, $from['x'] - $leftEdge,$from['y']-$topEdge, $to['x']-$leftEdge,$to['y']-$topEdge, $theColor);
    }
    $previous = $point;
  }
}


if(request('attribution') != 'none') {
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
}

header('Cache-Control: max-age=' . (60*60*24*30) . ', public');

header('X-Tiles-Downloaded: ' . $numTiles);

$fmt = request('format', "png");
switch($fmt) {
  case "jpg":
  case "jpeg":
    header('Content-type: image/jpg');
    $quality = request('quality', 75);
    imagejpeg($im, null, $quality);
    break;
  case "png":
#  default:
    header('Content-type: image/png');
    imagepng($im);
    break;
}
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

function request($k, $default=false) {
  return array_key_exists($k, $_REQUEST) ? $_REQUEST[$k] : $default;
}


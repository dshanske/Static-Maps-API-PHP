<?php

function get($k, $default=false) {
  return array_key_exists($k, $_GET) ? $_GET[$k] : $default;
}

define('TILE_SIZE', 256);

$defaultLatitude = 45.5165;
$defaultLongitude = -122.6764;

$markers = array();
if($markersTemp=get('marker')) {
  if(!is_array($markersTemp))
    $markersTemp = array($markersTemp);

  // If no latitude is set, use the center of all the markers
  foreach($markersTemp as $m) {
    if(preg_match_all('/(?P<k>[a-z]+):(?P<v>[^,]+)/', $m, $matches)) {
      $properties = array();
      foreach($matches['k'] as $i=>$key) {
        $properties[$key] = $matches['v'][$i];
      }

      // Skip invalid marker definitions for now, maybe show an error later?
      if(array_key_exists('lat', $properties) && array_key_exists('lng', $properties) && array_key_exists('icon', $properties)) {
        $properties['iconFile'] = './images/' . $properties['icon'] . '.png';
        if(file_exists($properties['iconFile'])) {
          $markers[] = $properties;
        }
      }
    }
  }

  foreach($markers as $marker) {

  }
}

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
    'http://services.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Reference/MapServer/tile/{Z}/{Y}/{X}'
  ),
  'oceans' => array(
    'http://server.arcgisonline.com/ArcGIS/rest/services/Ocean_Basemap/MapServer/tile/{Z}/{Y}/{X}'
  ),
  'national-geographic' => array(
    'http://server.arcgisonline.com/ArcGIS/rest/services/NatGeo_World_Map/MapServer/tile/{Z}/{Y}/{X}'
  ),
  'osm' => array(
    'http://tile.openstreetmap.org/{Z}/{X}/{Y}.png'
  )
);

if(get('basemap')) {
  $tileURL = $tileServices[get('basemap')][0];
} else {
  $tileURL = $tileServices['streets'][0];
}
#$tileURL = "images/{Y}-{X}.jpg";

/*
?>
<div style="float:left;">
  <img src="output.jpg" width="<?= $width ?>" height="<?= $height ?>" /><br />
  <!-- 
  <img src="http://maps.googleapis.com/maps/api/staticmap?center=<?= $latitude ?>,<?= $longitude ?>&amp;zoom=14&amp;size=<?= $width ?>x<?= $height ?>&amp;sensor=false" width="<?= $width ?>" height="<?= $height ?>" /> 
  -->
</div>
<?php
*/



function urlForTile($x, $y, $z) {
  global $tileURL;
  return str_replace(array(
    '{X}', '{Y}', '{Z}'
  ), array(
    $x, $y, $z
  ), $tileURL);
}


class Mercator {

  public function totalPixelsForZoomLevel($zoom) {
    return pow(2, $zoom) * TILE_SIZE;
  }

  public function lngToX($longitude, $zoom) {
    return round((($longitude + 180) / 360) * $this->totalPixelsForZoomLevel($zoom));
  }

  public function latToY($latitude, $zoom) {
    return round(((atanh(sin(deg2rad(-$latitude))) / pi()) + 1) * $this->totalPixelsForZoomLevel($zoom - 1));
  }

  public function latLngToPixels($latitude, $longitude, $zoom) {
    return array(
      'x' => $this->lngToX($longitude, $zoom),
      'y' => $this->latToY($latitude, $zoom)
    );
  }


  public function xToLng($x, $zoom) {
    return (($x * 360) / $this->totalPixelsForZoomLevel($zoom)) - 180;
  }

  public function yToLat($y, $zoom) {
    $a = pi() * (($y / $this->totalPixelsForZoomLevel($zoom - 1)) - 1);
    return -1 * (rad2deg(asin(tanh($a))));
  }

  public function pixelsToLatLng($x, $y, $zoom) {
    return array(
      'lat' => $this->yToLat($y, $zoom),
      'lng' => $this->xToLng($x, $zoom)
    );
  }

  public function tileToPixels($x, $y) {
    return array(
      'x' => $x * TILE_SIZE,
      'y' => $y * TILE_SIZE
    );
  }

  public function pixelsToTile($x, $y) {
    return array(
      'x' => floor($x / TILE_SIZE),
      'y' => floor($y / TILE_SIZE)
    );
  }

  public function positionInTile($x, $y) {
    $tile = $this->pixelsToTile($x, $y);
    return array(
      'x' => round(TILE_SIZE * (($x / TILE_SIZE) - $tile['x'])),
      'y' => round(TILE_SIZE * (($y / TILE_SIZE) - $tile['y']))
    );
  }

}

$mercator = new Mercator();


$im = imagecreatetruecolor($width, $height);


// Find the pixel coordinate of the center of the map
$center = $mercator->latLngToPixels($latitude, $longitude, $zoom);
// echo '<br />';

$tilePos = $mercator->pixelsToTile($center['x'], $center['y']);
// print_r($tilePos);
// echo '<br />';

$pos = $mercator->positionInTile($center['x'], $center['y']);
// print_r($pos);
// echo '<br />';

// For the given number of pixels, determine how many tiles are needed in each direction
$neTile = $mercator->pixelsToTile($center['x'] + $width/2, $center['y'] + $height/2);
// print_r($neTile);
// echo '<br />';

$swTile = $mercator->pixelsToTile($center['x'] - $width/2, $center['y'] - $height/2);
// print_r($swTile);
// echo '<br />';

$leftEdge = $center['x'] - $width/2;
$topEdge = $center['y'] - $height/2;


// Now download all the tiles
$tiles = array();
$chs = array();
$mh = curl_multi_init();
$numTiles = 0;

for($x = $swTile['x']; $x <= $neTile['x']; $x++) {
  if(!array_key_exists("$x", $tiles)) {
    $tiles["$x"] = array();
    $chs["$x"] = array();
  }

  for($y = $swTile['y']; $y <= $neTile['y']; $y++) {
    $url = urlForTile($x, $y, $zoom);
    $tiles["$x"]["$y"] = false;
    $chs["$x"]["$y"] = curl_init($url);
    curl_setopt($chs["$x"]["$y"], CURLOPT_RETURNTRANSFER, TRUE);
    curl_multi_add_handle($mh, $chs["$x"]["$y"]);
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

// Assemble all the tiles into a new image positioned as appropriate
foreach($tiles as $x=>$yTiles) {
  foreach($yTiles as $y=>$tile) {
    $x = intval($x);
    $y = intval($y);
    // echo '<hr />';
    // echo $x . 'x' . $y . '<br />';

    // print_r($tilePos); echo '<br />';
    // print_r($pos); echo '<br />';

    $ox = (($x - $tilePos['x']) * TILE_SIZE) - $pos['x'] + ($width/2);
    $oy = (($y - $tilePos['y']) * TILE_SIZE) - $pos['y'] + ($height/2);

    // echo 'Offset: ' . $ox . 'x' . $oy . '<br />';
    imagecopy($im, $tile, $ox,$oy, 0,0, imagesx($tile),imagesy($tile));
  }
}


// Add markers
foreach($markers as $marker) {
  // Icons that start with 'dot-' do not have a shadow
  $shadow = !preg_match('/^dot-/', $marker['icon']);

  // Icons with a shadow are centered at the bottom middle pixel.
  // Icons with no shadow are centered in the center pixel.

  $px = $mercator->latLngToPixels($marker['lat'], $marker['lng'], $zoom);
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
if($width < 220) {
  $shrinkFactor = 2;
  imagecopyresampled($im, $logo, $width-round(imagesx($logo)/$shrinkFactor)-4, $height-round(imagesy($logo)/$shrinkFactor)-4, 0,0, round(imagesx($logo)/$shrinkFactor),round(imagesy($logo)/$shrinkFactor), imagesx($logo),imagesy($logo));
} else {
  imagecopy($im, $logo, $width-imagesx($logo)-4, $height-imagesy($logo)-4, 0,0, imagesx($logo),imagesy($logo));
}


header('X-Tiles-Downloaded: ' . $numTiles);
header('Content-type: image/png');
imagepng($im);
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
<?php

function get($k, $default=false) {
  return array_key_exists($k, $_GET) ? $_GET[$k] : $default;
}

$latitude = get('latitude', 45.5165);
$longitude = get('longitude', -122.6764);
$zoom = get('zoom', 14);
$width = get('width', 300);
$height = get('height', 300);


$tileURL = "http://services.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{Z}/{Y}/{X}";
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
    return pow(2, $zoom) * 256;
  }

  public function lngToX($longitude, $zoom) {
    return (($longitude + 180) / 360) * $this->totalPixelsForZoomLevel($zoom);
  }

  public function latToY($latitude, $zoom) {
    return ((atanh(sin(deg2rad(-$latitude))) / pi()) + 1) * $this->totalPixelsForZoomLevel($zoom - 1);
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
      'x' => $x * 256,
      'y' => $y * 256
    );
  }

  public function pixelsToTile($x, $y) {
    return array(
      'x' => floor($x / 256),
      'y' => floor($y / 256)
    );
  }

  public function positionInTile($x, $y) {
    $tile = $this->pixelsToTile($x, $y);
    return array(
      'x' => round(256 * (($x / 256) - $tile['x'])),
      'y' => round(256 * (($y / 256) - $tile['y']))
    );
  }

}

$mercator = new Mercator();


$im = imagecreatetruecolor($width, $height);


// Find the pixel coordinate of the center of the map
$center = $mercator->latLngToPixels($latitude, $longitude, $zoom);
// print_r($center);
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

// Now download all the tiles
$tiles = array();
$numTiles = 0;

for($x = $swTile['x']; $x <= $neTile['x']; $x++) {
  if(!array_key_exists("$x", $tiles))
    $tiles["$x"] = array();

  for($y = $swTile['y']; $y <= $neTile['y']; $y++) {
    $url = urlForTile($x, $y, $zoom);
    $tiles["$x"]["$y"] = imagecreatefromjpeg($url);
    $numTiles++;
  }
}

// echo '<pre>';
// print_r($tiles);
// echo '</pre>';


// Assemble all the tiles into a new image positioned as appropriate
foreach($tiles as $x=>$yTiles) {
  foreach($yTiles as $y=>$tile) {
    $x = intval($x);
    $y = intval($y);
    // echo '<hr />';
    // echo $x . 'x' . $y . '<br />';

    // print_r($tilePos); echo '<br />';
    // print_r($pos); echo '<br />';

    $ox = (($x - $tilePos['x']) * 256) - $pos['x'] + ($width/2);
    $oy = (($y - $tilePos['y']) * 256) - $pos['y'] + ($height/2);

    // echo 'Offset: ' . $ox . 'x' . $oy . '<br />';
    imagecopy($im, $tile, $ox,$oy, 0,0, imagesx($tile),imagesy($tile));

  }
}


// Add markers

if(get('marker')) {

}




$logo = imagecreatefrompng('./images/powered-by-esri.png');
// TODO: Shrink the logo if the image is small
if($width < 160) {
  $shrinkFactor = 2;
  imagecopyresampled($im, $logo, $width-round(imagesx($logo)/$shrinkFactor)-4, $height-round(imagesy($logo)/$shrinkFactor)-4, 0,0, round(imagesx($logo)/$shrinkFactor),round(imagesy($logo)/$shrinkFactor), imagesx($logo),imagesy($logo));
} else {
  imagecopy($im, $logo, $width-imagesx($logo)-4, $height-imagesy($logo)-4, 0,0, imagesx($logo),imagesy($logo));
}


header('X-Tiles-Downloaded: ' . $numTiles);
header('Content-type: image/jpeg');
imagejpeg($im, null, 90);
imagedestroy($im);


/**
 * http://msdn.microsoft.com/en-us/library/bb259689.aspx
 * http://derickrethans.nl/php-mapping.html
 */

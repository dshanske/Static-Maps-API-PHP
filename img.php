<?php
include('include/WebMercator.php');
include('include/ArcGISGeocoder.php');
include('include/Polyline.php');

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
          $ch = curl_init($properties['icon']);
          curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
          $img = curl_exec($ch);
          $properties['iconImg'] = @imagecreatefromstring($img);
          if(!$properties['iconImg']) {
            $properties['iconImg'] = false;
          }
        } else {
          $properties['iconImg'] = imagecreatefrompng('./images/' . $properties['icon'] . '.png');
        }

        if($properties['iconImg']) {
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
      $properties['weight'] = 3;

    // Now parse the points into an array
    if(preg_match_all('/(?P<point>\[[0-9\.-]+,[0-9\.-]+\])/', $path, $matches)) {
      $properties['path'] = json_decode('[' . implode(',', $matches['point']) . ']');
      // Adjust the bounds to fit the path

      foreach($properties['path'] as $point) {
        if($point[1] < $bounds['minLat'])
          $bounds['minLat'] = $point[1];

        if($point[1] > $bounds['maxLat'])
          $bounds['maxLat'] = $point[1];

        if($point[0] < $bounds['minLng'])
          $bounds['minLng'] = $point[0];

        if($point[0] > $bounds['maxLng'])
          $bounds['maxLng'] = $point[0];
      }
    }
    if(array_key_exists('path', $properties))
      $paths[] = $properties;

  }
} else if($pathsTemp=request('polyline')) {
    $properties = array();
    if(preg_match_all('/(?P<k>[a-z]+):(?P<v>[^;]+)/', $pathsTemp, $matches)) {
      foreach($matches['k'] as $j=>$key) {
        $properties[$key] = $matches['v'][$j];
      }
    }

    // Set default color and weight if none specified
    if(!array_key_exists('color', $properties))
      $properties['color'] = '333333';
    if(!array_key_exists('weight', $properties))
      $properties['weight'] = 3;
      if ( array_key_exists( 'enc', $properties ) ) {
      	    $properties['path'] = Polyline::pair( Polyline::decode( $properties['enc'] ) );
            foreach($properties['path'] as $point) {
            if($point[1] < $bounds['minLat'])
               $bounds['minLat'] = $point[1];

            if($point[1] > $bounds['maxLat'])
              $bounds['maxLat'] = $point[1];

            if($point[0] < $bounds['minLng'])
              $bounds['minLng'] = $point[0];

            if($point[0] > $bounds['maxLng'])
              $bounds['maxLng'] = $point[0];
            }

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


// If no zoom is specified, choose a zoom level that will fit all the markers and the path
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

if(request('maxzoom') && request('maxzoom') < $zoom) {
  $zoom = request('maxzoom');
}

$minZoom = 2;
if($zoom < $minZoom)
  $zoom = $minZoom;

$maxZoom = 18;
if ($zoom > $maxZoom)
  $zoom = $maxZoom;

$tileServices = array(
  'streets' => array(
    'https://services.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{Z}/{Y}/{X}'
  ),
  'satellite' => array(
    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{Z}/{Y}/{X}'
  ),
  'hybrid' => array(
    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{Z}/{Y}/{X}',
    'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{Z}/{Y}/{X}'
  ),
  'topo' => array(
    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Topo_Map/MapServer/tile/{Z}/{Y}/{X}'
  ),
  'gray' => array(
    'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Base/MapServer/tile/{Z}/{Y}/{X}',
    'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Reference/MapServer/tile/{Z}/{Y}/{X}'
  ),
  'gray-background' => array(
    'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Base/MapServer/tile/{Z}/{Y}/{X}',
  ),
  'oceans' => array(
    'https://server.arcgisonline.com/ArcGIS/rest/services/Ocean_Basemap/MapServer/tile/{Z}/{Y}/{X}'
  ),
  'national-geographic' => array(
    'https://server.arcgisonline.com/ArcGIS/rest/services/NatGeo_World_Map/MapServer/tile/{Z}/{Y}/{X}'
  ),
  'osm' => array(
    'https://tile.openstreetmap.org/{Z}/{X}/{Y}.png'
  ),
  'otm' => array(
    'https://tile.opentopomap.org/{Z}/{X}/{Y}.png'
  ),
  'stamen-toner' => array(
    'https://stamen-tiles.a.ssl.fastly.net/toner/{Z}/{X}/{Y}.png'
  ),
  'stamen-toner-background' => array(
    'https://stamen-tiles.a.ssl.fastly.net/toner-background/{Z}/{X}/{Y}.png'
  ),
  'stamen-toner-lite' => array(
    'https://stamen-tiles.a.ssl.fastly.net/toner-lite/{Z}/{X}/{Y}.png'
  ),
  'stamen-terrain' => array(
    'https://stamen-tiles.a.ssl.fastly.net/terrain/{Z}/{X}/{Y}.png'
  ),
  'stamen-terrain-background' => array(
    'https://stamen-tiles.a.ssl.fastly.net/terrain-background/{Z}/{X}/{Y}.png'
  ),
  'stamen-watercolor' => array(
    'https://stamen-tiles.a.ssl.fastly.net/watercolor/{Z}/{X}/{Y}.png'
  ),
  'carto-light' => array(
    'https://cartodb-basemaps-a.global.ssl.fastly.net/light_all/{Z}/{X}/{Y}.png'
  ),
  'carto-dark' => array(
    'https://cartodb-basemaps-a.global.ssl.fastly.net/dark_all/{Z}/{X}/{Y}.png'
  ),
  'carto-voyager' => array(
    'https://cartodb-basemaps-a.global.ssl.fastly.net/rastertiles/voyager/{Z}/{X}/{Y}.png'
  )
);

if( (request('basemap')) && array_key_exists( request('basemap'), $tileServices ) ) {
  $tileURL = $tileServices[request('basemap')][0];
  if(array_key_exists(1, $tileServices[request('basemap')]))
    $overlayURL = $tileServices[request('basemap')][1];
  else
    $overlayURL = 0;
} elseif ('custom' === request('basemap') ) {
    $tileURL = request('tileurl');
    $overlayURL = false;
} else {
  $tileURL = $tileServices['osm'][0];
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
    curl_setopt($chs["$x"]["$y"], CURLOPT_USERAGENT, 'Static Maps API/ github.com/aaronpk/Static-Maps-API-PHP');
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


if(count($paths)) {
  // Draw the path with ImageMagick because GD sucks as anti-aliased lines
  $mg = new Imagick();
  $mg->newImage($width, $height, new ImagickPixel('none'));

  $draw = new ImagickDraw();

  $colors = array();
  foreach($paths as $path) {

    $draw->setStrokeColor(new ImagickPixel('#'.$path['color']));
    $draw->setStrokeWidth($path['weight']);
    $draw->setFillOpacity(0);
    $draw->setStrokeLineCap(Imagick::LINECAP_ROUND);
    $draw->setStrokeLineJoin(Imagick::LINEJOIN_ROUND);

    $previous = false;
    foreach($path['path'] as $point) {
      if($previous) {
        $from = $webmercator->latLngToPixels($previous[1], $previous[0], $zoom);
        $to = $webmercator->latLngToPixels($point[1], $point[0], $zoom);

        if( request('bezier') ) {
          $x_dist = abs($from['x'] - $to['x']);
	  $y_dist = abs($from['y'] - $to['y']);

	  // If the X distance is longer than Y distance, draw from left to right
	  if($x_dist > $y_dist) {
	    // Draw from left to right
	    if($from['x'] > $to['x']) {
	      $tmpFrom = $from;
	      $tmpTo = $to;
	      $from = $tmpTo;
	      $to = $tmpFrom;
	      unset($tmp);
	     }
	  } else {
	    // Draw from top to bottom
	    if($from['y'] > $to['y']) {
	      $tmpFrom = $from;
	      $tmpTo = $to;
	      $from = $tmpTo;
	      $to = $tmpFrom;
	      unset($tmp);
	    }
	  }

          $angle = 1 * request('bezier');

	  // Midpoint between the two ends
	  $M = array(
	  	'x' => ($from['x'] + $to['x']) / 2,
		'y' => ($from['y'] + $to['y']) / 2
	  );

	  // Derived from http://math.stackexchange.com/a/383648 and http://www.wolframalpha.com/input/?i=triangle+%5B1,1%5D+%5B5,2%5D+%5B1-1%2Fsqrt(3),1%2B4%2Fsqrt(3)%5D
          // See  for details
          $A = $from;
          $B = $to;

          $P = array(
		'x' => ($M['x']) - (($A['y']-$M['y']) * tan(deg2rad($angle))),
		'y' => ($M['y']) + (($A['x']-$M['x']) * tan(deg2rad($angle)))
	  );

          $draw->pathStart();
	  $draw->pathMoveToAbsolute($A['x']-$leftEdge,$A['y']-$topEdge);
	  $draw->pathCurveToQuadraticBezierAbsolute(
		$P['x']-$leftEdge, $P['y']-$topEdge,
		$B['x']-$leftEdge, $B['y']-$topEdge
	  );
	
	  $draw->pathFinish();
        } else {
         $draw->line($from['x']-$leftEdge,$from['y']-$topEdge, $to['x']-$leftEdge,$to['y']-$topEdge);
        }
    }
      $previous = $point;
    }
  }

  $mg->drawImage($draw);
  $mg->setImageFormat( "png" );

  $pathImg = imagecreatefromstring($mg);
  imagecopy($im, $pathImg, 0,0, 0,0, $width,$height);
}


// Add markers
foreach($markers as $marker) {
  // Icons that start with 'dot-' do not have a shadow
  $shadow = !preg_match('/^dot-/', $marker['icon']);

  if($width < 120 || $height < 120) {
    $shrinkFactor = 1.5;
  } else {
    $shrinkFactor = 1;
  }

  // Icons with a shadow are centered at the bottom middle pixel.
  // Icons with no shadow are centered in the center pixel.

  $px = $webmercator->latLngToPixels($marker['lat'], $marker['lng'], $zoom);
  $pos = array(
    'x' => $px['x'] - $leftEdge,
    'y' => $px['y'] - $topEdge
  );

  if($shrinkFactor > 1) {
    $markerImg = imagecreatetruecolor(round(imagesx($marker['iconImg'])/$shrinkFactor), round(imagesy($marker['iconImg'])/$shrinkFactor));
    imagealphablending($markerImg, true);
    $color = imagecolorallocatealpha($markerImg, 0, 0, 0, 127);
    imagefill($markerImg, 0,0, $color);
    imagecopyresampled($markerImg, $marker['iconImg'], 0,0, 0,0, imagesx($markerImg),imagesy($markerImg), imagesx($marker['iconImg']),imagesy($marker['iconImg']));
  } else {
    $markerImg = $marker['iconImg'];
  }

  if($shadow) {
    $iconPos = array(
      'x' => $pos['x'] - round(imagesx($markerImg)/2),
      'y' => $pos['y'] - imagesy($markerImg)
    );
  } else {
    $iconPos = array(
      'x' => $pos['x'] - round(imagesx($markerImg)/2),
      'y' => $pos['y'] - round(imagesy($markerImg)/2)
    );
  }

  imagecopy($im, $markerImg, $iconPos['x'],$iconPos['y'], 0,0, imagesx($markerImg),imagesy($markerImg));
}



if(preg_match('/https?:\/\/(.+)/', request('attribution'), $match)) {
  // Looks like an external image, attempt to download it
  $ch = curl_init(request('attribution'));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $img = curl_exec($ch);
  $logo = @imagecreatefromstring($img);
  imagecopy($im, $logo, $width-imagesx($logo), $height-imagesy($logo), 0,0, imagesx($logo),imagesy($logo));
} elseif ( 'mapbox' === request('attribution') ) {
  $logo = imagecreatefrompng('./images/mapbox-attribution.png');
  imagecopy($im, $logo, $width-imagesx($logo), $height-imagesy($logo), 0,0, imagesx($logo),imagesy($logo));
} elseif ( 'mapbox-small' === request('attribution') ) {
  $logo = imagecreatefrompng($assetPath . '/mapbox-attribution.png');
  $shrinkFactor = 2;
  imagecopyresampled($im, $logo, $width-round(imagesx($logo)/$shrinkFactor), $height-round(imagesy($logo)/$shrinkFactor), 0,0, round(imagesx($logo)/$shrinkFactor),round(imagesy($logo)/$shrinkFactor), imagesx($logo),imagesy($logo));
} elseif('esri' === request('attribution') ) {
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
} else {
  $logo = imagecreatefrompng('./images/osm-attribution.png');
  $shrinkFactor = 4;
  imagecopyresampled($im, $logo, $width-round(imagesx($logo)/$shrinkFactor), $height-round(imagesy($logo)/$shrinkFactor), 0,0, round(imagesx($logo)/$shrinkFactor),round(imagesy($logo)/$shrinkFactor), imagesx($logo),imagesy($logo));
}
#header('Cache-Control: max-age=' . (60*60*24*30) . ', public');

#header('X-Tiles-Downloaded: ' . $numTiles);

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


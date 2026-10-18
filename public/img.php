<?php

include('../include/WebMercator.php');
include('../include/ArcGISGeocoder.php');
include('../include/Polyline.php');
include('../include/helpers.php');
include('../include/Config.php');

define('TILE_SIZE', 256);
define('ATTRIBUTION_DIR', __DIR__ . '/../assets/attribution');

try {
    $config = Config::load();
} catch (RuntimeException $e) {
    error_log('Static Maps API: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'Server configuration error';
    exit;
}

$webmercator = new WebMercator();

// If any markers are specified, choose a default lat/lng as the center of all the markers

$bounds = array(
  'minLat' => 90,
  'maxLat' => -90,
  'minLng' => 180,
  'maxLng' => -180
);

$is_authenticated = is_authenticated(request('token'));

$markers = array();
if ($markersTemp = request('marker')) {
    if (!is_array($markersTemp)) {
        $markersTemp = array($markersTemp);
    }

  // If no latitude is set, use the center of all the markers
    foreach ($markersTemp as $i => $m) {
        if (preg_match_all('/(?P<k>[a-z]+):(?P<v>[^;]+)/', $m, $matches)) {
            $properties = array();
            foreach ($matches['k'] as $j => $key) {
                $properties[$key] = $matches['v'][$j];
            }

          // Skip invalid marker definitions, show error in a header
            if (
                array_key_exists('icon', $properties) && (
                (array_key_exists('lat', $properties) && array_key_exists('lng', $properties))
                || array_key_exists('location', $properties)
                )
            ) {
              // Geocode the provided location and return lat/lng
                if (array_key_exists('location', $properties)) {
                    $result = ArcGISGeocoder::geocode($properties['location']);
                    if (!$result->success) {
                        header('X-Marker-' . ($i + 1) . ': error geocoding location "' . $properties['location'] . '"');
                        continue;
                    }

                    $properties['lat'] = $result->latitude;
                    $properties['lng'] = $result->longitude;
                }

                if (preg_match('/https?:\/\/(.+)/', $properties['icon'], $match)) {
                    if ($is_authenticated) {
                            // Looks like an external image, attempt to download it
                            $ch = curl_init($properties['icon']);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $img = curl_exec($ch);
                            $properties['iconImg'] = @imagecreatefromstring($img);
                    }
                } else {
                    $properties['iconImg'] = imagecreatefrompng('./images/' . $properties['icon'] . '.png');
                }

                if ($properties['iconImg']) {
                    $markers[] = $properties;
                }

                if ($properties['lat'] < $bounds['minLat']) {
                    $bounds['minLat'] = $properties['lat'];
                }

                if ($properties['lat'] > $bounds['maxLat']) {
                    $bounds['maxLat'] = $properties['lat'];
                }

                if ($properties['lng'] < $bounds['minLng']) {
                    $bounds['minLng'] = $properties['lng'];
                }

                if ($properties['lng'] > $bounds['maxLng']) {
                    $bounds['maxLng'] = $properties['lng'];
                }
            } else {
                header('X-Marker-' . ($i + 1) . ': missing icon, or lat/lng/location parameters');
            }
        }
    }
}


$paths = array();
if ($pathsTemp = request('path')) {
    if (!is_array($pathsTemp)) {
        $pathsTemp = array($pathsTemp);
    }

    foreach ($pathsTemp as $i => $path) {
        $properties = array();
        if (preg_match_all('/(?P<k>[a-z]+):(?P<v>[^;]+)/', $path, $matches)) {
            foreach ($matches['k'] as $j => $key) {
                $properties[$key] = $matches['v'][$j];
            }
        }

      // Set default color and weight if none specified
        if (!array_key_exists('color', $properties)) {
            $properties['color'] = $config['defaults']['path_color'];
        }
        if (!array_key_exists('weight', $properties)) {
            $properties['weight'] = $config['defaults']['path_weight'];
        }

      // Now parse the points into an array
        if (preg_match_all('/(?P<point>\[[0-9\.-]+,[0-9\.-]+\])/', $path, $matches)) {
            $properties['path'] = json_decode('[' . implode(',', $matches['point']) . ']');
          // Adjust the bounds to fit the path

            foreach ($properties['path'] as $point) {
                if ($point[1] < $bounds['minLat']) {
                    $bounds['minLat'] = $point[1];
                }

                if ($point[1] > $bounds['maxLat']) {
                    $bounds['maxLat'] = $point[1];
                }

                if ($point[0] < $bounds['minLng']) {
                    $bounds['minLng'] = $point[0];
                }

                if ($point[0] > $bounds['maxLng']) {
                    $bounds['maxLng'] = $point[0];
                }
            }
        }
        if (array_key_exists('path', $properties)) {
            $paths[] = $properties;
        }
    }
} elseif ($pathsTemp = request('polyline')) {
    $properties = array();
    if (preg_match_all('/(?P<k>[a-z]+):(?P<v>[^;]+)/', $pathsTemp, $matches)) {
        foreach ($matches['k'] as $j => $key) {
            $properties[$key] = $matches['v'][$j];
        }
    }

    // Set default color and weight if none specified
    if (!array_key_exists('color', $properties)) {
        $properties['color'] = $config['defaults']['path_color'];
    }
    if (!array_key_exists('weight', $properties)) {
        $properties['weight'] = $config['defaults']['path_weight'];
    }
    if (array_key_exists('enc', $properties)) {
          $properties['path'] = Polyline::pair(Polyline::decode($properties['enc']));
        foreach ($properties['path'] as $point) {
            if ($point[1] < $bounds['minLat']) {
                $bounds['minLat'] = $point[1];
            }

            if ($point[1] > $bounds['maxLat']) {
                $bounds['maxLat'] = $point[1];
            }

            if ($point[0] < $bounds['minLng']) {
                $bounds['minLng'] = $point[0];
            }

            if ($point[0] > $bounds['maxLng']) {
                $bounds['maxLng'] = $point[0];
            }
        }

          $paths[] = $properties;
    }
}

$defaultLatitude = $bounds['minLat'] + (($bounds['maxLat'] - $bounds['minLat']) / 2);
$defaultLongitude = $bounds['minLng'] + (($bounds['maxLng'] - $bounds['minLng']) / 2);

if (request('latitude') !== false) {
    $latitude = request('latitude');
    $longitude = request('longitude');
} elseif (request('location') !== false) {
    $result = ArcGISGeocoder::geocode(request('location'));
    if (!$result->success) {
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



$width = request('width', $config['defaults']['width']);
$height = request('height', $config['defaults']['height']);

// Choose the basemap: a configured one, a custom tile URL (token required), or the default
$basemap = is_string(request('basemap')) ? Config::basemap($config, request('basemap'), $is_authenticated) : null;
if ($basemap === null && 'custom' === request('basemap') && $is_authenticated && is_string(request('tileurl'))) {
    $basemap = array(
        'layers'  => array(request('tileurl')),
        'minzoom' => $config['zoom']['min'],
        'maxzoom' => $config['zoom']['max'],
    );
}
if ($basemap === null) {
    $basemap = Config::basemap($config, $config['defaults']['basemap'], $is_authenticated);
}
$tileURL = $basemap['layers'][0];
$overlayURL = $basemap['layers'][1] ?? false;
$tileKey = $basemap['key'] ?? null;


// If no zoom is specified, choose a zoom level that will fit all the markers and the path
if (request('zoom')) {
    $zoom = request('zoom');
} else {
  // start at max zoom level (20)
    $fitZoom = 20;
    $doesNotFit = true;
    while ($fitZoom > 1 && $doesNotFit) {
        $center = $webmercator->latLngToPixels($latitude, $longitude, $fitZoom);

        $leftEdge = $center['x'] - $width / 2;
        $topEdge = $center['y'] - $height / 2;

      // check if the bounding rectangle fits within width/height
        $sw = $webmercator->latLngToPixels($bounds['minLat'], $bounds['minLng'], $fitZoom);
        $ne = $webmercator->latLngToPixels($bounds['maxLat'], $bounds['maxLng'], $fitZoom);

        $fitHeight = abs($ne['y'] - $sw['y']);
        $fitWidth = abs($ne['x'] - $sw['x']);

        if ($fitHeight <= $height && $fitWidth <= $width) {
            $doesNotFit = false;
        }

        $fitZoom--;
    }

    $zoom = $fitZoom;
}

if (request('maxzoom') && request('maxzoom') < $zoom) {
    $zoom = request('maxzoom');
}

$minZoom = $basemap['minzoom'];
if ($zoom < $minZoom) {
    $zoom = $minZoom;
}

$maxZoom = $basemap['maxzoom'];
if ($zoom > $maxZoom) {
    $zoom = $maxZoom;
}

$im = imagecreatetruecolor($width, $height);

// Find the pixel coordinate of the center of the map
$center = $webmercator->latLngToPixels($latitude, $longitude, $zoom);

$leftEdge = $center['x'] - $width / 2;
$topEdge = $center['y'] - $height / 2;

$tilePos = $webmercator->pixelsToTile($center['x'], $center['y']);
// print_r($tilePos);
// echo '<br />';

$pos = $webmercator->positionInTile($center['x'], $center['y']);
// print_r($pos);
// echo '<br />';

// For the given number of pixels, determine how many tiles are needed in each direction
$neTile = $webmercator->pixelsToTile($center['x'] + $width / 2, $center['y'] + $height / 2);
// print_r($neTile);
// echo '<br />';

$swTile = $webmercator->pixelsToTile($center['x'] - $width / 2, $center['y'] - $height / 2);
// print_r($swTile);
// echo '<br />';


// Now download all the tiles
$tiles = array();
$overlays = array();
$chs = array();
$mh = curl_multi_init();
$numTiles = 0;

for ($x = $swTile['x']; $x <= $neTile['x']; $x++) {
    $x = (int)$x;
    if (!array_key_exists($x, $tiles)) {
        $tiles[$x] = array();
        $overlays[$x] = array();
        $chs[$x] = array();
        $ochs[$x] = array();
    }

    for ($y = $swTile['y']; $y <= $neTile['y']; $y++) {
        $y = (int)$y;
        $url = Config::tileUrl($tileURL, $x, $y, $zoom, $tileKey);
        $tiles[$x][$y] = false;
        $chs[$x][$y] = curl_init($url);
        curl_setopt($chs[$x][$y], CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chs[$x][$y], CURLOPT_FOLLOWLOCATION, true);
        curl_multi_add_handle($mh, $chs[$x][$y]);
        curl_setopt($chs[$x][$y], CURLOPT_USERAGENT, $config['user_agent']);
        if ($overlayURL) {
            $url = Config::tileUrl($overlayURL, $x, $y, $zoom, $tileKey);
            $overlays[$x][$y] = false;
            $ochs[$x][$y] = curl_init($url);
            curl_setopt($ochs[$x][$y], CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ochs[$x][$y], CURLOPT_FOLLOWLOCATION, true);
            curl_multi_add_handle($mh, $ochs[$x][$y]);
        }

        $numTiles++;
    }
}

$running = null;
// Execute the handles. Blocks until all are finished.
do {
    $mrc = curl_multi_exec($mh, $running);
} while ($running > 0);

// In case any of the tiles fail, they will be grey instead of throwing an error
$blank = imagecreatetruecolor(256, 256);
$grey = imagecolorallocate($im, 224, 224, 224);
imagefill($blank, 0, 0, $grey);

foreach ($chs as $x => $yTiles) {
    foreach ($yTiles as $y => $ch) {
        $content = curl_multi_getcontent($ch);
        $tiles[$x][$y] = @imagecreatefromstring($content);
        if (!$tiles[$x][$y]) {
            $tiles[$x][$y] = $blank;
        }
    }
}

if ($overlayURL) {
    foreach ($ochs as $x => $yTiles) {
        foreach ($yTiles as $y => $ch) {
            $content = curl_multi_getcontent($ch);
            $overlays[$x][$y] = @imagecreatefromstring($content);
            if (!$overlays[$x][$y]) {
                $overlays[$x][$y] = $blank;
            }
        }
    }
}

// Assemble all the tiles into a new image positioned as appropriate
foreach ($tiles as $x => $yTiles) {
    foreach ($yTiles as $y => $tile) {
        $x = intval($x);
        $y = intval($y);

        $ox = (($x - $tilePos['x']) * TILE_SIZE) - $pos['x'] + ($width / 2);
        $oy = (($y - $tilePos['y']) * TILE_SIZE) - $pos['y'] + ($height / 2);

        imagecopy($im, $tile, $ox, $oy, 0, 0, imagesx($tile), imagesy($tile));
    }
}

if ($overlayURL) {
    foreach ($overlays as $x => $yTiles) {
        foreach ($yTiles as $y => $tile) {
            $x = intval($x);
            $y = intval($y);

            $ox = (($x - $tilePos['x']) * TILE_SIZE) - $pos['x'] + ($width / 2);
            $oy = (($y - $tilePos['y']) * TILE_SIZE) - $pos['y'] + ($height / 2);

            imagecopy($im, $tile, $ox, $oy, 0, 0, imagesx($tile), imagesy($tile));
        }
    }
}


if (count($paths)) {
  // Draw the path with ImageMagick because GD sucks as anti-aliased lines
    $mg = new Imagick();
    $mg->newImage($width, $height, new ImagickPixel('none'));

    $draw = new ImagickDraw();

    $colors = array();
    foreach ($paths as $path) {
        $draw->setStrokeColor(new ImagickPixel('#' . $path['color']));
        $draw->setStrokeWidth($path['weight']);
        $draw->setFillOpacity(0);
        $draw->setStrokeLineCap(Imagick::LINECAP_ROUND);
        $draw->setStrokeLineJoin(Imagick::LINEJOIN_ROUND);

        $previous = false;
        foreach ($path['path'] as $point) {
            if ($previous) {
                $from = $webmercator->latLngToPixels($previous[1], $previous[0], $zoom);
                $to = $webmercator->latLngToPixels($point[1], $point[0], $zoom);

                if (request('bezier')) {
                    $x_dist = abs($from['x'] - $to['x']);
                    $y_dist = abs($from['y'] - $to['y']);

              // If the X distance is longer than Y distance, draw from left to right
                    if ($x_dist > $y_dist) {
                          // Draw from left to right
                        if ($from['x'] > $to['x']) {
                            $tmpFrom = $from;
                            $tmpTo = $to;
                            $from = $tmpTo;
                            $to = $tmpFrom;
                            unset($tmp);
                        }
                    } else {
                        // Draw from top to bottom
                        if ($from['y'] > $to['y']) {
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
                    'x' => ($M['x']) - (($A['y'] - $M['y']) * tan(deg2rad($angle))),
                    'y' => ($M['y']) + (($A['x'] - $M['x']) * tan(deg2rad($angle)))
                    );

                    $draw->pathStart();
                    $draw->pathMoveToAbsolute($A['x'] - $leftEdge, $A['y'] - $topEdge);
                    $draw->pathCurveToQuadraticBezierAbsolute(
                        $P['x'] - $leftEdge,
                        $P['y'] - $topEdge,
                        $B['x'] - $leftEdge,
                        $B['y'] - $topEdge
                    );

                    $draw->pathFinish();
                } else {
                    $draw->line($from['x'] - $leftEdge, $from['y'] - $topEdge, $to['x'] - $leftEdge, $to['y'] - $topEdge);
                }
            }
            $previous = $point;
        }
    }

    $mg->drawImage($draw);
    $mg->setImageFormat("png");

    $pathImg = imagecreatefromstring($mg);
    imagecopy($im, $pathImg, 0, 0, 0, 0, $width, $height);
}


// Add markers
foreach ($markers as $marker) {
  // Icons that start with 'dot-' do not have a shadow
    $shadow = !preg_match('/^dot-/', $marker['icon']);

    if ($width < 120 || $height < 120) {
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

    if ($shrinkFactor > 1) {
        $markerImg = imagecreatetruecolor(round(imagesx($marker['iconImg']) / $shrinkFactor), round(imagesy($marker['iconImg']) / $shrinkFactor));
        imagealphablending($markerImg, true);
        $color = imagecolorallocatealpha($markerImg, 0, 0, 0, 127);
        imagefill($markerImg, 0, 0, $color);
        imagecopyresampled($markerImg, $marker['iconImg'], 0, 0, 0, 0, imagesx($markerImg), imagesy($markerImg), imagesx($marker['iconImg']), imagesy($marker['iconImg']));
    } else {
        $markerImg = $marker['iconImg'];
    }

    if ($shadow) {
        $iconPos = array(
        'x' => $pos['x'] - round(imagesx($markerImg) / 2),
        'y' => $pos['y'] - imagesy($markerImg)
        );
    } else {
        $iconPos = array(
        'x' => $pos['x'] - round(imagesx($markerImg) / 2),
        'y' => $pos['y'] - round(imagesy($markerImg) / 2)
        );
    }

    imagecopy($im, $markerImg, $iconPos['x'], $iconPos['y'], 0, 0, imagesx($markerImg), imagesy($markerImg));
}



// The request's attribution, else the basemap's default, else the global default
$attribution = request('attribution');
if ($attribution === false) {
    $attribution = $basemap['attribution'] ?? $config['defaults']['attribution'];
}

if (preg_match('/https?:\/\/(.+)/', $attribution, $match)) {
  // Looks like an external image, attempt to download it
    $ch = curl_init($attribution);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $img = curl_exec($ch);
    $logo = @imagecreatefromstring($img);
    imagecopy($im, $logo, $width - imagesx($logo), $height - imagesy($logo), 0, 0, imagesx($logo), imagesy($logo));
} elseif ('mapbox' === $attribution) {
    $logo = imagecreatefrompng(ATTRIBUTION_DIR . '/mapbox-attribution.png');
    imagecopy($im, $logo, $width - imagesx($logo), $height - imagesy($logo), 0, 0, imagesx($logo), imagesy($logo));
} elseif ('mapbox-small' === $attribution) {
    $logo = imagecreatefrompng($assetPath . '/mapbox-attribution.png');
    $shrinkFactor = 2;
    imagecopyresampled($im, $logo, $width - round(imagesx($logo) / $shrinkFactor), $height - round(imagesy($logo) / $shrinkFactor), 0, 0, round(imagesx($logo) / $shrinkFactor), round(imagesy($logo) / $shrinkFactor), imagesx($logo), imagesy($logo));
} elseif ('esri' === $attribution) {
    $logo = imagecreatefrompng(ATTRIBUTION_DIR . '/powered-by-esri.png');

  // Shrink the esri logo if the image is small
    if ($width > 120) {
        if ($width < 220) {
            $shrinkFactor = 2;
            imagecopyresampled($im, $logo, $width - round(imagesx($logo) / $shrinkFactor) - 4, $height - round(imagesy($logo) / $shrinkFactor) - 4, 0, 0, round(imagesx($logo) / $shrinkFactor), round(imagesy($logo) / $shrinkFactor), imagesx($logo), imagesy($logo));
        } else {
            imagecopy($im, $logo, $width - imagesx($logo) - 4, $height - imagesy($logo) - 4, 0, 0, imagesx($logo), imagesy($logo));
        }
    }
} else {
    $logo = imagecreatefrompng(ATTRIBUTION_DIR . '/osm-attribution.png');
    $shrinkFactor = 4;
    imagecopyresampled($im, $logo, $width - round(imagesx($logo) / $shrinkFactor), $height - round(imagesy($logo) / $shrinkFactor), 0, 0, round(imagesx($logo) / $shrinkFactor), round(imagesy($logo) / $shrinkFactor), imagesx($logo), imagesy($logo));
}
#header('Cache-Control: max-age=' . (60*60*24*30) . ', public');

#header('X-Tiles-Downloaded: ' . $numTiles);

$fmt = request('format', $config['defaults']['format']);
switch ($fmt) {
    case "jpg":
    case "jpeg":
        header('Content-type: image/jpg');
        $quality = request('quality', $config['defaults']['jpeg_quality']);
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

function pa($a)
{
    echo '<pre>';
    print_r($a);
    echo '</pre>';
}

<?php

class WebMercator {

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
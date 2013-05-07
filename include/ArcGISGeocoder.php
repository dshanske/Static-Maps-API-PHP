<?php

class ArcGISGeocoderResult {
  public $name;
  public $latitude;
  public $longitude;
  public $bounds;
  public $raw;
  public $success = false;

  public function __construct($location, $raw) {
    if($location) {
      $this->name = $location->name;
      $this->latitude = $location->feature->geometry->y;
      $this->longitude = $location->feature->geometry->x;
      $this->raw = $raw;

      $this->bounds = true;
      // $this->bounds = new LatLngBounds();
      // $this->bounds->extend($location->extent->ymin, $location->extent->xmin);
      // $this->bounds->extend($location->extent->ymax, $location->extent->xmax);

      $this->success = true;
    } else {
      $this->raw = $raw;
    }
  }

  public function __get($key) {
    if(!$this->bounds)
      return null;

    switch($key) {
      case 'radius':
        return $this->bounds->radius;
    }
  }
}

class ArcGISGeocoder {
  public static function geocode($input){
    $json = self::_getData($input);

    if($json == false)
      return new ArcGISGeocoderResult(false, $json);
    
    $result = json_decode($json);
    
    if(empty($result->locations))
      return new ArcGISGeocoderResult(false, $json);

    $location = $result->locations[0];

    return new ArcGISGeocoderResult($location, $json);
  }

  
  private static function _getData($input) {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, 'http://geocode.arcgis.com/arcgis/rest/services/World/GeocodeServer/find?f=json&text='.urlencode($input));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    return curl_exec($ch);
  }
}

<?php

/**
 * Geocoding with the ArcGIS World Geocoding Service.
 *
 * Geocoding is being removed (#16); this class only moved here unchanged.
 */

namespace StaticMaps;

/**
 * Looks up a place name or address.
 */
class ArcGISGeocoder
{
    /**
     * Find the first location matching a query.
     *
     * @param string $input Place name or address.
     *
     * @return ArcGISGeocoderResult The result; check its `success` property.
     */
    public static function geocode($input)
    {
        $json = self::getData($input);

        if ($json == false) {
            return new ArcGISGeocoderResult(false, $json);
        }

        $result = json_decode($json);

        if (empty($result->locations)) {
            return new ArcGISGeocoderResult(false, $json);
        }

        $location = $result->locations[0];

        return new ArcGISGeocoderResult($location, $json);
    }

    /**
     * Call the geocoder's `find` endpoint.
     *
     * @param string $input Place name or address.
     *
     * @return string|false The response body, or false on failure.
     */
    private static function getData($input)
    {
        $ch = curl_init();

        curl_setopt(
            $ch,
            CURLOPT_URL,
            'http://geocode.arcgis.com/arcgis/rest/services/World/GeocodeServer/find?f=json&text=' . urlencode($input)
        );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        return curl_exec($ch);
    }
}

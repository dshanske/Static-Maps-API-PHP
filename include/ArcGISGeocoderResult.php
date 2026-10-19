<?php

/**
 * Result of an ArcGIS geocoder lookup.
 *
 * Geocoding is being removed (#16); this class only moved here unchanged.
 */

namespace StaticMaps;

/**
 * The first location found for a query, or a failed lookup.
 */
class ArcGISGeocoderResult
{
    /** @var string|null Name of the location found. */
    public $name;

    /** @var float|null Latitude in degrees. */
    public $latitude;

    /** @var float|null Longitude in degrees. */
    public $longitude;

    /** @var mixed Placeholder for the result's bounds: true on success, never filled in. */
    public $bounds;

    /** @var string|false|null The raw response body. */
    public $raw;

    /** @var bool Whether a location was found. */
    public $success = false;

    /**
     * @param object|false       $location The first entry of the response's `locations`, or false.
     * @param string|false|null  $raw      The raw response body.
     */
    public function __construct($location, $raw)
    {
        if ($location) {
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

    /**
     * Read a derived property (`radius`).
     *
     * @param string $key Property name.
     *
     * @return mixed The value, or null.
     */
    public function __get($key)
    {
        if (!$this->bounds) {
            return null;
        }

        switch ($key) {
            case 'radius':
                return $this->bounds->radius;
        }
    }
}

<?php

/**
 * Bounding box of the markers and paths on a map.
 */

namespace StaticMaps;

/**
 * A latitude/longitude box that grows to include points.
 *
 * It starts "inside out" (min > max), so the first point sets it. Values are
 * kept exactly as given (marker coordinates are strings from the request).
 */
final class Bounds
{
    /** @var mixed Southern edge, latitude in degrees. */
    public mixed $minLat = 90;

    /** @var mixed Northern edge, latitude in degrees. */
    public mixed $maxLat = -90;

    /** @var mixed Western edge, longitude in degrees. */
    public mixed $minLng = 180;

    /** @var mixed Eastern edge, longitude in degrees. */
    public mixed $maxLng = -180;

    /**
     * Grow the box to include a point.
     *
     * @param mixed $lat Latitude in degrees.
     * @param mixed $lng Longitude in degrees.
     *
     * @return void
     */
    public function extend(mixed $lat, mixed $lng): void
    {
        if ($lat < $this->minLat) {
            $this->minLat = $lat;
        }

        if ($lat > $this->maxLat) {
            $this->maxLat = $lat;
        }

        if ($lng < $this->minLng) {
            $this->minLng = $lng;
        }

        if ($lng > $this->maxLng) {
            $this->maxLng = $lng;
        }
    }

    /**
     * Whether no point has been added.
     *
     * @return bool True while the box is still inside out.
     */
    public function isEmpty(): bool
    {
        return $this->minLat > $this->maxLat;
    }

    /**
     * Centre of the box; 0,0 when no point was added.
     *
     * @return array{lat: int|float, lng: int|float} Latitude and longitude in degrees.
     */
    public function center(): array
    {
        return [
            'lat' => $this->minLat + (($this->maxLat - $this->minLat) / 2),
            'lng' => $this->minLng + (($this->maxLng - $this->minLng) / 2),
        ];
    }
}

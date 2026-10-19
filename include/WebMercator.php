<?php

/**
 * Web Mercator (EPSG:3857) pixel and tile maths.
 *
 * @see https://learn.microsoft.com/en-us/bingmaps/articles/bing-maps-tile-system
 * @see https://derickrethans.nl/php-mapping.html
 */

namespace StaticMaps;

/**
 * Converts between latitude/longitude, global pixel coordinates and tiles.
 *
 * Global pixel coordinates count from the north-west corner of the world
 * (lat 85.05°, lng -180°) at the given zoom level, where the world is
 * 2^zoom × TILE_SIZE pixels wide.
 */
class WebMercator
{
    /** @var int Width and height of a tile in pixels. */
    public const TILE_SIZE = 256;

    /**
     * Width (and height) of the whole world in pixels at a zoom level.
     *
     * @param int|float|string $zoom Zoom level.
     *
     * @return int|float Pixels.
     */
    public function totalPixelsForZoomLevel($zoom)
    {
        return pow(2, $zoom) * self::TILE_SIZE;
    }

    /**
     * Global x pixel of a longitude.
     *
     * @param int|float|string $longitude Longitude in degrees.
     * @param int|float|string $zoom      Zoom level.
     *
     * @return float Rounded pixel x.
     */
    public function lngToX($longitude, $zoom)
    {
        return round((($longitude + 180) / 360) * $this->totalPixelsForZoomLevel($zoom));
    }

    /**
     * Global y pixel of a latitude.
     *
     * @param int|float|string $latitude Latitude in degrees.
     * @param int|float|string $zoom     Zoom level.
     *
     * @return float Rounded pixel y (±INF at the poles).
     */
    public function latToY($latitude, $zoom)
    {
        return round(((atanh(sin(deg2rad(-$latitude))) / pi()) + 1) * $this->totalPixelsForZoomLevel($zoom - 1));
    }

    /**
     * Global pixel of a point.
     *
     * @param int|float|string $latitude  Latitude in degrees.
     * @param int|float|string $longitude Longitude in degrees.
     * @param int|float|string $zoom      Zoom level.
     *
     * @return array{x: float, y: float} Rounded pixel coordinates.
     */
    public function latLngToPixels($latitude, $longitude, $zoom)
    {
        return array(
            'x' => $this->lngToX($longitude, $zoom),
            'y' => $this->latToY($latitude, $zoom)
        );
    }

    /**
     * Longitude of a global x pixel.
     *
     * @param int|float $x    Pixel x.
     * @param int|float $zoom Zoom level.
     *
     * @return float Longitude in degrees.
     */
    public function xToLng($x, $zoom)
    {
        return (($x * 360) / $this->totalPixelsForZoomLevel($zoom)) - 180;
    }

    /**
     * Latitude of a global y pixel.
     *
     * @param int|float $y    Pixel y.
     * @param int|float $zoom Zoom level.
     *
     * @return float Latitude in degrees.
     */
    public function yToLat($y, $zoom)
    {
        $a = pi() * (($y / $this->totalPixelsForZoomLevel($zoom - 1)) - 1);
        return -1 * (rad2deg(asin(tanh($a))));
    }

    /**
     * Point at a global pixel.
     *
     * @param int|float $x    Pixel x.
     * @param int|float $y    Pixel y.
     * @param int|float $zoom Zoom level.
     *
     * @return array{lat: float, lng: float|int} Latitude and longitude in degrees.
     */
    public function pixelsToLatLng($x, $y, $zoom)
    {
        return array(
            'lat' => $this->yToLat($y, $zoom),
            'lng' => $this->xToLng($x, $zoom)
        );
    }

    /**
     * Global pixel of a tile's north-west corner.
     *
     * @param int|float $x Tile column.
     * @param int|float $y Tile row.
     *
     * @return array{x: int|float, y: int|float} Pixel coordinates.
     */
    public function tileToPixels($x, $y)
    {
        return array(
            'x' => $x * self::TILE_SIZE,
            'y' => $y * self::TILE_SIZE
        );
    }

    /**
     * Tile that contains a global pixel.
     *
     * @param int|float $x Pixel x.
     * @param int|float $y Pixel y.
     *
     * @return array{x: float, y: float} Tile column and row.
     */
    public function pixelsToTile($x, $y)
    {
        return array(
            'x' => floor($x / self::TILE_SIZE),
            'y' => floor($y / self::TILE_SIZE)
        );
    }

    /**
     * Position of a global pixel inside its tile.
     *
     * @param int|float $x Pixel x.
     * @param int|float $y Pixel y.
     *
     * @return array{x: float, y: float} Pixel offset from the tile's north-west corner.
     */
    public function positionInTile($x, $y)
    {
        $tile = $this->pixelsToTile($x, $y);
        return array(
            'x' => round(self::TILE_SIZE * (($x / self::TILE_SIZE) - $tile['x'])),
            'y' => round(self::TILE_SIZE * (($y / self::TILE_SIZE) - $tile['y']))
        );
    }
}

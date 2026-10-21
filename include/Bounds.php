<?php

/**
 * Bounding box of the markers and paths on a map.
 */

namespace StaticMaps;

/**
 * A latitude/longitude box that grows to include points and spans.
 *
 * It starts "inside out" (min > max), so the first point sets it. Latitudes
 * and the plain `minLng`/`maxLng` are kept exactly as given (marker
 * coordinates are strings from the request).
 *
 * Longitude wraps around at ±180°, so the box's west and east edges come from
 * span(): the shortest stretch of longitude that covers everything added,
 * which may cross the antimeridian (east > 180), e.g. points in Japan and
 * Alaska (#35).
 */
final class Bounds
{
    /**
     * @var float Longitudes this close (in degrees) count as the same when
     *            stretches are joined: a segment across the antimeridian ends at
     *            `from + delta()`, which can miss the next point + 360 by a rounding
     *            error (#93).
     */
    private const EPSILON = 1e-9;

    /** @var mixed Southern edge, latitude in degrees. */
    public mixed $minLat = 90;

    /** @var mixed Northern edge, latitude in degrees. */
    public mixed $maxLat = -90;

    /** @var mixed Lowest longitude added, in degrees (ignores wrapping; see span()). */
    public mixed $minLng = 180;

    /** @var mixed Highest longitude added, in degrees (ignores wrapping; see span()). */
    public mixed $maxLng = -180;

    /** @var list<array{float, float}> Longitude stretches added: [west, east], west in -180..180, east >= west. */
    private array $spans = [];

    /**
     * Grow the box to include a point.
     *
     * @param mixed $lat Latitude in degrees.
     * @param mixed $lng Longitude in degrees (any value; wrapped into -180..180 for span()).
     *
     * @return void
     */
    public function extend(mixed $lat, mixed $lng): void
    {
        $this->extendLat($lat);

        if ($lng < $this->minLng) {
            $this->minLng = $lng;
        }

        if ($lng > $this->maxLng) {
            $this->maxLng = $lng;
        }
        $this->addSpan((float) $lng, (float) $lng);
    }

    /**
     * Grow the box to include an area from `west` eastwards to `east`.
     *
     * @param float $minLat Southern edge in degrees.
     * @param float $west   Western edge, longitude in degrees.
     * @param float $maxLat Northern edge in degrees.
     * @param float $east   Eastern edge in degrees; less than `west` means the area crosses
     *                      the antimeridian (e.g. west 170, east -170 is 20° wide).
     *
     * @return void
     */
    public function extendArea(float $minLat, float $west, float $maxLat, float $east): void
    {
        $this->extend($minLat, $west);
        $this->extend($maxLat, $east);
        $this->addSpan($west, $east < $west ? $east + 360 : $east);
    }

    /**
     * Grow the box to include a straight path, each segment taking the short way
     * round (less than 180° of longitude), as it is drawn.
     *
     * @param list<array{mixed, mixed}> $points The path's [lng, lat] points in degrees.
     *
     * @return void
     */
    public function extendPath(array $points): void
    {
        $previous = null;
        foreach ($points as $point) {
            $this->extend($point[1], $point[0]);
            if ($previous !== null) {
                $from = (float) $previous[0];
                $to = $from + self::delta($from, (float) $point[0]);
                $this->addSpan(min($from, $to), max($from, $to));
            }
            $previous = $point;
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
     * The shortest stretch of longitude covering everything added.
     *
     * @return array{float, float} West and east edges in degrees: west in -180..180 and
     *         east >= west, so east > 180 when the stretch crosses the antimeridian.
     *         [-180, 180] when everything together goes all the way round; [0, 0] when empty.
     */
    public function span(): array
    {
        if ($this->spans === []) {
            return [0.0, 0.0];
        }

        // Merge overlapping stretches, sorted by their western edge.
        $spans = $this->spans;
        usort($spans, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($spans as [$west, $east]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $west <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $east);
            } else {
                $merged[] = [$west, $east];
            }
        }

        // A stretch running past 180 may cover ones at the start of the list
        // (their longitude + 360): fold those into it.
        while (count($merged) > 1 && $merged[0][0] + 360 <= $merged[count($merged) - 1][1] + self::EPSILON) {
            $first = array_shift($merged);
            $merged[count($merged) - 1][1] = max($merged[count($merged) - 1][1], $first[1] + 360);
        }
        if ($merged[count($merged) - 1][1] - $merged[0][0] >= 360) {
            return [-180.0, 180.0];
        }

        // The answer is everything but the widest gap. The gap round the back
        // (from the last stretch's east edge to the first one's west edge, 360°
        // later) wins ties, so a map that doesn't need to cross the antimeridian doesn't.
        $last = count($merged) - 1;
        $best = [$merged[0][0], $merged[$last][1]];
        $widest = $merged[0][0] + 360 - $merged[$last][1];
        for ($i = 0; $i < $last; $i++) {
            $gap = $merged[$i + 1][0] - $merged[$i][1];
            if ($gap > $widest) {
                $widest = $gap;
                $best = [$merged[$i + 1][0], $merged[$i][1] + 360];
            }
        }

        return $widest <= 0 ? [-180.0, 180.0] : $best;
    }

    /**
     * Centre of the box; 0,0 when no point was added.
     *
     * @return array{lat: int|float, lng: int|float} Latitude and longitude in degrees
     *         (longitude in -180..180).
     */
    public function center(): array
    {
        if ($this->isEmpty()) {
            return ['lat' => 0, 'lng' => 0];
        }
        [$west, $east] = $this->span();

        return [
            'lat' => $this->minLat + (($this->maxLat - $this->minLat) / 2),
            'lng' => self::wrap($west + ($east - $west) / 2),
        ];
    }

    /**
     * Wrap a longitude into -180..180.
     *
     * @param float $lng Longitude in degrees, any value.
     *
     * @return float The same meridian, -180 to 180 (180 stays 180).
     */
    public static function wrap(float $lng): float
    {
        if ($lng >= -180 && $lng <= 180) {
            return $lng;
        }

        return fmod(fmod($lng + 180, 360) + 360, 360) - 180;
    }

    /**
     * The short way from one longitude to another.
     *
     * @param float $from Start longitude in degrees.
     * @param float $to   End longitude in degrees.
     *
     * @return float Degrees to go east (negative: west), -180 to 180.
     */
    public static function delta(float $from, float $to): float
    {
        $d = fmod($to - $from, 360);
        if ($d > 180) {
            $d -= 360;
        } elseif ($d < -180) {
            $d += 360;
        }

        return $d;
    }

    /**
     * Grow the latitude range.
     *
     * @param mixed $lat Latitude in degrees.
     *
     * @return void
     */
    private function extendLat(mixed $lat): void
    {
        if ($lat < $this->minLat) {
            $this->minLat = $lat;
        }

        if ($lat > $this->maxLat) {
            $this->maxLat = $lat;
        }
    }

    /**
     * Record a stretch of longitude.
     *
     * @param float $west Western edge in degrees, any value.
     * @param float $east Eastern edge in degrees, >= west.
     *
     * @return void
     */
    private function addSpan(float $west, float $east): void
    {
        if ($east - $west >= 360) {
            $this->spans[] = [-180.0, 180.0];
            return;
        }
        $start = self::wrap($west);
        if ($start === 180.0) {
            $start = -180.0;
        }
        $this->spans[] = [$start, $start + ($east - $west)];
    }
}

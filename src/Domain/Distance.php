<?php

declare(strict_types=1);

namespace Canpas\Fleet\Domain;

use Canpas\Fleet\Support\DomainError;

/**
 * Round-trip road distance from the Canpas yard to a site and back.
 *
 * Uses the Google Routes API (computeRouteMatrix) when the server key is set in wp-config.php
 * as CANPAS_GOOGLE_SERVER_KEY. Results are cached for 30 days per site (rounded to ~11 m), so
 * repeat quotes cost nothing. Without a key, or if Google fails, it falls back to a clearly
 * marked estimate: straight-line distance × 1.3 each way. Estimates are flagged on the order
 * and the final bill uses the odometer anyway.
 */
final class Distance
{
    public const ROAD_FACTOR = 1.3;

    /** @return array{km:float, source:string} source: routes | estimate */
    public static function roundTrip(float $lat, float $lng): array
    {
        self::assertCoords($lat, $lng, 'site');
        $yardLat = Settings::get('yard_lat');
        $yardLng = Settings::get('yard_lng');
        if ($yardLat === '' || $yardLng === '' || $yardLat === null || $yardLng === null) {
            throw new DomainError('yard_not_set', 'Canpas has not set the yard location yet. Please contact Canpas.');
        }
        $yardLat = (float) $yardLat;
        $yardLng = (float) $yardLng;

        $key = 'cf_dist_' . md5(sprintf('%.4f,%.4f|%.4f,%.4f', $yardLat, $yardLng, $lat, $lng));
        $cached = get_transient($key);
        if (is_array($cached) && isset($cached['km'], $cached['source'])) {
            return $cached;
        }

        $result = null;
        if (defined('CANPAS_GOOGLE_SERVER_KEY') && CANPAS_GOOGLE_SERVER_KEY !== '') {
            $result = self::google($yardLat, $yardLng, $lat, $lng);
        }
        $result ??= ['km' => round(2 * self::ROAD_FACTOR * self::haversine($yardLat, $yardLng, $lat, $lng), 1), 'source' => 'estimate'];
        set_transient($key, $result, $result['source'] === 'routes' ? 30 * DAY_IN_SECONDS : HOUR_IN_SECONDS);
        return $result;
    }

    /** Parse "14.4296, 120.9367" (as copied from Google Maps) into [lat, lng]. */
    public static function parseCoords(string $text): array
    {
        if (!preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*[, ]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $text, $m)) {
            throw new DomainError('invalid_input', 'Paste the site location as two numbers, e.g. 14.4296, 120.9367.', ['field' => 'site_coords']);
        }
        $lat = (float) $m[1];
        $lng = (float) $m[2];
        self::assertCoords($lat, $lng, 'site');
        return [$lat, $lng];
    }

    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private static function assertCoords(float $lat, float $lng, string $what): void
    {
        // Philippines bounding box (with margin): Canpas only delivers in-country.
        if ($lat < 4 || $lat > 22 || $lng < 115 || $lng > 128) {
            throw new DomainError('outside_service_area', "The {$what} location is outside the Philippines. Check the coordinates.", ['field' => 'site_coords']);
        }
    }

    /** @return array{km:float, source:string}|null */
    private static function google(float $yLat, float $yLng, float $sLat, float $sLng): ?array
    {
        $wp = static fn(float $a, float $b): array => ['waypoint' => ['location' => ['latLng' => ['latitude' => $a, 'longitude' => $b]]]];
        $resp = wp_remote_post('https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix', [
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Goog-Api-Key' => CANPAS_GOOGLE_SERVER_KEY,
                'X-Goog-FieldMask' => 'originIndex,destinationIndex,distanceMeters,condition',
            ],
            'body' => wp_json_encode([
                'origins' => [$wp($yLat, $yLng), $wp($sLat, $sLng)],
                'destinations' => [$wp($sLat, $sLng), $wp($yLat, $yLng)],
                'travelMode' => 'DRIVE',
            ]),
        ]);
        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
            return null;
        }
        $rows = json_decode(wp_remote_retrieve_body($resp), true);
        $out = $back = null;
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (($row['condition'] ?? '') !== 'ROUTE_EXISTS' || !isset($row['distanceMeters'])) {
                continue;
            }
            $o = (int) ($row['originIndex'] ?? -1);
            $d = (int) ($row['destinationIndex'] ?? -1);
            if ($o === 0 && $d === 0) {
                $out = (int) $row['distanceMeters'];
            } elseif ($o === 1 && $d === 1) {
                $back = (int) $row['distanceMeters'];
            }
        }
        if ($out === null || $back === null) {
            return null;
        }
        return ['km' => round(($out + $back) / 1000, 1), 'source' => 'routes'];
    }
}

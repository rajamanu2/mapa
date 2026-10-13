<?php
namespace App\MapaMobile;

/** A provider-reported coordinate is not a measured accuracy or proof of crew presence. */
final class Way4TrackFixPolicy
{
    public static function fresh(array $fix, int $now, int $previous = 0): bool
    {
        if (($fix['source_protocol'] ?? null) !== 'way4track'
            || ($fix['position_quality'] ?? null) !== 'provider_reported'
            || !array_key_exists('accuracy_m', $fix) || $fix['accuracy_m'] !== null
            || ($fix['gps_valid'] ?? null) !== null || ($fix['satellites'] ?? null) !== null) return false;
        foreach (['latitude','longitude'] as $key) {
            if (!isset($fix[$key]) || is_bool($fix[$key]) || !is_numeric($fix[$key]) || !is_finite((float)$fix[$key])) return false;
        }
        foreach (['recorded_at','provider_received_at'] as $key) {
            if (!isset($fix[$key]) || !is_numeric($fix[$key]) || is_bool($fix[$key])
                || !is_finite((float)$fix[$key]) || (float)$fix[$key] !== (float)(int)$fix[$key]) return false;
        }
        $recorded = (int)$fix['recorded_at'];
        $received = (int)$fix['provider_received_at'];
        return abs((float)$fix['latitude']) <= 90 && abs((float)$fix['longitude']) <= 180
            && !((float)$fix['latitude'] === 0.0 && (float)$fix['longitude'] === 0.0)
            && $recorded >= $now - 300000 && $recorded <= $now + 30000 && $recorded > $previous
            && $received >= $recorded - 30000 && $received <= $now + 30000;
    }
}

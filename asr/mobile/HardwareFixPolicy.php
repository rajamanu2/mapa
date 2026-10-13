<?php
namespace App\MapaMobile;

/** Hardware evidence is distinct from a phone's measured horizontal accuracy. */
final class HardwareFixPolicy
{
    public static function fresh(array $fix, int $now, int $previous = 0): bool
    {
        if (($fix['source_protocol'] ?? null) === 'way4track') return Way4TrackFixPolicy::fresh($fix, $now, $previous);
        if (($fix['source_protocol'] ?? null) !== 'gt06') return Policy::freshFix($fix, $now, $previous);
        if (!in_array($fix['gps_valid'] ?? null, [true, 1], true)) return false;
        $sat = $fix['satellites'] ?? null;
        if (!is_numeric($sat) || (int)$sat != $sat || $sat < 4 || $sat > 15) return false;
        foreach (['latitude', 'longitude', 'recorded_at'] as $key) {
            if (!isset($fix[$key]) || !is_numeric($fix[$key]) || !is_finite((float)$fix[$key])) return false;
        }
        $accuracy = $fix['accuracy_m'] ?? null;
        if ($accuracy !== null && (!is_numeric($accuracy) || !is_finite((float)$accuracy) || $accuracy <= 0 || $accuracy > 100)) return false;
        return abs((float)$fix['latitude']) <= 90 && abs((float)$fix['longitude']) <= 180
            && !((float)$fix['latitude'] === 0.0 && (float)$fix['longitude'] === 0.0)
            && (int)$fix['recorded_at'] >= $now - 300000 && (int)$fix['recorded_at'] <= $now + 30000
            && (int)$fix['recorded_at'] > $previous;
    }

    public static function same(array $fix, object $last): bool
    {
        foreach (['latitude','longitude','accuracy_m','recorded_at','source_protocol','gps_valid','satellites'] as $key) {
            $a = $fix[$key] ?? null; $b = $last->$key ?? null;
            // The existing latest-position accuracy column is MySQL FLOAT.
            if ($key==='accuracy_m' && $a!==null && $b!==null && abs((float)$a-(float)$b)<0.00001) continue;
            if (($a === null) !== ($b === null) || ($a !== null && $a != $b)) return false;
        }
        return true;
    }
}

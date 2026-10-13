<?php
namespace App\MapaVoice;

/** The deadline cascades to another unit; only a valid first acceptance assigns. */
final class AutomaticDispatchPolicy
{
    public const MAX_OFFERS = 5;
    public const MAX_WORKER_SILENCE_SECONDS = 90;
    public const MAX_OVERDUE_RESPONSE_SECONDS = 90;

    /** Search 0–500 m first, then successive 1 km bands around the pickup. */
    public static function radiusBand(mixed $distanceKm): ?int
    {
        if (!is_numeric($distanceKm) || !is_finite((float)$distanceKm) || (float)$distanceKm < 0) return null;
        $meters = (float)$distanceKm * 1000;
        return $meters <= 500 ? 500 : 500 + (int)ceil(($meters - 500) / 1000) * 1000;
    }

    public static function rankByRadius(array $candidates): array
    {
        $candidates = array_values(array_filter($candidates, static fn($c) =>
            self::radiusBand($c['distance_km'] ?? null) !== null
            && is_numeric($c['road_seconds'] ?? null) && is_finite((float)$c['road_seconds']) && $c['road_seconds'] >= 0));
        usort($candidates, static fn($a, $b) =>
            (self::radiusBand($a['distance_km']) <=> self::radiusBand($b['distance_km']))
            ?: ($a['road_seconds'] <=> $b['road_seconds'])
            ?: (($a['distance_km'] <=> $b['distance_km']) ?: strcmp($a['id'], $b['id'])));
        return $candidates;
    }
    public static function accepts(string $state, int $expires, int $now): bool
    {
        return in_array($state,['offered','overdue'],true)
            && $expires >= $now - self::MAX_OVERDUE_RESPONSE_SECONDS;
    }
    public static function workerCurrent(int $lastUpdate, int $now): bool
    {
        return $lastUpdate >= $now - self::MAX_WORKER_SILENCE_SECONDS && $lastUpdate <= $now + 5;
    }
    /** A declined offer must leave a usable operational reason for control. */
    public static function validResponseDetails(array $body): bool
    {
        if (($body['response']??null)!=='decline') return true;
        $reason=$body['decline_reason']??null;
        $detail=$body['decline_detail']??'';
        return is_string($reason)&&in_array($reason,['unavailable','vehicle_issue','already_busy','location_issue','other'],true)
            &&is_string($detail)&&strlen($detail)<=2000&&($reason!=='other'||trim($detail)!=='');
    }
    public static function rank(array $candidates): array
    {
        $candidates=array_values(array_filter($candidates, static fn($c)=>
            is_numeric($c['road_seconds']??null) && is_finite((float)$c['road_seconds']) && $c['road_seconds']>=0));
        usort($candidates,static fn($a,$b)=>($a['road_seconds']<=>$b['road_seconds'])?:strcmp($a['id'],$b['id']));
        return $candidates;
    }
    public static function sourceOwned(mixed $meta): bool
    {
        $m=Policy::meta($meta);
        return isset($m['asr_import']) || isset($m['asr_source_id']) || isset($m['mapa_source_id']);
    }
    public static function response(string $state,int $created,int $expires,?int $responded,int $now): array
    {
        $awaiting=in_array($state,['offered','overdue'],true);$timedOut=$state==='expired'||($awaiting&&$expires<=$now);
        return ['response_seconds'=>in_array($state,['accepted','declined'],true)&&$responded!==null?max(0,$responded-$created):null,
            'remaining_seconds'=>$awaiting?max(0,$expires-$now):0,'no_response'=>$timedOut,
            'lateness_seconds'=>max(0,($responded??$now)-$expires),
            'is_late_response'=>in_array($state,['accepted','declined'],true)&&$responded!==null&&$responded>$expires,
            'response_status'=>$awaiting&&$timedOut?'overdue':($timedOut?'no_response':($awaiting?'awaiting_response':$state))];
    }
}

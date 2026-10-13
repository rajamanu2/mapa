<?php
namespace App\MapaMobile;

/** Explicit caller confirmation is a fixed candidate, separate from the live GPS stream. */
final class CallerPickupEvidence
{
    public static function fix(array $value): array {
        return ['latitude'=>round((float)$value['latitude'],7),'longitude'=>round((float)$value['longitude'],7),
            'accuracy_m'=>round((float)$value['accuracy_m'],3),'recorded_at'=>(int)$value['recorded_at']];
    }
    public static function same(array $a,array $b): bool { return self::fix($a)===self::fix($b); }
    public static function read(?object $row,int $now): ?array {
        $raw=$row->pickup_confirmation??null;
        $value=is_string($raw)?json_decode($raw,true):$raw;
        if(!is_array($value)||!is_int($value['confirmed_at']??null)||$value['confirmed_at']<=0
            ||!is_int($value['recorded_at']??null)||$value['recorded_at']<=0
            ||!ConnectedTrackingPolicy::fix($value,$value['recorded_at']))return null;
        $fix=self::fix($value);
        return [...$fix,'confirmed_at'=>$value['confirmed_at'],'source'=>'caller_confirmed','stale'=>!ConnectedTrackingPolicy::fix($fix,$now)];
    }
}

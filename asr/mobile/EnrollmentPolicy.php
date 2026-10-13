<?php
namespace App\MapaMobile;

/** Pure enrollment/evidence checks. Device declarations are not identity attestation. */
final class EnrollmentPolicy
{
    public const MAX_BODY = 3145728;
    public const MAX_JPEG = 2097152;
    public static function token(mixed $token): bool { return is_string($token) && preg_match('/^[a-f0-9]{64}$/D',$token)===1; }
    public static function capture(mixed $time,int $now,int $age=120000): bool {
        return is_numeric($time) && is_finite((float)$time) && (int)$time >= $now-$age && (int)$time <= $now+30000;
    }
    public static function gps(array $fix,int $now): bool {
        return ($fix['mock_location']??null)===false && Policy::freshFix($fix,$now,0)
            && self::capture($fix['recorded_at']??null,$now) && !(abs((float)$fix['latitude'])<0.000001 && abs((float)$fix['longitude'])<0.000001);
    }
    public static function evidence(array $body,array $gps,int $now): bool {
        if(!is_string($body['completion_note']??null) || trim($body['completion_note'])==='' || strlen($body['completion_note'])>2000
            || !self::capture($body['photo_captured_at']??null,$now) || !self::gps($gps,$now)
            || !self::capture($gps['received_at']??null,$now) || $gps['recorded_at']<(int)$body['photo_captured_at']-30000
            || $gps['received_at']<(int)$body['photo_captured_at']-30000) return false;
        foreach(['latitude','longitude','recorded_at','accuracy_m','mock_location'] as $key)
            if(array_key_exists($key,$body) && $body[$key]!==$gps[$key] && (string)$body[$key] !== (string)$gps[$key]) return false;
        return true;
    }
}

<?php
namespace App\MapaVoice;
/** MAPA.DEV. Pure policy helpers; transcripts are always untrusted suggestions. */
final class Policy
{
    public const CONTRACT='mapa-native-voice-v1';
    public const TERMINAL_ORDERS=['completed','canceled','cancelled','failed'];
    public static function canonical($value) {
        if(!is_array($value))return $value;
        if(!array_is_list($value))ksort($value);
        return array_map([self::class,'canonical'],$value);
    }
    public static function fingerprint(string $scope,array $body): string {return hash('sha256',$scope.':'.json_encode(self::canonical($body),JSON_THROW_ON_ERROR));}
    public static function uuid($v): bool {return is_string($v)&&preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',$v)===1;}
    public static function meta($v): array {if(is_array($v))return $v; $m=json_decode($v??'{}',true);return is_array($m)?$m:[];}
    public static function demo($v): bool {return (self::meta($v)['mapa_demo']??false)===true;}
    public static function distance(float $a,float $b,float $c,float $d): float {
        $x=sin(deg2rad($c-$a)/2)**2+cos(deg2rad($a))*cos(deg2rad($c))*sin(deg2rad($d-$b)/2)**2;
        return 6371*2*atan2(sqrt(min(1,max(0,$x))),sqrt(max(0,1-$x)));
    }
    public static function coordinates($lat,$lng): bool {return is_numeric($lat)&&is_numeric($lng)&&is_finite((float)$lat)&&is_finite((float)$lng)&&$lat>=-90&&$lat<=90&&$lng>=-180&&$lng<=180&&!((float)$lat===0.0&&(float)$lng===0.0);}
    public static function fresh($at,int $now,int $age): bool {return is_numeric($at)&&$at>$now-$age*1000&&$at<=$now+30000;}
    public static function allowedResource(bool $demo,$driverMeta,$vehicleMeta): bool {
        return $demo ? self::demo($driverMeta)&&self::demo($vehicleMeta) : !self::demo($driverMeta)&&!self::demo($vehicleMeta)
            &&!(self::meta($driverMeta)['simulated']??false)&&!(self::meta($vehicleMeta)['simulated']??false);
    }
}

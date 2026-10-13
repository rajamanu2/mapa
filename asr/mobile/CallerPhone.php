<?php
namespace App\MapaMobile;

/** Optional operator-entered callback. Formatting never verifies number ownership. */
final class CallerPhone
{
    public const KEY='mapa_caller_phone';
    public const ERROR='Enter a valid caller phone number: 10 Indian digits or an international number beginning with +. Leave it blank when unknown.';
    public static function normalize(mixed $value): string
    {
        if($value===null)return '';
        if(!is_string($value)||strlen($value)>40||!preg_match('/^[+0-9 ()-]*$/D',$value))throw new \InvalidArgumentException(self::ERROR);
        if(trim($value,' ')==='')return '';
        $number=str_replace([' ','(',')','-'],'',$value);
        if(preg_match('/^[1-9][0-9]{9}$/D',$number))$number='+91'.$number;
        elseif(preg_match('/^91[1-9][0-9]{9}$/D',$number))$number='+'.$number;
        if(!preg_match('/^\+[1-9][0-9]{7,14}$/D',$number))throw new \InvalidArgumentException(self::ERROR);
        return $number;
    }
    public static function dispatchDetails(mixed $meta): array
    {
        $meta=is_object($meta)?(array)$meta:(is_array($meta)?$meta:[]);
        $review=$meta['mapa_dispatch_review']??[];$review=is_object($review)?(array)$review:(is_array($review)?$review:[]);
        $details=array_intersect_key($review,array_flip(['reported_problem','landmark','village','mandal','access_directions','patient_count','priority','vehicle_requirement','consciousness','breathing','scene_safety']));
        // Presence is intentional: an explicit clear must not revive an old review number.
        $value=array_key_exists(self::KEY,$meta)?$meta[self::KEY]:($review['callback_number']??null);
        try{$number=self::normalize($value);}catch(\InvalidArgumentException $e){$number='';}
        if($number!=='')$details['callback_number']=$number;
        return $details;
    }
    public static function publicMeta(mixed $meta): mixed
    {
        if(!is_array($meta)&&!is_object($meta))return $meta;
        $object=is_object($meta);$out=(array)$meta;unset($out[self::KEY]);
        if(isset($out['mapa_dispatch_review'])&&(is_array($out['mapa_dispatch_review'])||is_object($out['mapa_dispatch_review']))){
            $reviewObject=is_object($out['mapa_dispatch_review']);$review=(array)$out['mapa_dispatch_review'];unset($review['callback_number']);$out['mapa_dispatch_review']=$reviewObject?(object)$review:$review;
        }
        return $object?(object)$out:$out;
    }
    public static function operatorRoute(string $action): bool
    {
        $controller=explode('@',$action,2)[0];
        return in_array($controller,[
            'Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1\\OrderController',
            'Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1\\DriverController',
            'Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1\\VehicleController',
        ],true);
    }
}

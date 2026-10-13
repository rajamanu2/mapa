<?php
namespace App\MapaVoice;

/** Observations only. Missing evidence is never a zero response time or a stationary vehicle. */
final class CommandDeskPolicy
{
    public static function meta($value): array {
        if(is_array($value))return $value;
        try{$v=json_decode((string)$value,true,32,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}catch(\Throwable $e){return [];}
    }
    public static function milliseconds($value): ?int {
        if($value===null||$value==='')return null;
        if(is_int($value)||is_float($value))return is_finite((float)$value)&&$value>0?(int)$value:null;
        if(!is_string($value)||!preg_match('/^\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d/',$value))return null;
        try{return (new \DateTimeImmutable($value,new \DateTimeZone('UTC')))->getTimestamp()*1000;}catch(\Throwable $e){return null;}
    }
    public static function assignmentHash(array $attributes): string {
        $sort=function($v)use(&$sort){if(!is_array($v))return $v;if(!array_is_list($v))ksort($v);return array_map($sort,$v);};
        return hash('sha256',json_encode($sort($attributes),JSON_THROW_ON_ERROR));
    }
    /** Stage labels use native status and scoped arrival/crew evidence, never elapsed time. */
    public static function tripProgress(?object $order,?object $driver,?object $vehicle,?int $ack,int $now): array {
        $status=$order?->status;$meta=self::meta($order?->meta??null);
        $accepted=$order&&$driver&&$vehicle&&$ack&&$ack<=$now&&$driver->vehicle_uuid===$vehicle->uuid;
        $pickup=$accepted?self::reportedArrival($meta['mapa_mobile_arrivals']['pickup_arrived']??null,$order->company_uuid,$order->uuid,$driver->uuid,$vehicle->uuid,$ack,$now,'pickup_arrived'):null;
        $hospital=$accepted?self::reportedArrival($meta['mapa_mobile_arrivals']['hospital_arrived']??null,$order->company_uuid,$order->uuid,$driver->uuid,$vehicle->uuid,$ack,$now):null;
        $onboard=$meta['mapa_mobile_trip']['enroute']??null;
        $verifiedOnboard=$accepted&&is_array($onboard)&&($onboard['source']??null)==='mapa_crew'&&($onboard['crew_confirmed']??null)===true
            &&($onboard['driver_id']??null)===$driver->public_id&&($onboard['vehicle_id']??null)===$vehicle->public_id
            &&is_int($onboard['recorded_at']??null)&&$onboard['recorded_at']>=$ack&&$onboard['recorded_at']<=$now;
        return ['native_status'=>$status,'accepted'=>(bool)$accepted,'pickup_arrived'=>$pickup!==null,'pickup_arrived_at'=>$pickup,
            'hospital_arrived'=>$hospital!==null,'hospital_arrived_at'=>$hospital,'patient_onboard'=>$verifiedOnboard?true:null,
            'completed'=>$status==='completed','arrival_basis'=>'current_assignment_crew_reported_evidence'];
    }
    public static function offers(array $offers,int $now): array {
        $out=['offers_received'=>count($offers),'accepted_offers'=>0,'declined_offers'=>0,'expired_offers'=>0,'overdue_offers'=>0,'pending_offers'=>0,'cancelled_offers'=>0,'unknown_offers'=>0];$responses=[];
        foreach($offers as $offer){
            $state=$offer['state']??null;$expiry=self::milliseconds($offer['expires_at']??null);
            if($state==='offered'&&$expiry!==null&&$expiry<=$now)$state='overdue';
            $key=['accepted'=>'accepted_offers','declined'=>'declined_offers','expired'=>'expired_offers','overdue'=>'overdue_offers','offered'=>'pending_offers','cancelled'=>'cancelled_offers'][$state??'']??'unknown_offers';$out[$key]++;
            if(in_array($state,['accepted','declined'],true)){
                $from=self::milliseconds($offer['created_at']??null);$to=self::milliseconds($offer['responded_at']??null);
                if($from!==null&&$to!==null&&$to>=$from&&$to<=$now)$responses[]=($to-$from)/1000;
            }
        }
        $denominator=$out['accepted_offers']+$out['declined_offers']+$out['expired_offers'];
        return $out+['no_response_offers'=>$out['expired_offers']+$out['overdue_offers'],'acceptance_denominator'=>$denominator,'acceptance_rate'=>$denominator?round(100*$out['accepted_offers']/$denominator,1):null,
            'response_samples'=>count($responses),'average_response_seconds'=>$responses?round(array_sum($responses)/count($responses),1):null];
    }
    private static function validFix(array $fix,int $now): bool {
        if(($fix['source']??null)!=='device_gps'||($fix['mock_location']??null)!==false||($fix['demo']??false)===true||($fix['simulated']??false)===true)return false;
        foreach(['latitude'=>90,'longitude'=>180] as $field=>$bound)if(!is_numeric($fix[$field]??null)||!is_finite((float)$fix[$field])||abs((float)$fix[$field])>$bound)return false;
        return is_numeric($fix['accuracy_m']??null)&&$fix['accuracy_m']>=0&&$fix['accuracy_m']<=50
            &&is_int($fix['recorded_at']??null)&&is_int($fix['received_at']??null)&&$fix['recorded_at']>0
            &&$fix['recorded_at']<=$now&&$fix['received_at']<=$now&&$fix['received_at']>=$fix['recorded_at']
            &&$fix['received_at']-$fix['recorded_at']<=120000;
    }
    private static function distance(array $a,array $b): float {
        $lat=deg2rad($b['latitude']-$a['latitude']);$lon=deg2rad($b['longitude']-$a['longitude']);
        $h=sin($lat/2)**2+cos(deg2rad($a['latitude']))*cos(deg2rad($b['latitude']))*sin($lon/2)**2;
        return 6371000*2*asin(sqrt(min(1,max(0,$h))));
    }
    public static function movement(?int $acceptedAt,array $fixes,int $now,bool $truncated=false): array {
        $unknown=['state'=>'unknown','reason'=>'missing_acceptance_or_gps','distance_m'=>null,'elapsed_seconds'=>null,'review_after_seconds'=>null];
        if($acceptedAt===null||$acceptedAt>$now)return $unknown;
        $unknown['elapsed_seconds']=(int)(($now-$acceptedAt)/1000);
        $valid=array_values(array_filter($fixes,fn($f)=>is_array($f)&&self::validFix($f,$now)));
        usort($valid,fn($a,$b)=>$a['recorded_at']<=>$b['recorded_at']);$last=$valid?end($valid):null;
        if(!$last||$now-$last['recorded_at']>60000||$now-$last['received_at']>60000)return array_replace($unknown,['reason'=>'gps_stale_or_missing']);
        $baseline=null;foreach($valid as $fix)if(abs($fix['recorded_at']-$acceptedAt)<=30000){$baseline=$fix;break;}
        if(!$baseline)return array_replace($unknown,['reason'=>'acceptance_location_unavailable']);
        $distance=self::distance($baseline,$last);$uncertainty=$baseline['accuracy_m']+$last['accuracy_m'];
        if($last['recorded_at']>$acceptedAt&&$distance-$uncertainty>=200)return array_replace($unknown,['state'=>'movement_observed','reason'=>null,'distance_m'=>round($distance)]);
        $previous=$baseline['recorded_at'];$maxDistance=0;
        foreach($valid as $fix){if($fix['recorded_at']<$previous)continue;if($fix['recorded_at']-$previous>90000)return array_replace($unknown,['reason'=>'gps_gap']);
            $maxDistance=max($maxDistance,self::distance($baseline,$fix)+$baseline['accuracy_m']+$fix['accuracy_m']);$previous=$fix['recorded_at'];}
        if($truncated||$maxDistance>=200)return array_replace($unknown,['reason'=>$truncated?'gps_history_truncated':'movement_uncertain']);
        $elapsed=$unknown['elapsed_seconds'];$threshold=$elapsed>=300?300:($elapsed>=240?240:($elapsed>=180?180:($elapsed>=120?120:null)));
        return array_replace($unknown,['state'=>$threshold===null?'awaiting_movement':'movement_not_observed','reason'=>null,'distance_m'=>round($distance),'review_after_seconds'=>$threshold]);
    }
    public static function closure(string $stage,?int $arrivedAt,int $now): array {
        if(in_array($stage,['completed','canceled','cancelled'],true))return ['state'=>'closed','elapsed_seconds'=>null];
        if($arrivedAt===null||$arrivedAt>$now)return ['state'=>'unknown','elapsed_seconds'=>null];
        $seconds=(int)(($now-$arrivedAt)/1000);
        return ['state'=>$seconds>=900?'review_overdue':($seconds>=600?'review_delay':($seconds>=300?'reminder':'awaiting_handover')),'elapsed_seconds'=>$seconds];
    }
    public static function reportedArrival($event,string $company,string $order,?string $driver,?string $vehicle,?int $ack,int $now,string $code='hospital_arrived'): ?int {
        if(!in_array($code,['pickup_arrived','hospital_arrived'],true)||!is_array($event)||!$driver||!$vehicle||!$ack||($event['code']??null)!==$code
            ||($event['source']??null)!=='crew_reported_arrival'||($event['confirmed']??null)!==true||($event['native_stage']??null)!==($code==='hospital_arrived'?'enroute':'started')
            ||($event['company_uuid']??null)!==$company||($event['order_uuid']??null)!==$order||($event['driver_uuid']??null)!==$driver
            ||($event['vehicle_uuid']??null)!==$vehicle||($event['acknowledged_at']??null)!==$ack
            ||!is_int($event['recorded_at']??null)||$event['recorded_at']<$ack||$event['recorded_at']>$now)return null;
        return $event['recorded_at'];
    }
}

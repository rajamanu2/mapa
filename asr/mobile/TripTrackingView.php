<?php
namespace App\MapaMobile;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A single read-only projection; callers must authorize the order before use. */
final class TripTrackingView
{
    private static function native(string $model,string $company) {
        return $model::withoutGlobalScopes()->where('company_uuid',$company)->whereNull('deleted_at');
    }
    private static function place(?string $uuid,string $company): ?array {
        $p=$uuid?self::native(Place::class,$company)->where('uuid',$uuid)->first():null;
        if(!$p)return null;
        $lat=$p->location?->getLat();$lng=$p->location?->getLng();
        $valid=is_numeric($lat)&&is_numeric($lng)&&is_finite((float)$lat)&&is_finite((float)$lng)
            &&abs((float)$lat)<=90&&abs((float)$lng)<=180&&!((float)$lat===0.0&&(float)$lng===0.0);
        return ['label'=>(string)$p->name,'latitude'=>$valid?(float)$lat:null,'longitude'=>$valid?(float)$lng:null];
    }
    public static function forOrder(?string $uuid,string $company,?Order $authorizedOrder=null,?Place $authorizedDestination=null): ?array {
        $now=(int)round(microtime(true)*1000);
        // Keep the assignment already authorized by the caller. Re-querying here
        // could substitute a newly assigned crew/vehicle during an in-flight read.
        $order=$authorizedOrder??($uuid?self::native(Order::class,$company)->where('uuid',$uuid)->first():null);
        if($order&&($order->uuid!==$uuid||$order->company_uuid!==$company||$order->deleted_at))return null;
        $out=['stage'=>'awaiting_assignment','leg'=>null,'caller_location_allowed'=>true,
            'pickup'=>null,'destination'=>null,'ambulance'=>null,'eta'=>null,'planned_road_route'=>null,'server_time'=>$now];
        if(!$order)return [...$out,'revision'=>hash('sha256','awaiting_assignment')];
        if(ConnectedTrackingPolicy::closed($order->status))return [...$out,'stage'=>'completed','caller_location_allowed'=>false,'revision'=>hash('sha256','completed')];
        $payload=$order->payload_uuid?self::native(Payload::class,$company)->where('uuid',$order->payload_uuid)->first():null;
        // A hospital must never receive a new destination selected after its access
        // check. On the next poll its complete authorization is evaluated again.
        if($authorizedDestination&&(!$payload||$payload->dropoff_uuid!==$authorizedDestination->uuid
            ||$authorizedDestination->company_uuid!==$company||$authorizedDestination->deleted_at))return null;
        $out['pickup']=self::place($payload?->pickup_uuid,$company);
        $out['destination']=self::place($payload?->dropoff_uuid,$company);
        $onboard=Schema::hasTable('mapa_mobile_hospital_handovers')&&DB::table('mapa_mobile_hospital_handovers')
            ->where(['company_uuid'=>$company,'order_uuid'=>$order->uuid])->exists();
        if($onboard||$order->status==='enroute'){
            $out['stage']='to_hospital';$out['leg']='hospital';$out['caller_location_allowed']=false;
        }elseif($order->dispatched&&in_array($order->status,['dispatched','started'],true)){
            $out['stage']='to_pickup';$out['leg']='pickup';
        }
        if($order->dispatched&&in_array($out['stage'],['to_pickup','to_hospital'],true)&&$order->vehicle_assigned_uuid){
            $v=DB::table('vehicles')->where(['company_uuid'=>$company,'uuid'=>$order->vehicle_assigned_uuid])->whereNull('deleted_at')->first();
            if($v)$out['ambulance']=['name'=>$v->name,'registration'=>$v->plate_number,
                'location'=>(new ConnectedTrackingService)->vehiclePosition($company,$v,$order->driver_assigned_uuid)];
        }
        // Exclude response-clock noise: all roles see the same revision for the same facts.
        $facts=$out;unset($facts['server_time']);
        $revision=hash('sha256',json_encode($facts,JSON_THROW_ON_ERROR));
        $fix=$out['ambulance']['location']??null;
        $target=$out['leg']==='pickup'?$out['pickup']:($out['leg']==='hospital'?$out['destination']:null);
        $fresh=$fix&&(($fix['source']??'')==='hardware_gps'?HardwareFixPolicy::fresh($fix,$now):ConnectedTrackingPolicy::fix($fix,$now));
        if($fresh&&$target&&$target['latitude']!==null&&$target['longitude']!==null){
            $route=PrivateRoadRoute::between($fix,$target);
            if($route)$out['planned_road_route']=[...$route,'leg'=>$out['leg'],'source_recorded_at'=>$fix['recorded_at'],'context_revision'=>$revision];
        }
        return [...$out,'revision'=>$revision];
    }
}

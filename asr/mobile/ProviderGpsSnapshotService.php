<?php
namespace App\MapaMobile;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Authenticated provider layer; no operational table, projection or source-system writes. */
final class ProviderGpsSnapshotService
{
    /** Selected-vehicle details only; patient data never enters the fleet-wide GPS response. */
    public function journey(array $context,string $vehicleId): array
    {
        if(!in_array($context['binding']->role??null,['operator','admin','superadmin'],true))MobileService::fail('A MAPA operator or administrator is required.',403);
        if(!preg_match('/^[a-f0-9-]{36}$/iD',$vehicleId))MobileService::fail('Invalid ambulance.',422);
        $company=$context['company'];$now=(int)round(microtime(true)*1000);
        $vehicle=DB::table('vehicles')->where(['company_uuid'=>$company,'uuid'=>$vehicleId])->whereNull('deleted_at')->first(['uuid','public_id','plate_number','meta']);
        if(!$vehicle)MobileService::fail('Ambulance not found.',404);
        $vehicleMeta=json_decode($vehicle->meta?:'{}',true);
        if(!is_array($vehicleMeta)||!empty($vehicleMeta['mapa_demo'])||!empty($vehicleMeta['simulated']))MobileService::fail('Ambulance not found.',404);
        $orders=DB::table('orders')->where(['company_uuid'=>$company,'vehicle_assigned_uuid'=>$vehicleId])->whereNull('deleted_at')
            ->where('created_at','>=',gmdate('Y-m-d H:i:s',(int)(($now-30*86400000)/1000)))
            ->orderByDesc('created_at')->limit(30)->get();
        $eligible=$orders->filter(function($order){$meta=json_decode($order->meta?:'{}',true);return is_array($meta)&&empty($meta['mapa_demo'])&&empty($meta['simulated']);});
        $order=$eligible->first(fn($row)=>!in_array($row->status,['completed','canceled','cancelled','failed'],true))??$eligible->first();
        if(!$order)return ['contract'=>'mapa-gps-journey-v1','vehicle_id'=>$vehicle->public_id,'server_time'=>$now,'trip'=>null,'patient'=>null,'trail'=>[],'trail_truncated'=>false,'read_only'=>true];
        $call=Schema::hasTable('mapa_voice_calls')?DB::table('mapa_voice_calls')->where(['company_uuid'=>$company,'order_uuid'=>$order->uuid,'demo'=>false])->first(['id','draft','review']):null;
        $draft=$call?json_decode($call->draft?:'{}',true):[];$review=$call?json_decode($call->review?:'{}',true):[];
        if(!is_array($draft))$draft=[];if(!is_array($review))$review=[];
        $meta=json_decode($order->meta?:'{}',true);if(!is_array($meta))$meta=[];
        $intake=is_array($meta['asr_intake']??null)?$meta['asr_intake']:[];
        $value=static function($raw,int $limit=200){return is_string($raw)&&trim($raw)!==''?mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]/u',' ',$raw)),0,$limit):null;};
        $patient=['name'=>$value($review['patient_name']??$draft['patient_name']??$intake['patient_name']??null),
            'age'=>is_numeric($review['patient_age']??$draft['patient_age']??$intake['patient_age']??null)?(int)($review['patient_age']??$draft['patient_age']??$intake['patient_age']):null,
            'gender'=>$value($review['patient_gender']??$draft['patient_gender']??$intake['patient_gender']??null,30),
            'incident'=>$value($review['incident_type']??$draft['incident_type']??$intake['incident_type']??null),
            'reported_problem'=>$value($review['reported_problem']??$draft['reported_problem']??null,500)];
        if(!array_filter($patient,fn($v)=>$v!==null))$patient=null;
        $created=\Carbon\Carbon::parse($order->created_at,'UTC')->valueOf();
        $started=$order->started_at?\Carbon\Carbon::parse($order->started_at,'UTC')->valueOf():null;
        $ended=in_array($order->status,['completed','canceled','cancelled','failed'],true)?\Carbon\Carbon::parse($order->updated_at,'UTC')->valueOf():$now;
        $completion=$meta['mapa_mobile_trip']['completed']['recorded_at']??null;
        if($order->status==='completed'&&is_int($completion)&&$completion>=$created&&$completion<=$now)$ended=$completion;
        $trail=[];$truncated=false;
        if(Schema::hasTable('mapa_device_position_history')){
            $points=DB::table('mapa_device_position_history')->where('company_uuid',$company)->where('vehicle_uuid',$vehicleId)
                ->whereBetween('recorded_at',[$created,$ended])->orderByDesc('recorded_at')->limit(1001)
                ->get(['latitude','longitude','recorded_at']);$truncated=$points->count()>1000;
            foreach($points->take(1000)->reverse() as $point){$lat=(float)$point->latitude;$lon=(float)$point->longitude;
                if(is_finite($lat)&&is_finite($lon)&&abs($lat)<=90&&abs($lon)<=180&&!($lat===0.0&&$lon===0.0))$trail[]=['latitude'=>$lat,'longitude'=>$lon,'recorded_at'=>(int)$point->recorded_at];}
        }
        return ['contract'=>'mapa-gps-journey-v1','vehicle_id'=>$vehicle->public_id,'server_time'=>$now,
            'trip'=>['order_id'=>$order->public_id,'status'=>$order->status,'created_at'=>$created,'started_at'=>$started,'ended_at'=>in_array($order->status,['completed','canceled','cancelled','failed'],true)?$ended:null,
                'pickup'=>$value($review['pickup_address']??$draft['pickup_address']??null,300),
                'destination'=>$value($review['destination_facility_name']??$intake['destination_facility_name']??null,200),
                'call_id'=>$call?->id],
            'patient'=>$patient,'trail'=>$trail,'trail_truncated'=>$truncated,'read_only'=>true];
    }
    public function read(array $context): array
    {
        if(!in_array($context['binding']->role??null,['operator','admin','superadmin'],true))MobileService::fail('A MAPA operator or administrator is required.',403);
        $company=$context['company'];$now=(int)round(microtime(true)*1000);
        // Existing private, read-only API mount. The host publisher writes only sanitized location fields.
        $path='/run/mapa-secrets/way4track-last-known.json';
        if(is_link($path)||!is_file($path)||!is_readable($path))MobileService::fail('Provider map data is not available yet.',503);
        if(DIRECTORY_SEPARATOR==='/'&&(fileperms($path)&0007)!==0)MobileService::fail('Provider snapshot permissions must be private.',503);
        $stream=fopen($path,'rb');
        if($stream===false)MobileService::fail('Provider map data is unavailable.',503);
        try{$data=stream_get_contents($stream,ProviderGpsSnapshotPolicy::MAX_BYTES+1);}finally{fclose($stream);}
        if($data===false||strlen($data)>ProviderGpsSnapshotPolicy::MAX_BYTES)MobileService::fail('Provider snapshot is invalid.',503);
        $snapshot=json_decode($data,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($snapshot))MobileService::fail('Provider snapshot is invalid.',503);
        return $this->readSnapshot($context,$snapshot,$now);
    }
    /** Read model shared with isolated database tests; HTTP clients cannot supply the snapshot. */
    public function readSnapshot(array $context,array $snapshot,int $now): array
    {
        if(!in_array($context['binding']->role??null,['operator','admin','superadmin'],true))MobileService::fail('A MAPA operator or administrator is required.',403);
        $company=$context['company'];
        // Validate tenant and bounds before touching the database. File mapping hints are never trusted.
        ProviderGpsSnapshotPolicy::view($snapshot,$company,[],$now);
        $bindings=[];$duplicates=[];
        if(Schema::hasTable('mapa_tracking_devices')){
            $rows=DB::table('mapa_tracking_devices as d')->join('vehicles as v',fn($q)=>$q->on('v.uuid','=','d.vehicle_uuid')->on('v.company_uuid','=','d.company_uuid'))
                ->where('d.company_uuid',$company)->whereNull('d.revoked_at')->whereNull('v.deleted_at')
                ->whereIn('d.external_id',array_column($snapshot['devices'],'external_id'))
                ->select(['d.external_id','v.uuid as vehicle_uuid','v.public_id as vehicle_id','v.plate_number as registration','v.name as vehicle_name',
                    'v.type as vehicle_type','v.make as vehicle_make','v.model as vehicle_model','v.year as vehicle_year','v.color as vehicle_color',
                    'v.call_sign','v.status as vehicle_status','v.online as vehicle_online','v.seating_capacity','v.meta'])
                ->limit(ProviderGpsSnapshotPolicy::MAX_DEVICES+1)->get();
            foreach($rows as $row){
                $meta=json_decode($row->meta?:'{}',true);
                if(!is_array($meta)||!empty($meta['mapa_demo'])||!empty($meta['simulated'])||!empty($meta['archived']))continue;
                if(isset($bindings[$row->external_id])){$duplicates[$row->external_id]=true;continue;}
                $bindings[$row->external_id]=(array)$row;
            }
            foreach($duplicates as $id=>$ignored)unset($bindings[$id]);
            if($bindings && Schema::hasTable('drivers') && Schema::hasTable('users')){
                $byVehicle=[];$ambiguous=[];
                $drivers=DB::table('drivers as r')->leftJoin('users as u',fn($q)=>$q->on('u.uuid','=','r.user_uuid')->on('u.company_uuid','=','r.company_uuid'))
                    ->where('r.company_uuid',$company)->whereNull('r.deleted_at')->whereIn('r.vehicle_uuid',array_column($bindings,'vehicle_uuid'))
                    ->select(['r.vehicle_uuid','r.public_id as driver_id','u.name as driver_name'])->limit(ProviderGpsSnapshotPolicy::MAX_DEVICES+1)->get();
                foreach($drivers as $driver){if(isset($byVehicle[$driver->vehicle_uuid])){$ambiguous[$driver->vehicle_uuid]=true;continue;}$byVehicle[$driver->vehicle_uuid]=$driver;}
                foreach($bindings as &$binding){$vehicle=$binding['vehicle_uuid'];if(isset($byVehicle[$vehicle])&&!isset($ambiguous[$vehicle])){
                    $binding['driver_id']=$byVehicle[$vehicle]->driver_id;$binding['driver_name']=$byVehicle[$vehicle]->driver_name;
                }}unset($binding);
            }
        }
        return ProviderGpsSnapshotPolicy::view($snapshot,$company,$bindings,$now);
    }
}

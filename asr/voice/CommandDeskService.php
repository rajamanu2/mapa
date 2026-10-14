<?php
namespace App\MapaVoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Tenant-bound, bounded, read-only operational evidence. Authentication belongs to the caller. */
final class CommandDeskService
{
    public const CONTRACT='mapa-command-desk-v1';
    public const SCAN_LIMIT=5000;
    public function read(array $context,array $filters=[],?int $nowMs=null): array {
        $company=$context['company']??null;
        if(!is_string($company)||!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/Di',$company)
            ||!in_array($context['binding']->role??null,['operator','admin','superadmin'],true))throw new HttpException(403,'Command desk requires authenticated staff.');
        if(array_diff(array_keys($filters),['days','page','driver_id']))throw new HttpException(422,'Unknown command desk filter.');
        $days=filter_var($filters['days']??30,FILTER_VALIDATE_INT);$page=filter_var($filters['page']??1,FILTER_VALIDATE_INT);$id=$filters['driver_id']??null;
        if($days===false||$days<1||$days>90||$page===false||$page<1||$page>10000||($id!==null&&(!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$id))))throw new HttpException(422,'Invalid command desk filter.');
        $now=$nowMs??(int)round(microtime(true)*1000);$cutoff=$now-$days*86400000;$date=gmdate('Y-m-d H:i:s',(int)($cutoff/1000));
        $drivers=DB::table('drivers as d')->leftJoin('users as u',fn($q)=>$q->on('u.uuid','=','d.user_uuid')->where('u.company_uuid',$company)->whereNull('u.deleted_at'))
            ->where('d.company_uuid',$company)->whereNull('d.deleted_at');
        if($id!==null)$drivers->where('d.public_id',$id);
        $driverRows=$drivers->orderBy('d.uuid')->offset(($page-1)*50)->limit(51)->get(['d.uuid','d.public_id','d.user_uuid','d.vehicle_uuid','d.meta','u.name']);$driverMore=$driverRows->count()>50;
        $rows=[];$byUuid=[];$byPublic=[];
        foreach($driverRows->take(50) as $d){if($this->demo($d))continue;$byUuid[$d->uuid]=$d;$byPublic[$d->public_id]=$d->uuid;}
        $offerAvailable=Schema::hasTable('mapa_dispatch_offers');$offerRows=[];$offerTruncated=false;
        if($offerAvailable&&$byUuid){
            $found=DB::table('mapa_dispatch_offers as o')->join('mapa_voice_calls as c',fn($q)=>$q->on('c.id','=','o.call_id')->where('c.company_uuid',$company))
                ->where('o.company_uuid',$company)->whereIn('o.driver_uuid',array_keys($byUuid))->where('c.demo',false)->where('c.provider','<>','asrhealth')
                ->where('o.created_at','>=',$date)->where('o.created_at','<=',gmdate('Y-m-d H:i:s',(int)($now/1000)))->orderByDesc('o.created_at')->orderBy('o.id')->limit(self::SCAN_LIMIT+1)->get(['o.*']);
            $offerTruncated=$found->count()>self::SCAN_LIMIT;foreach($found->take(self::SCAN_LIMIT) as $o)$offerRows[$o->driver_uuid][]=(array)$o;
        }
        $history=[];$completionCounts=array_fill_keys(array_keys($byUuid),0);$unattributed=0;$unknownCompletion=[];
        $completed=$this->native('orders',$company)->where('status','completed')->where('updated_at','>=',$date)->orderByDesc('updated_at')->orderBy('uuid')->limit(self::SCAN_LIMIT+1)->get();
        foreach($completed->take(self::SCAN_LIMIT) as $order){if($this->demo($order))continue;$meta=CommandDeskPolicy::meta($order->meta);$e=$meta['mapa_mobile_trip']['completed']??null;
            $at=is_array($e)?CommandDeskPolicy::milliseconds($e['recorded_at']??null):null;$driver=is_array($e)?($byPublic[$e['driver_id']??'']??null):null;
            if(!$at||$at>$now||!is_string($e['driver_id']??null)){$unattributed++;if(isset($byUuid[$order->driver_assigned_uuid]))$unknownCompletion[$order->driver_assigned_uuid]=true;continue;}if(!$driver||$at<$cutoff)continue;
            $completionCounts[$driver]++;
            if(count($history)<100)$history[]=['order_id'=>$order->public_id,'driver_uuid'=>$driver,'driver_id'=>$byUuid[$driver]->public_id,'driver_name'=>$byUuid[$driver]->name??null,'stage'=>$order->status,'completed_at'=>$at];
        }
        foreach($byUuid as $uuid=>$d){$stats=CommandDeskPolicy::offers($offerRows[$uuid]??[],$now);
            if(!$offerAvailable)foreach($stats as $key=>$value)$stats[$key]=null;
            $rows[]=['driver_uuid'=>$uuid,'driver_id'=>$d->public_id,'driver_name'=>$d->name??null,'current_vehicle_uuid'=>$d->vehicle_uuid,
                'completed_trips'=>isset($unknownCompletion[$uuid])||$completed->count()>self::SCAN_LIMIT?null:$completionCounts[$uuid],'known_completed_trips'=>$completionCounts[$uuid],
                'completed_trips_lower_bound'=>$completed->count()>self::SCAN_LIMIT,'offer_statistics_partial'=>$offerTruncated,'completed_trips_basis'=>'crew_recorded_completion_driver_identity']+$stats;}
        $active=$this->native('orders',$company)->whereNotIn('status',['completed','canceled','cancelled','failed'])->orderByDesc('updated_at')->orderBy('uuid')->limit(101)->get();$monitor=[];
        foreach($active->take(100) as $order){if($this->demo($order))continue;$monitor[]=$this->monitor($company,$order,$now);}
        $fleetRows=$this->native('vehicles',$company)->orderBy('uuid')->limit(201)->get();$fleet=[];
        $fleet=$this->fleet($company,$fleetRows->take(200)->filter(fn($v)=>!$this->demo($v)),$now);
        $callCount=Schema::hasTable('mapa_voice_calls')?DB::table('mapa_voice_calls')->where('company_uuid',$company)->where('demo',false)->whereNotIn('state',['completed','cancelled','canceled','dispatched','dismissed'])->count():null;
        $dispatchExceptions=[];$dispatchExceptionsTruncated=false;
        if(Schema::hasTable('mapa_dispatch_runs')&&Schema::hasTable('mapa_voice_calls')){
            $staleBefore=gmdate('Y-m-d H:i:s',(int)($now/1000)-90);
            $found=DB::table('mapa_dispatch_runs as r')->join('mapa_voice_calls as c',fn($q)=>$q->on('c.id','=','r.call_id')->on('c.company_uuid','=','r.company_uuid'))
                ->where('r.company_uuid',$company)->where('c.demo',false)->where('c.provider','<>','asrhealth')
                ->where(fn($q)=>$q->where('r.state','needs_operator')->orWhere(fn($s)=>$s->whereIn('r.state',['searching','offering'])->where('r.updated_at','<=',$staleBefore)))
                ->orderByDesc('r.updated_at')->orderBy('r.call_id')->limit(101)->get(['r.call_id','r.state','r.reason','r.updated_at']);
            $dispatchExceptionsTruncated=$found->count()>100;
            foreach($found->take(100) as $run)$dispatchExceptions[]=['call_id'=>$run->call_id,'state'=>$run->state,'reason'=>$run->reason,
                'updated_at'=>$run->updated_at,'message'=>$run->state==='needs_operator'?'Automatic search needs an operator. Review the call and available crews.':'Automatic dispatch worker has not updated this call for over 90 seconds. Check the scheduler and contact control.'];
        }
        $gpsCoverage=['recent'=>0,'stale'=>0,'missing'=>0];
        foreach($fleet as $vehicle){
            if($vehicle['gps_stale']===false)$gpsCoverage['recent']++;
            elseif($vehicle['gps_stale']===true)$gpsCoverage['stale']++;
            else $gpsCoverage['missing']++;
        }
        $completeFleet=$fleetRows->count()<=200;
        return ['contract'=>self::CONTRACT,'company_id'=>$company,'server_time'=>$now,'window'=>['days'=>$days,'from'=>$cutoff,'to'=>$now],
            'summary'=>['total_fleet'=>$completeFleet?count($fleet):null,'on_trip'=>$active->count()>100?null:count(array_unique(array_column(array_filter($monitor,fn($m)=>$m['vehicle_uuid']!==null&&$m['driver_id']!==null&&$m['stage']!=='created'),'vehicle_uuid'))),'active_calls'=>$callCount,'dispatchable_now'=>null,'gps_stale_offline'=>$completeFleet?$gpsCoverage['stale']+$gpsCoverage['missing']:null,'gps_coverage'=>$completeFleet?$gpsCoverage:null],
            'driver_statistics'=>$rows,'trip_monitoring'=>$monitor,'dispatch_exceptions'=>$dispatchExceptions,'history'=>$history,'fleet'=>$fleet,
            'metrics'=>['acceptance_denominator'=>'accepted + declined + expired; excludes pending/overdue/cancelled','response_denominator'=>'valid timed accepted/declined responses only; excludes expiry worker times','offers_available'=>$offerAvailable,'unattributed_completions'=>$unattributed],
            'page'=>$page,'page_size'=>50,'has_more'=>$driverMore,'truncated'=>['drivers'=>$driverMore,'offers'=>$offerTruncated,'completed_trips'=>$completed->count()>self::SCAN_LIMIT,'history'=>array_sum($completionCounts)>100,'active_trips'=>$active->count()>100,'fleet'=>$fleetRows->count()>200,'dispatch_exceptions'=>$dispatchExceptionsTruncated],
            'read_only'=>true,'dispatch_authority'=>\App\MapaMobile\DispatchAuthority::mapaControls()?'mapa':'asr','source_asr_dispatch_control'=>false,'source_asr_snapshot_read'=>false,'native_source_linked_orders_included'=>true];
    }
    private function native(string $table,string $company){return DB::table($table)->where('company_uuid',$company)->whereNull('deleted_at');}
    private function demo(object $row): bool {$meta=CommandDeskPolicy::meta($row->meta??null);return ($meta['mapa_demo']??false)===true||($meta['simulated']??false)===true;}
    /** Read existing vehicle projections; missing GPS/crew identity remains unknown. */
    private function fleet(string $company,$vehicles,int $now): array {
        if($vehicles->isEmpty())return [];
        $ids=$vehicles->pluck('uuid')->all();
        $driverQuery=DB::table('drivers as d')->leftJoin('users as u',fn($q)=>$q->on('u.uuid','=','d.user_uuid')->where('u.company_uuid',$company)->whereNull('u.deleted_at'))
            ->where('d.company_uuid',$company)->whereNull('d.deleted_at')->whereIn('d.vehicle_uuid',$ids);
        $columns=['d.*','u.name as crew_name'];if(Schema::hasColumn('users','phone'))$columns[]='u.phone as crew_phone';
        $drivers=$driverQuery->limit(1001)->get($columns)->filter(fn($d)=>!$this->demo($d))->groupBy('vehicle_uuid');
        $sourceIds=Schema::hasTable('mapa_source_connections')?array_fill_keys(DB::table('mapa_source_connections')->where('company_uuid',$company)
            ->whereIn('dataset',['drivers','ambulances'])->whereIn('native_uuid',array_merge($ids,$drivers->flatten()->pluck('uuid')->all()))->pluck('native_uuid')->all(),true):[];
        $phoneStates=Schema::hasTable('mapa_mobile_driver_states')?DB::table('mapa_mobile_driver_states')->where('company_uuid',$company)
            ->whereIn('driver_uuid',$drivers->flatten()->pluck('uuid')->all())->get()->keyBy('driver_uuid'):collect();
        $positions=Schema::hasTable('mapa_vehicle_position_states')?DB::table('mapa_vehicle_position_states')->where('company_uuid',$company)->whereIn('vehicle_uuid',$ids)->get()->keyBy('vehicle_uuid'):collect();
        $hardware=Schema::hasTable('mapa_device_positions')&&Schema::hasTable('mapa_tracking_devices')?DB::table('mapa_device_positions as p')
            ->join('mapa_tracking_devices as t',fn($q)=>$q->on('t.id','=','p.device_id')->on('t.vehicle_uuid','=','p.vehicle_uuid')->on('t.company_uuid','=','p.company_uuid'))
            ->where('p.company_uuid',$company)->whereIn('p.vehicle_uuid',$ids)->whereNull('t.revoked_at')->select('p.*')->get()
            ->sortByDesc('recorded_at')->groupBy('vehicle_uuid')->map(fn($rows)=>$rows->first()):collect();
        $coordinates=collect();
        if(Schema::hasColumn('vehicles','location')&&DB::getDriverName()==='mysql')$coordinates=$this->native('vehicles',$company)->whereIn('uuid',$ids)->select('uuid')->selectRaw('ST_Y(location) AS gps_latitude, ST_X(location) AS gps_longitude')->get()->keyBy('uuid');
        $out=[];
        foreach($vehicles as $v){
            $linked=$drivers->get($v->uuid,collect());$driver=$linked->count()===1?$linked->first():null;
            if($driver&&isset($v->driver_uuid)&&$v->driver_uuid&&$v->driver_uuid!==$driver->uuid)$driver=null;
            $position=$positions->get($v->uuid);$point=$coordinates->get($v->uuid);$recorded=$position?self::validTimestamp($position->recorded_at??null,$now):null;
            $latitude=$point->gps_latitude??$v->latitude??null;$longitude=$point->gps_longitude??$v->longitude??null;
            $valid=is_numeric($latitude)&&is_numeric($longitude)&&is_finite((float)$latitude)&&is_finite((float)$longitude)&&abs((float)$latitude)<=90&&abs((float)$longitude)<=180
                &&$recorded!==null&&in_array($position->source??null,['crew_phone','hardware_gps'],true);
            $age=$valid?(int)(($now-$recorded)/1000):null;$meta=CommandDeskPolicy::meta($v->meta??null);
            $driverMeta=CommandDeskPolicy::meta($driver->meta??null);
            $sourceImported=isset($meta['asr_import'])||isset($meta['asr_source_id'])||isset($meta['mapa_source_id'])||isset($sourceIds[$v->uuid])
                ||isset($driverMeta['asr_import'])||isset($driverMeta['asr_source_id'])||isset($driverMeta['mapa_source_id'])||($driver&&isset($sourceIds[$driver->uuid]));
            $evidence=$valid&&($position->source??null)==='hardware_gps'?$hardware->get($v->uuid):null;
            if($evidence&&($evidence->vehicle_uuid!==$v->uuid||(int)$evidence->recorded_at!==$recorded))$evidence=null;
            $protocol=$evidence->source_protocol??null;
            $phoneState=$driver?$phoneStates->get($driver->uuid):null;
            $phoneTime=$phoneState?self::validTimestamp($phoneState->recorded_at??null,$now):null;
            $phoneLat=$driver->gps_latitude??$driver->latitude??null;$phoneLon=$driver->gps_longitude??$driver->longitude??null;
            $phoneFix=$phoneTime!==null&&is_numeric($phoneLat)&&is_numeric($phoneLon)&&abs((float)$phoneLat)<=90&&abs((float)$phoneLon)<=180
                ?['latitude'=>(float)$phoneLat,'longitude'=>(float)$phoneLon,'recorded_at'=>$phoneTime,'age_seconds'=>(int)(($now-$phoneTime)/1000),'stale'=>$now-$phoneTime>180000,'source'=>'crew_phone']:null;
            $device=$hardware->get($v->uuid);
            $deviceTime=$device?self::validTimestamp($device->recorded_at??null,$now):null;
            $hardwareFix=$deviceTime!==null&&is_numeric($device->latitude??null)&&is_numeric($device->longitude??null)
                &&abs((float)$device->latitude)<=90&&abs((float)$device->longitude)<=180
                ?['latitude'=>(float)$device->latitude,'longitude'=>(float)$device->longitude,'recorded_at'=>$deviceTime,
                    'age_seconds'=>(int)(($now-$deviceTime)/1000),'stale'=>$now-$deviceTime>180000,'source'=>'ambulance_gps',
                    'protocol'=>$device->source_protocol??null,'quality'=>($device->source_protocol??null)==='way4track'?'provider_reported':null,
                    'accuracy_m'=>isset($device->accuracy_m)?(float)$device->accuracy_m:null]:null;
            $out[]=['vehicle_uuid'=>$v->uuid,'vehicle_id'=>$v->public_id,'registration'=>$v->plate_number??null,'name'=>$v->name??null,
                'driver_id'=>$driver?->public_id,'driver_name'=>$driver?->crew_name,'driver_phone'=>$driver->crew_phone??null,
                'driver_mapping_ambiguous'=>$linked->count()>1,'capability'=>$meta['mapa_ambulance_level']??null,
                'status'=>$v->status??null,'online'=>isset($v->online)?(bool)$v->online:null,'dispatchable'=>null,
                'latitude'=>$valid?(float)$latitude:null,'longitude'=>$valid?(float)$longitude:null,'gps_recorded_at'=>$valid?$recorded:null,
                'gps_age_seconds'=>$age,'gps_stale'=>$age===null?null:$age>180,'gps_source'=>$valid?$position->source:null,
                'source_protocol'=>$protocol,'position_quality'=>$protocol==='way4track'?'provider_reported':null,
                'accuracy_m'=>isset($evidence->accuracy_m)?(float)$evidence->accuracy_m:null,
                'provider_received_at'=>isset($evidence->provider_received_at)?(int)$evidence->provider_received_at:null,
                'crew_phone_location'=>$phoneFix,'ambulance_gps_location'=>$hardwareFix,
                'source_imported'=>$sourceImported,
                'dispatch_authority'=>\App\MapaMobile\DispatchAuthority::mapaControls()?'mapa':($sourceImported?'asr':'mapa'),
                'source_owned'=>!\App\MapaMobile\DispatchAuthority::mapaControls()&&$sourceImported];
        }
        return $out;
    }
    private static function validTimestamp($value,int $now): ?int {return is_numeric($value)&&is_finite((float)$value)&&$value>0&&$value<=$now?(int)$value:null;}
    private function monitor(string $company,object $order,int $now): array {
        $driver=$this->native('drivers',$company)->where('uuid',$order->driver_assigned_uuid)->first();
        $vehicle=$this->native('vehicles',$company)->where('uuid',$order->vehicle_assigned_uuid)->first();
        $name=$driver?$this->native('users',$company)->where('uuid',$driver->user_uuid)->value('name'):null;
        $call=DB::table('mapa_voice_calls')->where(['company_uuid'=>$company,'order_uuid'=>$order->uuid,'demo'=>false])->first(['id','provider']);
        $ackState=Schema::hasTable('mapa_mobile_order_states')&&$driver?DB::table('mapa_mobile_order_states')->where(['company_uuid'=>$company,'order_uuid'=>$order->uuid,'driver_uuid'=>$driver->uuid])->first():null;
        $ack=$ackState&&$driver&&$vehicle&&$driver->vehicle_uuid===$vehicle->uuid&&is_string($ackState->assignment_hash??null)&&hash_equals($ackState->assignment_hash,CommandDeskPolicy::assignmentHash((array)$order))?$ackState->acknowledged_at:null;
        $fixes=[];$truncated=false;
        if($driver&&$vehicle&&$ack&&Schema::hasTable('mapa_mobile_receipts')){
            $receipts=DB::table('mapa_mobile_receipts')->where(['company_uuid'=>$company,'user_uuid'=>$driver->user_uuid])->where('created_at','>=',gmdate('Y-m-d H:i:s',(int)max(($now-1800000)/1000,((int)$ack-30000)/1000)))
                ->where('result->gps_evidence->driver_id',$driver->public_id)->where('result->gps_evidence->vehicle_id',$vehicle->public_id)->orderByDesc('id')->limit(201)->get();$truncated=$receipts->count()>200;
            foreach($receipts->take(200) as $receipt){$e=CommandDeskPolicy::meta($receipt->result)['gps_evidence']??null;if(is_array($e))$fixes[]=$e;}
        }
        $movement=CommandDeskPolicy::movement($ack?(int)$ack:null,$fixes,$now,$truncated);
        $pickupArrival=CommandDeskPolicy::reportedArrival(CommandDeskPolicy::meta($order->meta)['mapa_mobile_arrivals']['pickup_arrived']??null,$company,$order->uuid,$driver?->uuid,$vehicle?->uuid,$ack?(int)$ack:null,$now,'pickup_arrived');
        if(!in_array($order->status,['dispatched','started'],true)||$pickupArrival!==null)$movement=['state'=>'not_applicable','reason'=>$pickupArrival!==null?'pickup_arrival_reported':'native_stage_advanced','distance_m'=>null,'elapsed_seconds'=>null,'review_after_seconds'=>null];
        $arrival=CommandDeskPolicy::reportedArrival(CommandDeskPolicy::meta($order->meta)['mapa_mobile_arrivals']['hospital_arrived']??null,$company,$order->uuid,$driver?->uuid,$vehicle?->uuid,$ack?(int)$ack:null,$now);
        $arrivalSource=$arrival!==null?'crew_reported_arrival':null;
        if($arrival===null&&$order->tracking_number_uuid&&Schema::hasTable('tracking_numbers')&&Schema::hasTable('tracking_statuses')&&$this->native('tracking_numbers',$company)->where(['uuid'=>$order->tracking_number_uuid,'owner_uuid'=>$order->uuid])->exists()){
            $stamp=$this->native('tracking_statuses',$company)->where(['tracking_number_uuid'=>$order->tracking_number_uuid,'code'=>'hospital_arrived'])->orderByDesc('created_at')->value('created_at');$arrival=CommandDeskPolicy::milliseconds($stamp);
            if($arrival!==null)$arrivalSource='native_tracking_event';
        }
        $closure=CommandDeskPolicy::closure($order->status,$arrival,$now);$alerts=[];
        if($movement['state']==='movement_not_observed')$alerts[]=['code'=>'movement_review','message'=>'Movement beyond 200 m has not been observed in continuous fresh GPS evidence. Contact crew to review the situation.'];
        if($movement['state']==='unknown')$alerts[]=['code'=>'movement_unknown','message'=>'Movement is unknown: reliable acceptance/location evidence is incomplete.'];
        if(in_array($closure['state'],['reminder','review_delay','review_overdue'],true))$alerts[]=['code'=>'closure_review','message'=>'The trip remains open after recorded hospital arrival. Review handover and closure with crew.'];
        return ['order_id'=>$order->public_id,'call_id'=>$call?->id,'driver_id'=>$driver?->public_id,'driver_name'=>$name,'vehicle_uuid'=>$vehicle?->uuid,'vehicle_registration'=>$vehicle?->plate_number,'stage'=>$order->status,
            'hospital_arrived_at'=>$arrival,'hospital_arrival_source'=>$arrivalSource,'movement'=>$movement,'closure'=>$closure,'alerts'=>$alerts,'source_owned'=>$call?->provider==='asrhealth'];
    }
}

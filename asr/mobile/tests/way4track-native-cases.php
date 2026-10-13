<?php
// Included only by connected-tracking-dedicated.php after its GT06 fixtures, never a live database.
use Illuminate\Support\Facades\DB;
$before=$count;
$wfDevice=$service->createDevice(trackingRequest(['vehicle_id'=>$vid,'external_id'=>'123456789012347','label'=>'DEMO Way4Track provider'],$admin));
$wfNow=(int)round(microtime(true)*1000)+15000;
$wfFix=['latitude'=>18.411234567,'longitude'=>82.911234567,'accuracy_m'=>null,'recorded_at'=>$wfNow,
    'provider_received_at'=>$wfNow+500,'source_protocol'=>'way4track','position_quality'=>'provider_reported','external_id'=>'123456789012347'];
$wfDriver=DB::table('drivers')->where('uuid',$crewDriver)->first();
$wfStates=DB::table('mapa_mobile_driver_states')->get()->toJson();
$wfOrders=DB::table('orders')->get()->toJson();
$wfCalls=DB::table('mapa_voice_calls')->get()->toJson();
$wfHistory=DB::table('mapa_device_position_history')->count();
$answer=$service->ingest(trackingRequest($wfFix,$wfDevice['secret']));
check($answer['map_updated']===true,'provider position projects native ambulance');
$stored=DB::table('mapa_device_positions')->where('device_id',$wfDevice['id'])->first();
check($stored->source_protocol==='way4track'&&$stored->accuracy_m===null&&$stored->gps_valid===null&&$stored->satellites===null,'provider never gains fabricated hardware quality');
check((int)$stored->recorded_at===$wfNow&&(int)$stored->provider_received_at===$wfNow+500,'GPS fix time and provider receipt persist separately');
$wfRecord=DB::table('mapa_device_position_history')->where('device_id',$wfDevice['id'])->first();
check((int)$wfRecord->recorded_at===$wfNow&&(int)$wfRecord->provider_received_at===$wfNow+500&&(int)$wfRecord->received_at!==$wfNow,'native receipt is distinct from provider and fix times');
check($stored->company_uuid===$company&&$stored->vehicle_uuid===$vehicle,'provider tenant and ambulance derive only from registered credential');
$wfPositions=DB::table('positions')->count();
$wfEvents=Illuminate\Support\Facades\Event::dispatched(App\MapaMobile\CrewVehicleLocationChanged::class)->count();
$answer=$service->ingest(trackingRequest([...$wfFix,'provider_received_at'=>$wfNow+1000],$wfDevice['secret']));
check(($answer['duplicate']??false)&&!$answer['map_updated'],'repeated provider fix with newer receipt is idempotent');
check(DB::table('mapa_device_position_history')->count()===$wfHistory+1&&DB::table('positions')->count()===$wfPositions,'provider retry adds no source or native history');
check(Illuminate\Support\Facades\Event::dispatched(App\MapaMobile\CrewVehicleLocationChanged::class)->count()===$wfEvents,'provider retry adds no broadcast');
denied(fn()=>$service->ingest(trackingRequest([...$wfFix,'external_id'=>'123456789012348'],$wfDevice['secret'])),401);
denied(fn()=>$service->ingest(trackingRequest($wfFix,str_repeat('a',64))),401);
foreach([
    ['accuracy_m'=>0],['accuracy_m'=>8],['recorded_at'=>$wfNow-600000,'provider_received_at'=>$wfNow],
    ['recorded_at'=>$wfNow+600000],['provider_received_at'=>$wfNow+600000],['provider_received_at'=>$wfNow-30001],
    ['latitude'=>91],['latitude'=>0,'longitude'=>0],['latitude'=>18.45],['recorded_at'=>$wfNow-1],
] as $change) denied(fn()=>$service->ingest(trackingRequest([...$wfFix,...$change],$wfDevice['secret'])),422);
foreach([['gps_valid'=>true],['satellites'=>8],['position_quality'=>'verified'],['source_protocol'=>'unknown']] as $change){
    try{$service->ingest(trackingRequest([...$wfFix,...$change],$wfDevice['secret']));throw new RuntimeException('Expected provider validation rejection');}
    catch(Illuminate\Validation\ValidationException $e){check(true,'fabricated provider schema rejected');}
}
$listed=$service->devices(trackingRequest([],$admin));
$found=array_values(array_filter($listed['devices'],fn($d)=>$d['id']===$wfDevice['id']))[0]['location'];
check($found['position_quality']==='provider_reported'&&$found['dispatch_eligible']===false&&$found['accuracy_m']===null,'operator sees reported quality without dispatch eligibility');
check($found['source_protocol']==='way4track'&&!$found['stale']&&$found['provider_received_at']===$wfNow+500,'provider read retains fresh fix and receipt separately');
check(DB::table('mapa_device_position_history')->count()===$wfHistory+1,'invalid provider requests leave history unchanged');
check(DB::table('mapa_mobile_driver_states')->get()->toJson()===$wfStates,'provider never changes driver location evidence');
check(DB::table('drivers')->where('uuid',$crewDriver)->first()==$wfDriver,'provider never changes driver duty or assignment');
check(DB::table('orders')->get()->toJson()===$wfOrders&&DB::table('mapa_voice_calls')->get()->toJson()===$wfCalls,'provider cannot modify calls or trips');
check(!Fleetbase\FleetOps\Models\Vehicle::withoutGlobalScopes()->where('uuid',$vehicle)->first()->online,'provider cannot start ambulance duty');
$service->revokeDevice(trackingRequest([],$admin),$wfDevice['id']);
denied(fn()=>$service->ingest(trackingRequest($wfFix,$wfDevice['secret'])),401);
check(DB::table('mapa_device_position_history')->count()===$wfHistory+1,'revoked provider retry creates no history');
echo ($count-$before)." Way4Track native identity, quality, history and projection checks passed.\n";

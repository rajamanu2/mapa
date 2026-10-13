<?php
// Included only by connected-tracking-dedicated.php in a disposable native database.
$before=$count;
DB::table('vehicles')->where('uuid',$vehicle)->update(['status'=>'available']);
$service->revokeDevice(trackingRequest([],$admin),$replacement['id']);
$gtDevice=$service->createDevice(trackingRequest(['vehicle_id'=>$vid,'external_id'=>'123456789012345','label'=>'DEMO GT06 receiver'],$admin));
$gtNow=(int)round(microtime(true)*1000)+10000;
$gtFix=['latitude'=>18.401234567,'longitude'=>82.901234567,'accuracy_m'=>null,'recorded_at'=>$gtNow,
    'source_protocol'=>'gt06','gps_valid'=>true,'satellites'=>8,'external_id'=>'123456789012345'];
$oldDriverCount=DB::table('mapa_mobile_driver_states')->count();
$answer=$service->ingest(trackingRequest($gtFix,$gtDevice['secret']));
check($answer['map_updated']===true,'GT06 fix projects to native vehicle');
$stored=DB::table('mapa_device_positions')->where('device_id',$gtDevice['id'])->first();
check($stored->accuracy_m===null && $stored->source_protocol==='gt06' && (int)$stored->satellites===8,'unknown accuracy and satellite evidence persist');
$historyBefore=DB::table('mapa_device_position_history')->count();
$eventsBefore=Illuminate\Support\Facades\Event::dispatched(App\MapaMobile\CrewVehicleLocationChanged::class)->count();
$answer=$service->ingest(trackingRequest($gtFix,$gtDevice['secret']));
check(($answer['duplicate']??false)===true && !$answer['map_updated'],'exact GT06 HTTP retry is idempotent after decimal normalization');
check(DB::table('mapa_device_position_history')->count()===$historyBefore,'retry adds no history');
check(Illuminate\Support\Facades\Event::dispatched(App\MapaMobile\CrewVehicleLocationChanged::class)->count()===$eventsBefore,'retry does not broadcast');
denied(fn()=>$service->ingest(trackingRequest([...$gtFix,'external_id'=>'123456789012346'],$gtDevice['secret'])),401);
foreach([['gps_valid'=>false],['recorded_at'=>$gtNow-600000],['recorded_at'=>$gtNow+600000],['latitude'=>91],['latitude'=>0,'longitude'=>0],['accuracy_m'=>0],['accuracy_m'=>101],['latitude'=>18.42]] as $change){
    denied(fn()=>$service->ingest(trackingRequest([...$gtFix,...$change],$gtDevice['secret'])),422);
}
foreach([['satellites'=>3],['satellites'=>16],['satellites'=>null],['source_protocol'=>'unknown']] as $change){
    try{$service->ingest(trackingRequest([...$gtFix,...$change],$gtDevice['secret']));throw new RuntimeException('Expected GT06 validation rejection');}
    catch(Illuminate\Validation\ValidationException $e){check(true,'invalid GT06 quality schema rejected');}
}
check(DB::table('mapa_device_position_history')->count()===$historyBefore,'all invalid GT06 samples leave history unchanged');
$listed=$service->devices(trackingRequest([],$admin));
$found=array_values(array_filter($listed['devices'],fn($d)=>$d['id']===$gtDevice['id']))[0];
check($found['location']['accuracy_m']===null && !$found['location']['stale'],'device read preserves unknown accuracy without treating it as zero');
check(DB::table('mapa_mobile_driver_states')->count()===$oldDriverCount,'GT06 does not create driver presence');
$native=Fleetbase\FleetOps\Models\Vehicle::withoutGlobalScopes()->where('uuid',$vehicle)->first();
check(!$native->online,'GT06 leaves duty unchanged');
$measured=[...$gtFix,'recorded_at'=>$gtNow+1,'accuracy_m'=>8.1];
$service->ingest(trackingRequest($measured,$gtDevice['secret']));
check(($service->ingest(trackingRequest($measured,$gtDevice['secret']))['duplicate']??false)===true,'measured accuracy retry tolerates database FLOAT representation');
$historyBefore=DB::table('mapa_device_position_history')->count();
$service->revokeDevice(trackingRequest([],$admin),$gtDevice['id']);
denied(fn()=>$service->ingest(trackingRequest($gtFix,$gtDevice['secret'])),401);
check(DB::table('mapa_device_position_history')->count()===$historyBefore,'revoked exact retry rechecks authorization');
echo ($count-$before)." GT06 native quality, identity, retry and projection checks passed.\n";

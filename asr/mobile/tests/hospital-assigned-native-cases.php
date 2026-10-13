<?php
// Invoked by hospital-handover-native-cases.php inside disposable native QA only.
if(!isset($mapaDedicatedQaApp))throw new RuntimeException('Isolated QA required.');
$earlyPayload=row('payloads',['company_uuid'=>$company,'dropoff_uuid'=>$hp]);
$earlyOrder=row('orders',['company_uuid'=>$company,'driver_assigned_uuid'=>$driver,'vehicle_assigned_uuid'=>$vehicle,
 'payload_uuid'=>$earlyPayload,'status'=>'dispatched','dispatched'=>1,'meta'=>json_encode(['mapa_dispatch_review'=>[
 'reported_problem'=>'DEMO caller reports breathing difficulty','patient_count'=>2,'consciousness'=>'unknown',
 'breathing'=>'not_normal','scene_safety'=>'unknown','priority'=>'urgent','callback_number'=>'DO NOT EXPOSE',
 'transcript'=>'PRIVATE DEMO transcript']])]);
$earlyId=DB::table('orders')->where('uuid',$earlyOrder)->value('public_id');
$earlyRows=fn($t)=>$handoverService->incoming(req([],$t))['assigned_trips'];
$earlyFind=fn($t)=>collect($earlyRows($t))->firstWhere('order_id',$earlyId);
$earlyCount=DB::table('mapa_mobile_hospital_handovers')->count();
$early=$earlyFind($ht);
check($early!==null&&$early['handover_state']==='awaiting_crew_report','receiving hospital sees assignment before crew onboard');
$savedEarlyMeta=DB::table('orders')->where('uuid',$earlyOrder)->value('meta');
$assignmentMeta=json_decode($savedEarlyMeta,true);$assignmentMeta['mapa_voice_call_id']=(string)Str::uuid();
DB::table('orders')->where('uuid',$earlyOrder)->update(['meta'=>json_encode($assignmentMeta)]);
$assignmentJob=new App\MapaMobile\DeliverHospitalAssignmentPush($earlyOrder,$company,$hp,$driver,$vehicle,
 hash('sha256','DEMO assignment guard'),time());
$currentAssignment=(new ReflectionClass($assignmentJob))->getMethod('current');$currentAssignment->setAccessible(true);
check($currentAssignment->invoke($assignmentJob)===true,'ordinary assigned trip passes hospital push recheck');
$assignmentMeta['mapa_operational_test']=true;
DB::table('orders')->where('uuid',$earlyOrder)->update(['meta'=>json_encode($assignmentMeta)]);
check($currentAssignment->invoke($assignmentJob)===false,'already queued hospital push refuses a no-patient pilot');
DB::table('orders')->where('uuid',$earlyOrder)->update(['meta'=>$savedEarlyMeta]);
check($early['caller_reported']['observed_by_crew']===false&&$early['caller_reported']['patient_count']===2&&$early['crew_observed']===null,'caller intake stays separate from absent clinical observations');
check(!isset($early['id'])&&!isset($early['revision'])&&$early['can_acknowledge_handover']===false,'early assignment cannot impersonate acknowledgeable handover');
check(!str_contains(json_encode($early['caller_reported']),'PRIVATE')&&!str_contains(json_encode($early['caller_reported']),'DO NOT EXPOSE'),'early intake projection allowlists fields');
check($early['tracking']['stage']==='to_pickup'&&$early['tracking']['revision']===App\MapaMobile\TripTrackingView::forOrder($earlyOrder,$company)['revision'],'early hospital tracking shares native trip facts');
check($handoverService->incoming(req([],$ht))['capabilities']['hospital_assigned_trips']===true,'hospital incoming advertises additive assignment capability');
check($earlyFind($ot)===null,'other hospital cannot see early assignment');
check(DB::table('mapa_mobile_hospital_handovers')->count()===$earlyCount,'polling never creates fake handover');
denied(fn()=>$handoverService->incoming(req([])),401);
// Existing observed report stays only in legacy incoming.
check(collect($earlyRows($ht))->firstWhere('order_id',$hpId)===null,'real current report replaces early card without duplicate row');
foreach(['status'=>'completed','dispatched'=>0,'company_uuid'=>$foreign,'driver_assigned_uuid'=>null,'vehicle_assigned_uuid'=>null,'deleted_at'=>now()] as $field=>$changed){
 $saved=DB::table('orders')->where('uuid',$earlyOrder)->value($field);
 DB::table('orders')->where('uuid',$earlyOrder)->update([$field=>$changed]);
 check($earlyFind($ht)===null,'early assignment disappears after withdrawn access '.$field);
 DB::table('orders')->where('uuid',$earlyOrder)->update([$field=>$saved]);
}
foreach(['drivers'=>$driver,'vehicles'=>$vehicle] as $table=>$id){
 DB::table($table)->where('uuid',$id)->update(['company_uuid'=>$foreign]);
 check($earlyFind($ht)===null,'foreign assigned resource cannot expose early trip '.$table);
 DB::table($table)->where('uuid',$id)->update(['company_uuid'=>$company]);
}
$savedDriverVehicle=DB::table('drivers')->where('uuid',$driver)->value('vehicle_uuid');
DB::table('drivers')->where('uuid',$driver)->update(['vehicle_uuid'=>$otherVehicle]);
check($earlyFind($ht)===null,'crew vehicle mismatch hides early assignment');
DB::table('drivers')->where('uuid',$driver)->update(['vehicle_uuid'=>$savedDriverVehicle]);
check($handoverService->incoming(req([],$ht))['assigned_trips_truncated']===false,'bounded assignment feed states whether more results exist');
DB::table('payloads')->where('uuid',$earlyPayload)->update(['dropoff_uuid'=>$hpOther]);
check($earlyFind($ht)===null&&$earlyFind($ot)!==null,'early projection follows current destination and removes old hospital access');
DB::table('payloads')->where('uuid',$earlyPayload)->update(['company_uuid'=>$foreign]);
check($earlyFind($ot)===null,'cross-company payload hides early trip');
// Bounds must be explicit rather than silently claiming all assigned trips are shown.
DB::table('payloads')->where('uuid',$earlyPayload)->update(['company_uuid'=>$company,'dropoff_uuid'=>$hp]);
$boundedOrders=[];
for($i=0;$i<101;$i++)$boundedOrders[]=row('orders',['company_uuid'=>$company,'driver_assigned_uuid'=>$driver,
 'vehicle_assigned_uuid'=>$vehicle,'payload_uuid'=>$earlyPayload,'status'=>'dispatched','dispatched'=>1]);
$boundedFeed=$handoverService->incoming(req([],$ht));
check($boundedFeed['assigned_trips_truncated']===true&&count($boundedFeed['assigned_trips'])<=100,'large early assignment feed explicitly reports truncation');
DB::table('orders')->whereIn('uuid',$boundedOrders)->delete();
DB::table('orders')->where('uuid',$earlyOrder)->delete();
DB::table('payloads')->where('uuid',$earlyPayload)->delete();

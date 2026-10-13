<?php
// Dedicated ephemeral QA database only. No provider calls or live credentials.
if(!isset($mapaDedicatedQaApp)) throw new RuntimeException('Hospital tests require disposable QA.');
foreach(['HospitalHandoverPolicy','HospitalHandoverService','HospitalHandoverController'] as $file) require_once __DIR__.'/../'.$file.'.php';
$handoverService=new App\MapaMobile\HospitalHandoverService;
config(['mapa_mobile.hospital_handover_enabled'=>true]);
$hp=row('places',['company_uuid'=>$company,'location'=>$point]);
$hpOther=row('places',['company_uuid'=>$company,'location'=>$point]);
$hpForeign=row('places',['company_uuid'=>$foreign,'location'=>$point]);
$hpPayload=row('payloads',['company_uuid'=>$company,'dropoff_uuid'=>$hp]);
$hpOrder=row('orders',['company_uuid'=>$company,'driver_assigned_uuid'=>$driver,'vehicle_assigned_uuid'=>$vehicle,'payload_uuid'=>$hpPayload,'status'=>'dispatched','dispatched'=>1]);
$hpId=DB::table('orders')->where('uuid',$hpOrder)->value('public_id');
$hpView=fn()=>collect($service->snapshot($service->context(req([],$token)))['orders'])->firstWhere('id',$hpId);
$hpBody=fn()=>['revision'=>$hpView()['revision'],'condition'=>'urgent','note'=>'DEMO observed condition only','preparation_note'=>'DEMO readiness request'];
denied(fn()=>$handoverService->onboard(req($hpBody(),$token),$hpId),409);
$service->mutate(req(['action_id'=>(string)Str::uuid(),'expected_revision'=>$hpView()['revision']],$token),'ack',$hpId);
denied(fn()=>$handoverService->onboard(req([...$hpBody(),'revision'=>str_repeat('0',64)],$token),$hpId),409);
denied(fn()=>$handoverService->onboard(req($hpBody()),$hpId),401);
$hpResult=$handoverService->onboard(req($hpBody(),$token),$hpId);
$hpReport=$hpResult['handover'];
check($hpReport['condition']==='urgent'&&$hpReport['status']==='incoming'&&$hpReport['revision']===1,'explicit onboard report persisted without inferred readiness');
check(!str_contains(DB::table('mapa_mobile_hospital_handovers')->where('id',$hpReport['id'])->value('report_encrypted'),'observed condition'),'patient notes encrypted at rest');
check(DB::table('orders')->where('uuid',$hpOrder)->value('status')==='dispatched','onboard report does not fabricate native workflow transition');
check($handoverService->onboard(req($hpBody(),$token),$hpId)['replayed'],'same report retry is idempotent');
denied(fn()=>$handoverService->onboard(req([...$hpBody(),'note'=>'DEMO changed'], $token),$hpId),409);
denied(fn()=>$handoverService->incoming(req([],$token)),403);
denied(fn()=>$handoverService->overview(req([],$token)),403);
$hpSessions=[];
foreach(['hospital'=>$hp,'other_hospital'=>$hpOther,'admin'=>null] as $label=>$destination){
 $email='demo-handover-'.$label.'@example.invalid';
 $uid=row('users',['email'=>$email,'password'=>Hash::make('DEMO-handover-test-only'),'status'=>'active','company_uuid'=>$company,'email_verified_at'=>now()]);
 row('company_users',['company_uuid'=>$company,'user_uuid'=>$uid,'status'=>'active']);
 $bid=(string)Str::uuid();
 DB::table('mapa_mobile_bindings')->insert(['id'=>$bid,'company_uuid'=>$company,'user_uuid'=>$uid,'role'=>$label==='admin'?'admin':'hospital','place_uuid'=>$destination,'enabled'=>true,'revision'=>1]);
 $hpSessions[$label]=['binding'=>$bid,'token'=>$service->login(req(['email'=>$email,'password'=>'DEMO-handover-test-only']))['token']];
}
$ht=$hpSessions['hospital']['token'];$ot=$hpSessions['other_hospital']['token'];$at=$hpSessions['admin']['token'];
require __DIR__.'/hospital-assigned-native-cases.php';
require __DIR__.'/trip-contacts-native-cases.php';
$feed=$handoverService->incoming(req([],$ht));
check(count($feed['incoming'])===1&&$feed['incoming'][0]['id']===$hpReport['id'],'only intended hospital receives patient handover');
check($feed['incoming'][0]['tracking']['stage']==='to_hospital'&&$feed['incoming'][0]['tracking']['caller_location_allowed']===false,'hospital sees native onboard trip tracking');
check($feed['incoming'][0]['tracking']['revision']===$hpView()['tracking']['revision'],'crew and receiving hospital share tracking revision');
check($feed['push_available']===false&&$feed['delivery_mode']==='authenticated_polling','no unconfigured push delivery claim');
check($handoverService->incoming(req([],$ot))['incoming']===[],'other hospital cannot list report');
require __DIR__.'/hospital-push-native-cases.php';
$respond=fn($status,$revision,$session)=>$handoverService->respond(req(['status'=>$status,'revision'=>$revision],$session),$hpReport['id']);
denied(fn()=>$respond('ready',1,$ot),404);
denied(fn()=>$respond('ready',1,$token),403);
denied(fn()=>$handoverService->overview(req([],$ht)),403);
$controller=$handoverService->overview(req([],$at));
check(count($controller['handovers'])===1&&$controller['handovers'][0]['status']==='incoming'&&$controller['handovers'][0]['current'],'controller sees explicit pending hospital state');
$accepted=$respond('acknowledged',1,$ht);
check($accepted['handover']['revision']===2&&$accepted['handover']['status']==='acknowledged','hospital acknowledgement revisions recorded');
denied(fn()=>$respond('ready',1,$ht),409);
$ready=$respond('ready',2,$ht);
check($ready['handover']['status']==='ready'&&DB::table('mapa_mobile_hospital_responses')->where('handover_id',$hpReport['id'])->count()===2,'readiness and immutable response audit saved once');
check($handoverService->overview(req([],$at))['handovers'][0]['status']==='ready','controller sees latest hospital readiness');
foreach(['dropoff_uuid'=>$hpOther,'company_uuid'=>$foreign] as $field=>$changed){
 $saved=DB::table('payloads')->where('uuid',$hpPayload)->value($field);
 DB::table('payloads')->where('uuid',$hpPayload)->update([$field=>$changed]);
 check($handoverService->incoming(req([],$ht))['incoming']===[],'changed/foreign payload hides report '.$field);
 denied(fn()=>$respond('ready',3,$ht),409);
 check(!$handoverService->overview(req([],$at))['handovers'][0]['current'],'controller knows handover became stale '.$field);
 check($handoverService->overview(req([],$at))['handovers'][0]['tracking']===null,'stale handover exposes no previous ambulance tracking '.$field);
 DB::table('payloads')->where('uuid',$hpPayload)->update([$field=>$saved]);
}
foreach(['driver_assigned_uuid'=>$otherDriver,'vehicle_assigned_uuid'=>$otherVehicle,'status'=>'completed','dispatched'=>0,'company_uuid'=>$foreign,'deleted_at'=>now()] as $field=>$changed){
 $saved=DB::table('orders')->where('uuid',$hpOrder)->value($field);
 DB::table('orders')->where('uuid',$hpOrder)->update([$field=>$changed]);
 check($handoverService->incoming(req([],$ht))['incoming']===[],'changed assignment or closed trip cannot expose report '.$field);
 denied(fn()=>$respond('ready',3,$ht),409);
 DB::table('orders')->where('uuid',$hpOrder)->update([$field=>$saved]);
}
DB::table('places')->where('uuid',$hp)->update(['company_uuid'=>$foreign]);
denied(fn()=>$handoverService->incoming(req([],$ht)),403);
DB::table('places')->where('uuid',$hp)->update(['company_uuid'=>$company]);
// A destination change requires an explicit fresh crew report, never silent
// forwarding of the previous hospital's clinical report or acknowledgement.
DB::table('payloads')->where('uuid',$hpPayload)->update(['dropoff_uuid'=>$hpOther]);
check($handoverService->incoming(req([],$ht))['incoming']===[]&&$handoverService->incoming(req([],$ot))['incoming']===[],'destination change awaits explicit handover transfer');
denied(fn()=>$handoverService->onboard(req($hpBody(),$token),$hpId),409);
$transfer=$handoverService->onboard(req([...$hpBody(),'confirm_transfer'=>true],$token),$hpId);
check($transfer['transferred']===true&&$transfer['handover']['status']==='incoming','reviewed transfer resets receiving readiness');
check($handoverService->incoming(req([],$ht))['incoming']===[]&&count($handoverService->incoming(req([],$ot))['incoming'])===1,'only current receiving hospital sees transferred trip');
check($handoverService->incoming(req([],$ot))['incoming'][0]['tracking']['revision']===$hpView()['tracking']['revision'],'new hospital and crew share current trip');
denied(fn()=>$respond('ready',$transfer['handover']['revision'],$ht),404);
check(DB::table('mapa_mobile_handover_revisions')->where('handover_id',$hpReport['id'])->count()===1,'previous handover preserved privately');
check(!str_contains(DB::table('mapa_mobile_handover_revisions')->where('handover_id',$hpReport['id'])->value('previous_encrypted'),'DEMO observed'),'handover history encrypted');
check($handoverService->onboard(req([...$hpBody(),'confirm_transfer'=>true],$token),$hpId)['replayed'],'transfer retry does not create duplicate history');
DB::table('payloads')->where('uuid',$hpPayload)->update(['dropoff_uuid'=>$hp]);
$handoverService->onboard(req([...$hpBody(),'confirm_transfer'=>true],$token),$hpId);
check(DB::table('mapa_mobile_handover_revisions')->where('handover_id',$hpReport['id'])->count()===2,'return transfer explicitly reviewed and audited');
DB::table('mapa_mobile_bindings')->where('id',$hpSessions['hospital']['binding'])->update(['place_uuid'=>$hpOther]);
denied(fn()=>$handoverService->incoming(req([],$ht)),401);
DB::table('mapa_mobile_bindings')->where('id',$hpSessions['hospital']['binding'])->update(['place_uuid'=>$hp]);
DB::table('mapa_mobile_sessions')->where('token_hash',hash('sha256',$ht))->update(['revoked_at'=>1]);
denied(fn()=>$handoverService->incoming(req([],$ht)),401);
denied(fn()=>$respond('ready',3,$ht),401);
DB::table('mapa_mobile_sessions')->where('token_hash',hash('sha256',$at))->update(['revoked_at'=>1]);
denied(fn()=>$handoverService->overview(req([],$at)),401);
config(['mapa_mobile.hospital_handover_enabled'=>false]);
denied(fn()=>$handoverService->incoming(req([],$ot)),503);
DB::table('orders')->where('uuid',$hpOrder)->delete();
DB::table('mapa_mobile_hospital_handovers')->where('id',$hpReport['id'])->delete();
DB::table('mapa_mobile_hospital_responses')->where('handover_id',$hpReport['id'])->delete();

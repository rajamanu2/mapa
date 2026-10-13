<?php
// Called only by the dedicated hospital suite; restore each synthetic field.
if(!isset($mapaDedicatedQaApp))throw new RuntimeException('Disposable QA required.');
$contactUser=DB::table('drivers')->where('uuid',$driver)->value('user_uuid');
$contactSavedUser=(array)DB::table('users')->where('uuid',$contactUser)->first();
$contactSavedHospital=(array)DB::table('places')->where('uuid',$hp)->first();
$contactSavedMeta=DB::table('orders')->where('uuid',$hpOrder)->value('meta');
DB::table('users')->where('uuid',$contactUser)->update(['phone'=>'+12025550142']);
DB::table('places')->where('uuid',$hp)->update(['phone'=>'+12025550143']);
DB::table('orders')->where('uuid',$hpOrder)->update(['meta'=>json_encode(['mapa_demo'=>true,'mapa_caller_phone'=>'+12025550144','mapa_dispatch_review'=>['caller_name'=>'DEMO Caller','callback_number'=>'+12025550145']])]);
$contactView=fn()=> $handoverService->incoming(req([],$ht))['incoming'][0]['contacts'];
$contact=$contactView();
check($contact['driver']['phone']==='+12025550142'&&$contact['caller']['phone']==='+12025550144'&&$contact['hospital']['phone']==='+12025550143','authorized receiving hospital has stored trip contacts');
check($hpView()['contacts']===$contact,'crew and hospital contact projection agrees');
DB::table('orders')->where('uuid',$hpOrder)->update(['meta'=>json_encode(['mapa_demo'=>true,'mapa_caller_phone'=>'','mapa_dispatch_review'=>['callback_number'=>'+12025550145']])]);
check($contactView()['caller']===null,'explicit callback clear cannot revive review number');
foreach(['company_uuid'=>$foreign,'deleted_at'=>now(),'phone'=>'tel:+12025550142'] as $field=>$value){
 DB::table('users')->where('uuid',$contactUser)->update([$field=>$value]);
 check($contactView()['driver']===null,'foreign/deleted/hostile driver user phone hidden '.$field);
 DB::table('users')->where('uuid',$contactUser)->update([$field=>$field==='phone'?'+12025550142':$contactSavedUser[$field]]);
}
DB::table('payloads')->where('uuid',$hpPayload)->update(['dropoff_uuid'=>$hpOther]);
$contactOverview=$handoverService->overview(req([],$at))['handovers'][0];
check($contactOverview['contacts']===App\MapaMobile\TripContacts::empty(),'stale handover never leaks current or previous contacts');
DB::table('payloads')->where('uuid',$hpPayload)->update(['dropoff_uuid'=>$hp]);
DB::table('orders')->where('uuid',$hpOrder)->update(['meta'=>$contactSavedMeta]);
DB::table('users')->where('uuid',$contactUser)->update(['phone'=>$contactSavedUser['phone']]);
DB::table('places')->where('uuid',$hp)->update(['phone'=>$contactSavedHospital['phone']]);

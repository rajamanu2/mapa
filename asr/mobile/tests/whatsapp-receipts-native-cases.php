<?php
// Isolated provider receipt tests. No external requests, numbers or real patient data.
use App\MapaMobile\WhatsAppWebhook;
use App\MapaMobile\WhatsAppWebhookController;
$webhook=new WhatsAppWebhook;
config(['mapa_mobile.whatsapp.app_secret'=>str_repeat('DEMO-secret-',4),'mapa_mobile.whatsapp.verify_token'=>str_repeat('DEMO-verify-',4)]);
function receiptBody(array $status, string $phone='1234567890123'):string{return json_encode(['object'=>'whatsapp_business_account','entry'=>[['changes'=>[['field'=>'messages','value'=>['metadata'=>['phone_number_id'=>$phone],'statuses'=>[$status]]]]]]]);}
function deliverReceipt(string $body):array{return (new WhatsAppWebhook)->receive($body,'sha256='.hash_hmac('sha256',$body,config('mapa_mobile.whatsapp.app_secret')));}
$stamp=time();$event=['id'=>'DEMO-message','status'=>'delivered','timestamp'=>(string)$stamp];
check($webhook->configured(),'receipts configured');
check($webhook->verify('subscribe',config('mapa_mobile.whatsapp.verify_token'),'12345')==='12345','verification challenge');
denied(fn()=>$webhook->verify('subscribe','forged','12345'),403);
denied(fn()=>$webhook->verify('unsubscribe',config('mapa_mobile.whatsapp.verify_token'),'12345'),403);
denied(fn()=>$webhook->verify('subscribe',config('mapa_mobile.whatsapp.verify_token'),'<script>'),400);
$raw=receiptBody($event);
denied(fn()=>$webhook->receive($raw,''),401);
denied(fn()=>$webhook->receive($raw,'sha256='.str_repeat('0',64)),401);
$sig='sha256='.hash_hmac('sha256',$raw,config('mapa_mobile.whatsapp.app_secret'));
denied(fn()=>$webhook->receive($raw.' ',$sig),401);
denied(fn()=>deliverReceipt('{'),400);
denied(fn()=>deliverReceipt(json_encode(['object'=>'foreign','entry'=>[]])),400);
denied(fn()=>deliverReceipt(str_repeat('x',262145)),413);
$waController=new WhatsAppWebhookController;
check($waController->receive(Illuminate\Http\Request::create('/','POST'))->getStatusCode()===415,'webhook JSON required');
$rr=$waController->receive(Illuminate\Http\Request::create('/','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],$raw));
check($rr->getStatusCode()===401&&str_contains($rr->headers->get('Cache-Control'),'no-store'),'forged controller no cache');
function messageStatus(string $action):string{return Illuminate\Support\Facades\DB::table('mapa_caller_messages')->where('action_id',$action)->value('status');}
check(deliverReceipt(receiptBody($event,'9999999999999'))['received'],'foreign sender acknowledged without writes');
check(messageStatus('DEMO_send_0001')==='accepted','foreign phone did not change status');
deliverReceipt(receiptBody([...$event,'id'=>'DEMO-unrelated']));check(messageStatus('DEMO_send_0001')==='accepted','unknown provider ID ignored');
deliverReceipt(receiptBody([...$event,'timestamp'=>(string)($stamp+600)]));check(messageStatus('DEMO_send_0001')==='accepted','future timestamps ignored');
deliverReceipt($raw);check(messageStatus('DEMO_send_0001')==='delivered','valid delivery correlated by phone and provider ID');
$before=Illuminate\Support\Facades\DB::table('mapa_caller_messages')->where('action_id','DEMO_send_0001')->first();
deliverReceipt($raw);$after=Illuminate\Support\Facades\DB::table('mapa_caller_messages')->where('action_id','DEMO_send_0001')->first();check((array)$before===(array)$after,'duplicate receipt no mutation');
deliverReceipt(receiptBody([...$event,'status'=>'sent','timestamp'=>(string)($stamp-2)]));check(messageStatus('DEMO_send_0001')==='delivered','late sent cannot downgrade delivery');
deliverReceipt(receiptBody([...$event,'status'=>'failed','errors'=>[['code'=>131000,'message'=>'DEMO private payload never persisted']]]));check(messageStatus('DEMO_send_0001')==='delivered','failed cannot contradict proven delivery');
deliverReceipt(receiptBody([...$event,'status'=>'read']));check(messageStatus('DEMO_send_0001')==='read','read advances delivery');
deliverReceipt($raw);check(messageStatus('DEMO_send_0001')==='read','late delivery cannot downgrade read');
$list=$messages->forCall(trackingRequest([],$admin),$call)['messages'];
$read=array_values(array_filter($list,fn($m)=>$m['action_id']==='DEMO_send_0001'))[0];
check($read['delivered']&&$read['read']&&$read['provider_accepted'],'operator distinguishes delivery and read');
check(!isset($read['provider_id'],$read['callback_hash'],$read['token'],$read['phone']),'operator status avoids credentials and recipient disclosure');
denied(fn()=>$messages->forCall(trackingRequest([]),$call),401);
denied(fn()=>$messages->forCall(trackingRequest([],$admin),$foreignCall),404);
// Correlation is registered before send; receipt can beat both accepted response and timeout.
foreach(['accepted','timeout','failed'] as $scenario){
 $link=$service->issue(trackingRequest(['consent_to_message'=>true],$admin),$call);
 $body=['action_id'=>'DEMO_race_'.$scenario,'token'=>$link['token'],'consent_to_message'=>true];
 Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory);
 Illuminate\Support\Facades\Http::fake(function($request)use($scenario,$stamp){
  $callback=$request->data()['biz_opaque_callback_data'];
  check(strlen($callback)===64,'random opaque correlation sent');
  check(Illuminate\Support\Facades\DB::table('mapa_caller_messages')->where('callback_hash',hash('sha256',$callback))->exists(),'only correlation hash stored before sending');
  $event=['id'=>'DEMO-race-'.$scenario,'status'=>$scenario==='failed'?'failed':'read','timestamp'=>(string)$stamp,'biz_opaque_callback_data'=>$callback,'errors'=>[['code'=>131026,'message'=>'DEMO not persisted']]];
  deliverReceipt(receiptBody([...$event,'biz_opaque_callback_data'=>str_repeat('0',64)]));
  check(messageStatus('DEMO_race_'.$scenario)==='unknown','unmatched callback cannot update pending attempt');
  deliverReceipt(receiptBody($event));
  if($scenario==='timeout')throw new Illuminate\Http\Client\ConnectionException('DEMO ambiguous timeout after receipt');
  return Illuminate\Support\Facades\Http::response(['messages'=>[['id'=>$event['id']]]],200);
 });
 $out=$messages->send(trackingRequest($body,$admin),$call);
 check($out['status']===($scenario==='failed'?'failed':'read'),'send response does not overwrite receipt '.$scenario);
 check($out['delivered']===($scenario!=='failed'),'delivery remains accurate '.$scenario);
 check($messages->send(trackingRequest($body,$admin),$call)['replayed'],'race replay idempotent '.$scenario);
 check(Illuminate\Support\Facades\Http::recorded()->count()<=1,'no retry '.$scenario);
 if($scenario==='failed'){
  check($out['failure_code']===131026,'only numeric provider failure code retained');
  deliverReceipt(receiptBody(['id'=>'DEMO-race-failed','status'=>'sent','timestamp'=>(string)($stamp+1)]));check(messageStatus($body['action_id'])==='failed','sent does not override failure');
  deliverReceipt(receiptBody(['id'=>'DEMO-race-failed','status'=>'delivered','timestamp'=>(string)($stamp+1)]));check(messageStatus($body['action_id'])==='delivered','proven delivery overrides failure');
  check(Illuminate\Support\Facades\DB::table('mapa_caller_messages')->where('action_id',$body['action_id'])->value('failure_code')===null,'delivery clears failure');
 }
}
config(['mapa_mobile.whatsapp.app_secret'=>'']);check(!$webhook->configured(),'missing secret disables receipts');denied(fn()=>deliverReceipt($raw),503);
echo "$count native integration checks passed; WhatsApp receipts use only signed synthetic payloads.\n";

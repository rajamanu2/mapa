<?php
namespace App\MapaMobile;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Only authenticated status metadata is retained; never messages, contacts or raw payloads. */
class WhatsAppWebhook
{
 public function configured():bool {
  return (bool)config('mapa_mobile.whatsapp.enabled',false)
   && strlen((string)config('mapa_mobile.whatsapp.app_secret'))>=20
   && strlen((string)config('mapa_mobile.whatsapp.verify_token'))>=20
   && preg_match('/^[0-9]{5,30}$/D',(string)config('mapa_mobile.whatsapp.phone_id'))
   && Schema::hasColumn('mapa_caller_messages','callback_hash');
 }
 public function verify($mode,$token,$challenge):string {
  if(!$this->configured())MobileService::fail('Webhook unavailable.',503);
  if($mode!=='subscribe'||!is_string($token)||!hash_equals((string)config('mapa_mobile.whatsapp.verify_token'),$token))MobileService::fail('Verification denied.',403);
  if(!is_string($challenge)||!preg_match('/^[0-9]{1,100}$/D',$challenge))MobileService::fail('Invalid challenge.',400);
  return $challenge;
 }
 public function receive(string $raw,string $signature):array {
  if(strlen($raw)>262144)MobileService::fail('Payload too large.',413);
  if(!$this->configured())MobileService::fail('Webhook unavailable.',503);
  if(!preg_match('/^sha256=[a-f0-9]{64}$/D',$signature)||!hash_equals('sha256='.hash_hmac('sha256',$raw,(string)config('mapa_mobile.whatsapp.app_secret')),$signature))MobileService::fail('Signature denied.',401);
  try{$body=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable $e){MobileService::fail('Invalid JSON.',400);}
  if(!is_array($body)||($body['object']??null)!=='whatsapp_business_account'||!is_array($body['entry']??null)||count($body['entry'])>100)MobileService::fail('Invalid webhook.',400);
  $events=[];
  foreach($body['entry'] as $entry){
   if(!is_array($entry)||!is_array($entry['changes']??null)||count($entry['changes'])>100)MobileService::fail('Invalid webhook.',400);
   foreach($entry['changes'] as $change){
    if(!is_array($change)||($change['field']??null)!=='messages')continue;
    $value=$change['value']??[];
    if(!is_array($value)||($value['metadata']['phone_number_id']??null)!==(string)config('mapa_mobile.whatsapp.phone_id'))continue;
    $statuses=$value['statuses']??[];
    if(!is_array($statuses)||count($statuses)>100)MobileService::fail('Too many statuses.',400);
    foreach($statuses as $event){
     if(count($events)>=100)MobileService::fail('Too many statuses.',400);
     if(!is_array($event))MobileService::fail('Invalid status.',400);
     $id=$event['id']??null;$status=$event['status']??null;$stamp=$event['timestamp']??null;
     if(!is_string($id)||$id===''||strlen($id)>255||!in_array($status,['sent','delivered','read','failed'],true))continue;
     if((!is_string($stamp)&&!is_int($stamp))||!preg_match('/^[0-9]{1,12}$/D',(string)$stamp)||(int)$stamp<1||(int)$stamp>time()+300)continue;
     $callback=$event['biz_opaque_callback_data']??null;
     if(!is_string($callback)||!preg_match('/^[a-f0-9]{64}$/D',$callback))$callback=null;
     $code=$event['errors'][0]['code']??null;
     $events[]=['id'=>$id,'status'=>$status,'timestamp'=>(int)$stamp,'callback'=>$callback,'code'=>is_int($code)&&$code>=0&&$code<=2147483647?$code:null];
    }
   }
  }
  DB::transaction(function()use($events){foreach($events as $event)$this->apply($event);});
  return ['received'=>true];
 }
 private function apply(array $e):void {
  $q=DB::table('mapa_caller_messages')->where('provider_phone_id',(string)config('mapa_mobile.whatsapp.phone_id'));
  if($e['callback']!==null)$q->where('callback_hash',hash('sha256',$e['callback']));else $q->where('provider_id',$e['id']);
  $row=$q->lockForUpdate()->first();
  if(!$row||($row->provider_id!==null&&!hash_equals($row->provider_id,$e['id'])))return;
  // Delivery/read evidence wins over failure and cannot be undone by delayed events.
  $rank=['unknown'=>0,'rejected'=>0,'accepted'=>1,'sent'=>2,'failed'=>3,'delivered'=>4,'read'=>5];
  if(($rank[$e['status']]??0)<=($rank[$row->status]??0))return;
  DB::table('mapa_caller_messages')->where(['company_uuid'=>$row->company_uuid,'action_id'=>$row->action_id])->update([
   'provider_id'=>$e['id'],'status'=>$e['status'],'provider_timestamp'=>max((int)$row->provider_timestamp,$e['timestamp']),
   'failure_code'=>$e['status']==='failed'?$e['code']:null,'updated_at'=>now()]);
 }
}

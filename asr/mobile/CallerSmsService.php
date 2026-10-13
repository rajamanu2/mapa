<?php
namespace App\MapaMobile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
/** Authenticated operator notification with durable at-most-once attempt per link. */
class CallerSmsService extends MobileService {
 private function staff(Request $r,bool $lock=false):array {$c=$this->context($r,$lock);if(!in_array($c['binding']->role,['operator','admin','superadmin'],true))self::fail('Dispatcher access required.');return $c;}
 public function status(Request $r):array {$this->staff($r);return ['configured'=>config('mapa_mobile.caller_tracking_enabled',false)&&(new SmsTransport)->configured()&&Schema::hasTable('mapa_caller_sms')&&Schema::hasColumn('mapa_tracking_links','scope'),'delivery_receipts'=>false,'transport'=>'gammu_smsd_gateway'];}
 public static function summary(object $row):array {return ['action_id'=>$row->action_id,'status'=>$row->status,'queued'=>$row->status==='queued','delivered'=>false,'delivery_confirmed'=>false,'updated_at'=>$row->updated_at];}
 public function forCall(Request $r,string $callId):array {
  $c=$this->staff($r);
  if(!DB::table('mapa_voice_calls')->where(['id'=>$callId,'company_uuid'=>$c['company']])->exists())self::fail('Call not found.',404);
  if(!Schema::hasTable('mapa_caller_sms'))return ['messages'=>[],'configured'=>false];
  return ['messages'=>DB::table('mapa_caller_sms')->where(['company_uuid'=>$c['company'],'call_id'=>$callId])->orderByDesc('created_at')->limit(20)->get()->map(fn($row)=>self::summary($row))->all()];
 }
 public function send(Request $r,string $callId):array {return $this->attempt($r,$callId,false);}
 public function auto(Request $r,string $callId):array {return $this->attempt($r,$callId,true);}
 private function replayAuto(object $row,object $call,string $phone):array {
  $link=DB::table('mapa_tracking_links')->where(['id'=>$row->link_id,'company_uuid'=>$call->company_uuid,'call_id'=>$call->id])->first();
  if(!ConnectedTrackingPolicy::active($link,(int)(microtime(true)*1000))||($link->scope??'pickup')!=='full_journey'
   ||!hash_equals((string)($link->callback_hash??''),hash('sha256',$phone))
   ||(($link->order_uuid??null)!==null&&$link->order_uuid!==$call->order_uuid))self::fail('Previous SMS link expired, changed or was revoked. Review and explicitly send a new link.',409);
  return ['replayed'=>true,...self::summary($row)];
 }
 private function attempt(Request $r,string $callId,bool $automatic):array {
  $this->staff($r);
  if(!config('mapa_mobile.caller_tracking_enabled')||!Schema::hasTable('mapa_caller_sms')||!(new SmsTransport)->configured())self::fail('SMS delivery is not configured.',503);
  $rules=['action_id'=>'required|string|regex:/^[A-Za-z0-9_-]{8,100}$/D','consent_to_message'=>'required|accepted'];
  if(!$automatic)$rules['token']='required|string|regex:/^[a-f0-9]{64}$/D';
  if($automatic)$rules['expected_callback_number']='required|string|max:30';
  $data=$r->validate($rules);
  if($r->input('consent_to_message')!==true)self::fail('Confirm permission to send this caller a service SMS.',422);
  $origin=rtrim((string)config('mapa_mobile.tracking_origin'),'/');$parts=parse_url($origin);
  if(!filter_var($origin,FILTER_VALIDATE_URL)||($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||!empty($parts['path']))self::fail('Public tracking origin is not configured.',503);
  $prepared=DB::transaction(function()use($r,$callId,$data,$origin,$automatic){
   $c=$this->staff($r,true);
   $call=DB::table('mapa_voice_calls')->where(['id'=>$callId,'company_uuid'=>$c['company']])->lockForUpdate()->first();
   if(!$call||$call->demo||ConnectedTrackingPolicy::closed($call->state))self::fail('Active call not found.',404);
   if(ConnectedTrackingPolicy::supervisedPilot($call))self::fail('A no-patient pilot has no SMS recipient.',409);
   (new SourceCallStore)->assertCall($call);
   if($call->order_uuid){$order=DB::table('orders')->where(['company_uuid'=>$c['company'],'uuid'=>$call->order_uuid])->whereNull('deleted_at')->first();if(!$order||ConnectedTrackingPolicy::closed($order->status))self::fail('Trip has ended.',409);}
   $review=json_decode($call->review?:$call->draft,true,64,JSON_THROW_ON_ERROR);
   try{$phone=CallerPhone::normalize($review['callback_number']??'');}catch(\InvalidArgumentException $e){self::fail('Review the callback number.',422);}
   if($phone==='')self::fail('Save a callback number before sending.',422);
   if($automatic){try{$expected=CallerPhone::normalize($data['expected_callback_number']);}catch(\InvalidArgumentException $e){self::fail('Review the expected callback number.',422);}if($expected!==$phone)self::fail('Callback number changed. Review and confirm SMS permission again.',409);}
   $phoneHash=hash_hmac('sha256',$phone,(string)config('app.key'));
   if($automatic){
    // Same call row lock serializes auto-issue and all SMS reservations. Check
    // before issue(), which otherwise revokes a previously delivered link.
    $priorAction=DB::table('mapa_caller_sms')->where(['company_uuid'=>$c['company'],'action_id'=>$data['action_id']])->first();
    if($priorAction){if($priorAction->call_id!==$callId||!$priorAction->automatic||!hash_equals($priorAction->phone_hash,$phoneHash))self::fail('SMS action was already used for a different request.',409);return $this->replayAuto($priorAction,$call,$phone);}
    $prior=DB::table('mapa_caller_sms')->where(['company_uuid'=>$c['company'],'call_id'=>$callId,'phone_hash'=>$phoneHash,'automatic'=>true])->first();
    if($prior)return $this->replayAuto($prior,$call,$phone);
    $issue=clone $r;$issue->replace(['consent_to_message'=>true,'scope'=>'full_journey']);
    $issued=(new ConnectedTrackingService)->issue($issue,$callId);$data['token']=$issued['token'];
   }
   $link=DB::table('mapa_tracking_links')->where(['company_uuid'=>$c['company'],'call_id'=>$callId,'token_hash'=>hash('sha256',$data['token'])])->whereNull('revoked_at')->where('expires_at','>',(int)(microtime(true)*1000))->first();
   if(!$link)self::fail('Tracking link expired or does not belong to this call.',409);
   if(($link->scope??'pickup')==='full_journey'&&!hash_equals((string)($link->callback_hash??''),hash('sha256',$phone)))self::fail('Caller number changed. Review the tracking link.',409);
   $key=['company_uuid'=>$c['company'],'action_id'=>$data['action_id']];$hash=Policy::fingerprint('sms',[$callId,$link->id,$phone]);
   $old=DB::table('mapa_caller_sms')->where($key)->first();
   if($old){if(!hash_equals($old->request_hash,$hash))self::fail('SMS request changed. Review before sending again.',409);return ['replayed'=>true,...self::summary($old)];}
   if(DB::table('mapa_caller_sms')->where(['company_uuid'=>$c['company'],'link_id'=>$link->id])->exists())self::fail('This link already has an SMS attempt. Check with the caller before issuing another.',409);
   $id=bin2hex(random_bytes(32));
   DB::table('mapa_caller_sms')->insert([...$key,'call_id'=>$callId,'link_id'=>$link->id,'created_by'=>$c['user']->uuid,'request_hash'=>$hash,'gateway_id'=>$id,'status'=>'unknown','automatic'=>$automatic,'phone_hash'=>$phoneHash,'consented_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
   return ['key'=>$key,'id'=>$id,'phone'=>$phone,'url'=>$origin.'/track/#'.$data['token']];
  });
  if(isset($prepared['replayed']))return $prepared;
  $result=(new SmsTransport)->send($prepared['id'],$prepared['phone'],$prepared['url']);
  DB::table('mapa_caller_sms')->where($prepared['key'])->update(['status'=>$result['status'],'updated_at'=>now()]);
  return ['replayed'=>false,...self::summary(DB::table('mapa_caller_sms')->where($prepared['key'])->first())];
 }
}

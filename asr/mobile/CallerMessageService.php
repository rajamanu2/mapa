<?php
namespace App\MapaMobile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
/** Dispatcher-only, explicit opt-in notification. Never retries an ambiguous send. */
class CallerMessageService extends MobileService
{
 private function transport(): WhatsAppTransport|WahaCallerTransport {
  return config('mapa_mobile.whatsapp.waha_caller_enabled') ? new WahaCallerTransport : new WhatsAppTransport;
 }
 public function status(Request $r):array {
  $this->staffContext($r);
  $waha=(bool)config('mapa_mobile.whatsapp.waha_caller_enabled');
  return ['configured'=>config('mapa_mobile.caller_tracking_enabled') && $this->transport()->configured() && Schema::hasColumn('mapa_caller_messages','callback_hash'),
   'delivery_receipts'=>!$waha && (bool)(new WhatsAppWebhook)->configured(), 'transport'=>$waha?'waha':'meta'];
 }
 public static function summary(object $row):array {
  return ['action_id'=>$row->action_id,'status'=>$row->status,'provider_accepted'=>in_array($row->status,['accepted','sent','delivered','read','failed'],true),
   'delivered'=>in_array($row->status,['delivered','read'],true),'read'=>$row->status==='read','failure_code'=>$row->failure_code??null,
   'provider_timestamp'=>$row->provider_timestamp??null,'updated_at'=>$row->updated_at];
 }
 public function forCall(Request $r,string $callId):array {
  $c=$this->staffContext($r);
  if(!DB::table('mapa_voice_calls')->where(['id'=>$callId,'company_uuid'=>$c['company']])->exists())self::fail('Call not found.',404);
  return ['messages'=>DB::table('mapa_caller_messages')->where(['company_uuid'=>$c['company'],'call_id'=>$callId])->orderByDesc('created_at')->limit(20)->get()->map(fn($row)=>self::summary($row))->all()];
 }
 private function staffContext(Request $r,bool $lock=false):array {
  $c=$this->context($r,$lock);if(!in_array($c['binding']->role,['operator','admin','superadmin'],true))self::fail('Dispatcher access required.');return $c;
 }
 public function send(Request $r,string $callId):array {
  $this->staffContext($r);
  $transport=$this->transport();
  if(!config('mapa_mobile.caller_tracking_enabled') || !Schema::hasColumn('mapa_caller_messages','callback_hash') || !$transport->configured()) self::fail('WhatsApp delivery is not configured.',503);
  $data=$r->validate(['action_id'=>'required|string|regex:/^[A-Za-z0-9_-]{8,100}$/D','token'=>'sometimes|string|regex:/^[a-f0-9]{64}$/D','issue_new'=>'sometimes|boolean','replace_existing'=>'sometimes|boolean','consent_to_message'=>'required|accepted']);
  $issueNew=$r->input('issue_new',false);
  if(!is_bool($issueNew)||($issueNew===isset($data['token'])))self::fail('Choose either an existing private link or a new link for this message.',422);
  $replace=$r->input('replace_existing',false);
  if(!is_bool($replace)||($replace&&!$issueNew))self::fail('Replacing an existing link must be an explicit new-link choice.',422);
  $origin=rtrim((string)config('mapa_mobile.tracking_origin'),'/');
  if(!filter_var($origin,FILTER_VALIDATE_URL)||parse_url($origin,PHP_URL_SCHEME)!=='https'||parse_url($origin,PHP_URL_QUERY)||parse_url($origin,PHP_URL_FRAGMENT)||parse_url($origin,PHP_URL_USER)||!in_array(parse_url($origin,PHP_URL_PATH),[null,'','/'],true))self::fail('The public tracking origin must be configured.',503);
  $prepared=DB::transaction(function()use($r,$callId,$data,$origin,$issueNew,$replace){
   $c=$this->staffContext($r,true);
   $call=DB::table('mapa_voice_calls')->where(['id'=>$callId,'company_uuid'=>$c['company']])->lockForUpdate()->first();
   if($call)SourceCallStore::assertCall($call);
   if(!$call || $call->demo || in_array($call->state,['dismissed','completed','canceled','cancelled','failed'],true))self::fail('Active call not found.',404);
   if(ConnectedTrackingPolicy::supervisedPilot($call))self::fail('A no-patient pilot has no WhatsApp recipient.',409);
   if($call->order_uuid){$order=DB::table('orders')->where(['company_uuid'=>$c['company'],'uuid'=>$call->order_uuid])->whereNull('deleted_at')->first();if(!$order||in_array($order->status,['completed','canceled','cancelled','failed'],true))self::fail('Trip has ended.',409);}
   $review=json_decode($call->review?:$call->draft,true,64,JSON_THROW_ON_ERROR);
   try{$phone=CallerPhone::normalize($review['callback_number']??'');}catch(\InvalidArgumentException $e){self::fail('Review the callback number.',422);}
   if($phone==='')self::fail('Save a callback number before sending.',422);
   $key=['company_uuid'=>$c['company'],'action_id'=>$data['action_id']];
   // A retry after a lost response must never mint another link or send again.
   if($issueNew){
    $prior=DB::table('mapa_caller_messages')->where($key)->first();
    if($prior){
     $priorHash=Policy::fingerprint('whatsapp',[$callId,$prior->link_id,$phone]);
     if($prior->call_id!==$callId||!hash_equals((string)$prior->request_hash,$priorHash))self::fail('Message request changed. Review before sending again.',409);
     return ['replayed'=>true,...self::summary($prior)];
    }
    $request=clone $r;$request->replace(['consent_to_message'=>true,'scope'=>'full_journey','replace_existing'=>$replace]);
    $issued=(new ConnectedTrackingService)->issue($request,$callId);
    $rawToken=$issued['token'];
   }else $rawToken=$data['token'];
   $link=DB::table('mapa_tracking_links')->where(['company_uuid'=>$c['company'],'call_id'=>$callId,'token_hash'=>hash('sha256',$rawToken)])->whereNull('revoked_at')->where('expires_at','>',(int)(microtime(true)*1000))->first();
   if(!$link)self::fail('Tracking link expired or does not belong to this call.',409);
   if(($link->scope??'pickup')!=='full_journey' || !is_string($link->callback_hash)
      || !hash_equals($link->callback_hash,hash('sha256',$phone)))self::fail('Caller number changed or this link is not bound to the caller. Create a new tracker link.',409);
   $hash=Policy::fingerprint('whatsapp',[$callId,$link->id,$phone]);
   $old=DB::table('mapa_caller_messages')->where($key)->first();
   if($old){if(!hash_equals($old->request_hash,$hash))self::fail('Message request changed. Review before sending again.',409);return ['replayed'=>true,...self::summary($old)];}
   // A link is sent at most once, including unknown network results.
   if(DB::table('mapa_caller_messages')->where(['company_uuid'=>$c['company'],'link_id'=>$link->id])->exists())self::fail('This link already has a send attempt. Verify receipt with the caller before issuing another link.',409);
   $callback=bin2hex(random_bytes(32));
   $providerPhoneId=config('mapa_mobile.whatsapp.waha_caller_enabled') ? config('mapa_text.phone_id') : config('mapa_mobile.whatsapp.phone_id');
   DB::table('mapa_caller_messages')->insert([...$key,'link_id'=>$link->id,'call_id'=>$callId,'created_by'=>$c['user']->uuid,'request_hash'=>$hash,'callback_hash'=>hash('sha256',$callback),'provider_phone_id'=>(string)$providerPhoneId,'status'=>'unknown','consented_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
   return ['key'=>$key,'phone'=>$phone,'callback'=>$callback,'url'=>$origin.'/track/#'.$rawToken,
    'tracker_link'=>$issueNew?['id'=>$issued['id'],'token'=>$rawToken,'url'=>$issued['url'],'expires_at'=>$issued['expires_at'],'scope'=>$issued['scope']]:null];
  });
  if(isset($prepared['replayed']))return $prepared;
  $result=$transport->send($prepared['phone'],$prepared['url'],$prepared['callback']);
  return DB::transaction(function()use($prepared,$result){
   $row=DB::table('mapa_caller_messages')->where($prepared['key'])->lockForUpdate()->first();
   // A signed receipt may precede this HTTP response (or a timeout). Never overwrite its evidence.
   if($row->status==='unknown'&&$row->provider_id===null){DB::table('mapa_caller_messages')->where($prepared['key'])->update(['status'=>$result['status'],'provider_id'=>$result['message_id'],'updated_at'=>now()]);$row=DB::table('mapa_caller_messages')->where($prepared['key'])->first();}
   return ['replayed'=>false,...self::summary($row),...($prepared['tracker_link']?['tracker_link'=>$prepared['tracker_link']]:[])];
  });
 }
}

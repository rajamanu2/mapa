<?php
namespace App\MapaText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
class OAuthFlowGuard {
 public function authorize(Request $r){
  abort_unless($r->isJson(),415);abort_if(strlen($r->getContent())>4096,413);
  abort_unless(config('mapa_text.enabled'),503,'Text Flow disabled.');
  $key=(string)config('mapa_text.flow_key');abort_unless(strlen($key)>=32&&hash_equals($key,(string)$r->header('X-MAPA-Flow-Key')),401);
  $token=(string)$r->bearerToken();abort_unless(strlen($token)>=20&&strlen($token)<=4096&&!preg_match('/[\x00-\x20\x7f]/',$token),401);
  $url=(string)config('mapa_text.oauth_introspection_url');$client=(string)config('mapa_text.oauth_client_id');$secret=(string)config('mapa_text.oauth_client_secret');
  abort_unless(SecurityPolicy::httpsEndpoint($url)&&$client!==''&&$secret!=='',503,'OAuth provider is not configured.');
  try{$response=Http::asForm()->withBasicAuth($client,$secret)->timeout(8)->connectTimeout(3)->withoutRedirecting()->post($url,['token'=>$token,'token_type_hint'=>'access_token']);
   $claims=$response->successful()?$response->json():null;
  }catch(\Throwable $e){abort(503,'OAuth validation unavailable.');}
  abort_unless(is_array($claims)&&SecurityPolicy::oauth($claims,(string)config('mapa_text.oauth_allowed_client'),(string)config('mapa_text.oauth_audience'),(string)config('mapa.company_uuid'),time()),403,'OAuth token is not authorized.');
  $v=$r->validate(['message_id'=>'required|uuid']);$m=DB::table('mapa_text_messages')->where(['id'=>$v['message_id'],'company_uuid'=>config('mapa.company_uuid'),'status'=>'sending','direction'=>'out'])->first();abort_unless($m,409,'Message is not pending sending.');
  $conv=DB::table('mapa_text_conversations')->where(['id'=>$m->conversation_id,'company_uuid'=>$m->company_uuid,'state'=>'open'])->first();
  $s=DB::table('mapa_text_settings')->where(['company_uuid'=>$m->company_uuid,'enabled'=>true])->first();
  $call=$conv?DB::table('mapa_voice_calls')->where(['id'=>$conv->call_id,'company_uuid'=>$m->company_uuid,'demo'=>false])->first():null;
  abort_unless($conv&&$s&&$call&&$m->sender===config('mapa_text.default_sender')&&$m->phone_id===config('mapa_text.phone_id'),403,'Caller or sender is no longer authorized.');
  try{$tracking=PrivateLink::decode($m->tracking_url);}catch(\Throwable $e){abort(403,'Tracker unavailable.');}
  $url=parse_url($tracking);$secretLink=(string)($url['fragment']??'');
  $link=DB::table('mapa_tracking_links')->where(['company_uuid'=>$m->company_uuid,'call_id'=>$call->id,'token_hash'=>hash('sha256',$secretLink)])->whereNull('revoked_at')->where('expires_at','>',(int)(microtime(true)*1000))->first();
  abort_unless($link&&($link->scope??'')==='full_journey'&&hash_equals((string)$link->callback_hash,hash('sha256',$m->recipient)),403,'Tracker access expired or changed.');
  // A one-time claim prevents replaying a captured workflow request.
  abort_unless(DB::table('mapa_text_send_claims')->insertOrIgnore(['message_id'=>$m->id,'claimed_at'=>now()])===1,409,'Send authorization already used.');
  return response()->json(['payload'=>['message_id'=>$m->id,'phone_id'=>$m->phone_id,'to'=>ltrim($m->recipient,'+'),'template'=>$m->template,'language'=>$m->language,'reference'=>$m->body,'tracking_url'=>$tracking]])->header('Cache-Control','no-store')->header('X-Content-Type-Options','nosniff');
 }
}

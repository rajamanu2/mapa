<?php
namespace App\MapaText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\MapaMobile\ConsoleEnrollmentService;
use App\MapaMobile\CallerPhone;
class TextController {
 private function staff(Request $r): array {
  abort_if(strlen($r->getContent())>65536,413);
  $c=(new ConsoleEnrollmentService)->context($r);
  abort_unless(in_array($c['binding']->role,['admin','superadmin'],true),403);
  if($r->isMethod('POST'))abort_unless($r->isJson(),415);
  return $c;
 }
 private function reply(array $v){return response()->json(['contract'=>'mapa-text-v1',...$v])->header('Cache-Control','no-store')->header('X-Content-Type-Options','nosniff');}
 private function audit(array $c,string $action,?string $id=null): void {DB::table('mapa_text_audit')->insert(['company_uuid'=>$c['company'],'user_uuid'=>$c['user']->uuid,'action'=>$action,'record_id'=>$id,'created_at'=>now()]);}
 private function transportReady(): bool {return config('mapa_text.transport')==='waha'?strlen((string)config('mapa_text.bridge_receipt_key'))>=32:(bool)config('mapa_text.meta_secret')&&(bool)config('mapa_text.verify_token');}
 public function desk(Request $r){
  $c=$this->staff($r);$company=$c['company'];$page=max(1,min(10000,(int)$r->query('page',1)));
  $q=DB::table('mapa_text_messages')->where('company_uuid',$company);
  $status=$r->query('status');if(in_array($status,['queued','sending','sent','delivered','read','failed','unknown','received'],true))$q->where('status',$status);
  $search=trim((string)$r->query('search',''));if($search!=='')$q->where('recipient','like','%'.substr(preg_replace('/[^+0-9]/','',$search),0,24).'%');
  $count=(clone $q)->count();
  $delivery=DB::table('mapa_text_messages')->where('company_uuid',$company)->where('direction','out')
   ->select('status',DB::raw('COUNT(*) AS total'))->groupBy('status')->pluck('total','status');
  $conversationCounts=DB::table('mapa_text_conversations')->where('company_uuid',$company)
   ->select('state',DB::raw('COUNT(*) AS total'))->groupBy('state')->pluck('total','state');
  $calls=DB::table('mapa_voice_calls')->where('company_uuid',$company)->where('demo',false)->orderByDesc('created_at')->limit(100)->get(['id','caller','state','created_at']);
  return $this->reply(['company_id'=>$company,'settings'=>DB::table('mapa_text_settings')->where('company_uuid',$company)->first()??['enabled'=>false,'sender'=>config('mapa_text.default_sender'),'phone_id'=>config('mapa_text.phone_id'),'template'=>'mapa_call_update','language'=>'en'],
   'configured'=>(bool)config('mapa_text.enabled')&&config('mapa_text.n8n_url')&&config('mapa_text.flow_key')&&$this->transportReady(),'transport'=>config('mapa_text.transport'),
   'messages'=>$q->orderByDesc('created_at')->offset(($page-1)*50)->limit(50)->get()->map(function($m){unset($m->tracking_url);return $m;}),'page'=>$page,'total'=>$count,
   'delivery_counts'=>$delivery,'conversation_counts'=>$conversationCounts,
   'conversations'=>DB::table('mapa_text_conversations')->where('company_uuid',$company)->orderByDesc('updated_at')->limit(100)->get()->map(function($c){unset($c->tracking_url);return $c;}),'calls'=>$calls]);
 }
 public function settings(Request $r){
  $c=$this->staff($r);$waha=config('mapa_text.transport')==='waha';
  $v=$r->validate(['enabled'=>'required|boolean','sender'=>'required|string|regex:/^\+[1-9][0-9]{7,14}$/',
   'phone_id'=>$waha?'nullable|string|regex:/^[0-9]{5,80}$/':'required|string|regex:/^[0-9]{5,80}$/',
   'template'=>'required|string|regex:/^[a-z0-9_]{1,120}$/','language'=>'required|string|regex:/^[a-zA-Z_]{2,16}$/']);
  if($waha)$v['phone_id']=substr($v['sender'],1);
  abort_if($v['enabled']&&($v['sender']!==config('mapa_text.default_sender')||$v['phone_id']!==config('mapa_text.phone_id')||!SecurityPolicy::httpsEndpoint((string)config('mapa_text.oauth_introspection_url'))||!config('mapa_text.oauth_client_secret')||!config('mapa_text.oauth_allowed_client')||strlen((string)config('mapa_text.flow_key'))<32),422,'Sender and OAuth connection must be configured on the server first.');
  abort_if($v['enabled']&&(!config('mapa_text.enabled')||!config('mapa_text.flow_key')||!config('mapa_text.n8n_url')||!$this->transportReady()),422,'Server connection is not configured.');
  abort_if(DB::table('mapa_text_settings')->where('phone_id',$v['phone_id'])->where('company_uuid','<>',$c['company'])->exists(),409,'Sender already belongs to another company.');
  abort_if(DB::table('mapa_text_messages')->where('phone_id',$v['phone_id'])->where('company_uuid','<>',$c['company'])->exists(),409,'Sender has history in another company.');
  DB::table('mapa_text_settings')->updateOrInsert(['company_uuid'=>$c['company']],[...$v,'updated_at'=>now(),'created_at'=>now()]);
  $this->audit($c,'settings_saved');
  return $this->reply(['ok'=>true]);
 }
 public function subscribe(Request $r){
  $c=$this->staff($r);$v=$r->validate(['call_id'=>'required|uuid','consent'=>'required|accepted','tracking_url'=>'required|url|max:2000']);
  $url=parse_url($v['tracking_url']);
  abort_unless(($url['scheme']??'')==='https'&&($url['host']??'')===config('mapa_text.tracking_host')
   &&($url['path']??'')==='/track/'&&!isset($url['user'])&&!isset($url['pass'])&&!isset($url['port'])&&!isset($url['query'])
   &&preg_match('/^[a-f0-9]{64}$/D',(string)($url['fragment']??'')),422,'Use an active MAPA tracking link for this call.');
  return DB::transaction(function()use($c,$v,$url){
   $call=DB::table('mapa_voice_calls')->where(['company_uuid'=>$c['company'],'id'=>$v['call_id']])->lockForUpdate()->first();abort_unless($call,404);abort_if((bool)$call->demo,422,'DEMO calls cannot send messages.');
   $draft=json_decode($call->review?:($call->draft?:'{}'),true)?:[];
   try{$recipient=CallerPhone::normalize($draft['callback_number']??$call->caller);}catch(\Throwable $e){abort(422,'A valid caller number is required.');}abort_if(!$recipient,422);
   $link=DB::table('mapa_tracking_links')->where(['company_uuid'=>$c['company'],'call_id'=>$call->id,'token_hash'=>hash('sha256',$url['fragment'])])
    ->whereNull('revoked_at')->where('expires_at','>',(int)(microtime(true)*1000))->first();
   abort_unless($link&&($link->scope??'pickup')==='full_journey'
    &&is_string($link->callback_hash)&&hash_equals($link->callback_hash,hash('sha256',$recipient)),422,'Tracking link expired or does not belong to this caller.');
   $existing=DB::table('mapa_text_conversations')->where(['company_uuid'=>$c['company'],'call_id'=>$call->id])->first();
   if($existing)return $this->reply(['ok'=>true,'conversation_id'=>$existing->id,'replayed'=>true]);
   $id=(string)Str::uuid();DB::table('mapa_text_conversations')->insert(['id'=>$id,'company_uuid'=>$c['company'],'call_id'=>$call->id,'recipient'=>$recipient,'state'=>'open','tracking_url'=>PrivateLink::encode($v['tracking_url']),'consented_by'=>$c['user']->uuid,'consented_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
   $this->audit($c,'caller_subscribed',$id);
   return $this->reply(['ok'=>true,'conversation_id'=>$id]);
  });
 }
 public function conversation(Request $r,string $id){$c=$this->staff($r);$v=$r->validate(['state'=>'required|in:open,closed']);$n=DB::table('mapa_text_conversations')->where(['company_uuid'=>$c['company'],'id'=>$id])->update([...$v,'updated_at'=>now()]);abort_unless($n,404);$this->audit($c,'conversation_'.$v['state'],$id);return $this->reply(['ok'=>true]);}
 public function retry(Request $r,string $id){$c=$this->staff($r);return DB::transaction(function()use($c,$id){$n=DB::table('mapa_text_messages')->where(['company_uuid'=>$c['company'],'id'=>$id,'status'=>'failed','direction'=>'out'])->update(['status'=>'queued','error'=>null,'updated_at'=>now()]);abort_unless($n,409,'Only definitively failed messages can be retried.');DB::table('mapa_text_send_claims')->where('message_id',$id)->delete();$this->audit($c,'message_retry',$id);return $this->reply(['ok'=>true]);});}
 public function verify(Request $r){$token=(string)config('mapa_text.verify_token');abort_unless($token!==''&&$r->query('hub_mode')==='subscribe'&&hash_equals($token,(string)$r->query('hub_verify_token')),403);return response((string)$r->query('hub_challenge'))->header('Cache-Control','no-store');}
 public function meta(Request $r){
  abort_if(strlen($r->getContent())>262144,413);$secret=(string)config('mapa_text.meta_secret');
  abort_unless(SecurityPolicy::signature($r->getContent(),(string)$r->header('X-Hub-Signature-256'),$secret),403);
  foreach($r->json('entry',[]) as $entry)foreach($entry['changes']??[] as $change){
   $v=$change['value']??[];$phone=(string)($v['metadata']['phone_number_id']??'');
   foreach($v['statuses']??[] as $receipt)ReceiptStore::record($phone,$receipt);
   foreach($v['messages']??[] as $incoming){
    $from='+'.ltrim((string)($incoming['from']??''),'+');$pid=(string)($incoming['id']??'');if($pid===''||strlen($pid)>200)continue;
    // Match only a sender with an existing outbound conversation; no cross-tenant routing by recipient alone.
    $last=DB::table('mapa_text_messages')->where(['phone_id'=>$phone,'recipient'=>$from,'direction'=>'out'])->orderByDesc('created_at')->first();if(!$last)continue;
    $inserted=DB::table('mapa_text_messages')->insertOrIgnore(['id'=>(string)Str::uuid(),'company_uuid'=>$last->company_uuid,'conversation_id'=>$last->conversation_id,'event_key'=>'in:'.hash('sha256',$pid),'direction'=>'in','recipient'=>$from,'sender'=>$last->sender,'phone_id'=>$phone,'body'=>mb_substr((string)($incoming['text']['body']??'[Non-text WhatsApp reply]'),0,4000),'status'=>'received','provider_id'=>$pid,'created_at'=>now(),'updated_at'=>now()]);
    if($inserted===1)DB::table('mapa_text_conversations')->where('id',$last->conversation_id)->update(['state'=>'open','updated_at'=>now()]);
   }
  }
  ReceiptStore::drain();return $this->reply(['ok'=>true]);
 }
}

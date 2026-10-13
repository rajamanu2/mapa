<?php
namespace App\MapaText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
class TextTick extends Command {
 protected $signature='mapa:text-tick';protected $description='Queue confirmed caller updates and process the MAPA text outbox';
 public function handle(): int {
  if(!config('mapa_text.enabled')||!Schema::hasTable('mapa_text_messages'))return 0;
  ReceiptStore::drain();
  DB::table('mapa_text_messages')->where('status','sending')->where('updated_at','<',now()->subMinutes(5))->update(['status'=>'unknown','error'=>'Interrupted send; reconcile provider history before retry.','updated_at'=>now()]);
  foreach(DB::table('mapa_text_conversations')->where('state','open')->get() as $conv){
   $s=DB::table('mapa_text_settings')->where(['company_uuid'=>$conv->company_uuid,'enabled'=>true])->first();if(!$s)continue;
   $call=DB::table('mapa_voice_calls')->where(['company_uuid'=>$conv->company_uuid,'id'=>$conv->call_id,'demo'=>false])->first();if(!$call)continue;
   $events=['ack'];
   if($call->state==='dismissed')$events=['cancelled'];
   if($call->order_uuid&&$call->state!=='dismissed'){
    $o=DB::table('orders')->where(['company_uuid'=>$conv->company_uuid,'uuid'=>$call->order_uuid])->whereNull('deleted_at')->first();
    if($o){
     $d=DB::table('drivers')->where(['company_uuid'=>$conv->company_uuid,'uuid'=>$o->driver_assigned_uuid])->whereNull('deleted_at')->first();
     $v=DB::table('vehicles')->where(['company_uuid'=>$conv->company_uuid,'uuid'=>$o->vehicle_assigned_uuid])->whereNull('deleted_at')->first();
     $state=DB::table('mapa_mobile_order_states')->where(['company_uuid'=>$conv->company_uuid,'order_uuid'=>$o->uuid,'driver_uuid'=>$o->driver_assigned_uuid])->first();
     $native=$state?\Fleetbase\FleetOps\Models\Order::withoutGlobalScopes()->where('company_uuid',$conv->company_uuid)->where('uuid',$o->uuid)->first():null;
     $ack=$native&&hash_equals($state->assignment_hash,hash('sha256',json_encode(\App\MapaVoice\Policy::canonical($native->getAttributes()),JSON_THROW_ON_ERROR)));
     $trip=\App\MapaVoice\CommandDeskPolicy::tripProgress($o,$d,$v,$ack?(int)($state->acknowledged_at??0):null,(int)round(microtime(true)*1000));
     $labels=['accepted','pickup_arrived','patient_onboard','hospital_arrived','completed'];
     // Send the latest confirmed stage; avoid a burst of stale milestones after reopening.
     foreach($labels as $key)if(($trip[$key]??false)===true){$events=[$key];}
    }
   }
   foreach($events as $key)DB::table('mapa_text_messages')->insertOrIgnore(['id'=>(string)Str::uuid(),'company_uuid'=>$conv->company_uuid,'conversation_id'=>$conv->id,'event_key'=>$key,'direction'=>'out','recipient'=>$conv->recipient,'sender'=>$s->sender,'phone_id'=>$s->phone_id,'template'=>$s->template,'language'=>$s->language,'body'=>TrackerMessage::stage($key),'tracking_url'=>$conv->tracking_url,'status'=>'queued','created_at'=>now(),'updated_at'=>now()]);
  }
  foreach(DB::table('mapa_text_messages')->where('status','queued')->orderBy('created_at')->limit(25)->get() as $m){
   $conv=DB::table('mapa_text_conversations')->where(['id'=>$m->conversation_id,'state'=>'open'])->first();$s=DB::table('mapa_text_settings')->where(['company_uuid'=>$m->company_uuid,'enabled'=>true])->first();
   if(!$conv||!$s||!config('mapa_text.n8n_url')||!config('mapa_text.flow_key'))continue;
   if(!DB::table('mapa_text_messages')->where(['id'=>$m->id,'status'=>'queued'])->update(['status'=>'sending','updated_at'=>now()]))continue;
   try {
    $response=Http::timeout(35)->connectTimeout(5)->withoutRedirecting()->withHeaders(['X-MAPA-Flow-Key'=>config('mapa_text.flow_key')])->post(config('mapa_text.n8n_url'),[
     'message_id'=>$m->id]);
    [$status,$pid]=DeliveryPolicy::outcome($response->status(),$response->json()??[]);
   }catch(\Throwable $e){$status='unknown';$pid=null;}
   DB::table('mapa_text_messages')->where(['id'=>$m->id,'status'=>'sending'])->update(['status'=>$status,'provider_id'=>$pid,'error'=>$status==='unknown'?'Delivery outcome unknown; inspect provider history.':($status==='failed'?'Provider rejected the request.':null),'updated_at'=>now()]);
  }
  ReceiptStore::drain();$this->info('MAPA text outbox processed.');return 0;
 }
}

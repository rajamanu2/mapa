<?php
namespace App\MapaText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class BridgeController {
 public function events(Request $r){
  abort_unless($r->isJson(),415);abort_if(strlen($r->getContent())>16384,413);
  $key=(string)config('mapa_text.bridge_receipt_key');abort_unless(strlen($key)>=32&&hash_equals($key,(string)$r->bearerToken()),401);
  abort_unless(config('mapa_text.transport')==='waha',403);
  $v=$r->validate(['sender_id'=>'required|string|max:80','id'=>'required|string|max:200','kind'=>'required|in:status,reply','status'=>'nullable|in:sent,delivered,read,failed','from'=>'nullable|string|regex:/^[1-9][0-9]{7,14}$/','text'=>'nullable|string|max:4000']);
  abort_unless(hash_equals((string)config('mapa_text.phone_id'),$v['sender_id']),403);
  if($v['kind']==='status'){
   abort_unless(isset($v['status']),422);ReceiptStore::record($v['sender_id'],['id'=>$v['id'],'status'=>$v['status']]);ReceiptStore::drain();
  }else{
   abort_unless(isset($v['from']),422);$from='+'.$v['from'];
   $last=DB::table('mapa_text_messages')->where(['company_uuid'=>config('mapa.company_uuid'),'phone_id'=>$v['sender_id'],'recipient'=>$from,'direction'=>'out'])->orderByDesc('created_at')->first();
   if($last){$inserted=DB::table('mapa_text_messages')->insertOrIgnore(['id'=>(string)Str::uuid(),'company_uuid'=>$last->company_uuid,'conversation_id'=>$last->conversation_id,'event_key'=>'in:'.hash('sha256',$v['id']),'direction'=>'in','recipient'=>$from,'sender'=>$last->sender,'phone_id'=>$v['sender_id'],'body'=>$v['text']??'[Non-text reply]','status'=>'received','provider_id'=>$v['id'],'created_at'=>now(),'updated_at'=>now()]);
    if($inserted===1)DB::table('mapa_text_conversations')->where(['id'=>$last->conversation_id,'company_uuid'=>$last->company_uuid])->update(['state'=>'open','updated_at'=>now()]);}
  }
  return response()->json(['ok'=>true])->header('Cache-Control','no-store');
 }
}

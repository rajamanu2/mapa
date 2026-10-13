<?php
namespace App\MapaText;
use Illuminate\Support\Facades\DB;
class ReceiptStore {
 public static function record(string $phone,array $r): void {
  $pid=(string)($r['id']??'');$status=(string)($r['status']??'');
  if(!preg_match('/^[0-9]{5,80}$/',$phone)||$pid===''||strlen($pid)>200||!in_array($status,['sent','delivered','read','failed'],true))return;
  DB::table('mapa_text_receipts')->insertOrIgnore(['id'=>hash('sha256',$phone.'|'.$pid.'|'.$status),'phone_id'=>$phone,'provider_id'=>$pid,'status'=>$status,'error'=>$status==='failed'?'Provider rejected or failed the message'.(isset($r['errors'][0]['code'])?' (code '.substr((string)$r['errors'][0]['code'],0,30).')':''):null,'created_at'=>now(),'updated_at'=>now()]);
 }
 public static function drain(): void {
  foreach(DB::table('mapa_text_receipts')->orderBy('created_at')->limit(500)->get() as $r)DB::transaction(function()use($r){
   $m=DB::table('mapa_text_messages')->where(['phone_id'=>$r->phone_id,'provider_id'=>$r->provider_id])->lockForUpdate()->first();if(!$m)return;
   $status=DeliveryPolicy::next($m->status,$r->status);
   DB::table('mapa_text_messages')->where('id',$m->id)->update(['status'=>$status,'error'=>$status==='failed'?$r->error:null,'updated_at'=>now()]);
   DB::table('mapa_text_receipts')->where('id',$r->id)->delete();
  });
  DB::table('mapa_text_receipts')->where('created_at','<',now()->subDays(7))->delete();
 }
}

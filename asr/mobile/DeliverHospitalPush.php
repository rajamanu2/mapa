<?php
namespace App\MapaMobile;
use Fleetbase\FleetOps\Models\{Order,Payload,Place,Vehicle};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue,SerializesModels};
use Illuminate\Support\Facades\{DB,Crypt};
class DeliverHospitalPush implements ShouldQueue {
 use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
 public $tries=3;public $timeout=60;
 public function backoff(): array{return [15,45,90];}
 public function __construct(public string $handoverId,public string $companyUuid,public string $placeUuid,public string $eventId,public int $issuedAt,public ?int $handoverRevision=null){}
 protected function isTraining($model): bool{return (bool)data_get($model->meta,'mapa_demo',false)||(bool)data_get($model->meta,'simulated',false);}
 private function current(): bool {
  $row=DB::table('mapa_mobile_hospital_handovers')->where(['id'=>$this->handoverId,'company_uuid'=>$this->companyUuid,'place_uuid'=>$this->placeUuid])->first();
  if(!$row||$row->status!=='incoming'||($this->handoverRevision!==null&&(int)$row->revision!==$this->handoverRevision))return false;
  $order=Order::withoutGlobalScopes()->where(['uuid'=>$row->order_uuid,'company_uuid'=>$this->companyUuid])->whereNull('deleted_at')->first();
  $payload=$order?Payload::withoutGlobalScopes()->where(['uuid'=>$order->payload_uuid,'company_uuid'=>$this->companyUuid])->whereNull('deleted_at')->first():null;
  $place=$payload?Place::withoutGlobalScopes()->where(['uuid'=>$payload->dropoff_uuid,'company_uuid'=>$this->companyUuid])->whereNull('deleted_at')->first():null;
  $vehicle=Vehicle::withoutGlobalScopes()->where(['uuid'=>$row->vehicle_uuid,'company_uuid'=>$this->companyUuid])->whereNull('deleted_at')->first();
  return $order && $place && $vehicle && !$this->isTraining($order) && !$this->isTraining($place) && !$this->isTraining($vehicle)
   && HospitalHandoverPolicy::receivingPlaceMatches($this->companyUuid,$this->placeUuid,(array)$row,$order->getAttributes(),$place->getAttributes());
 }
 public function handle(FcmTransport $fcm,PushService $push): void {
  if(time()-$this->issuedAt>120 || $this->issuedAt>time()+30 || $this->companyUuid!==config('mapa.company_uuid') || !$push->hospitalAvailable() || !$fcm->configured() || !$this->current())return;
  // Cursor traversal reaches all registered hospital staff; every target is reauthorized separately.
  $devices=DB::table('mapa_mobile_hospital_push_devices')->where(['company_uuid'=>$this->companyUuid,'place_uuid'=>$this->placeUuid])->whereNull('revoked_at')->where('expires_at','>',(int)round(microtime(true)*1000))->orderBy('token_hash')->cursor();
  foreach($devices as $device){
   if(time()-$this->issuedAt>120)return;
   try{$c=(new MobileService)->contextFromSessionHash($device->session_hash);}
   catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){DB::table('mapa_mobile_hospital_push_devices')->where('token_hash',$device->token_hash)->update(['revoked_at'=>(int)round(microtime(true)*1000)]);continue;}
   if($c['company']!==$this->companyUuid || $c['binding']->id!==$device->binding_id || $c['binding']->role!=='hospital' || !$c['place'] || $c['place']->uuid!==$this->placeUuid || !$this->current())continue;
   $id=hash('sha256',$this->eventId.$device->token_hash);
   DB::table('mapa_mobile_hospital_push_deliveries')->insertOrIgnore(['id'=>$id,'event_id'=>$this->eventId,'token_hash'=>$device->token_hash,'status'=>'pending','created_at'=>now(),'updated_at'=>now()]);
   $claimed=DB::transaction(function()use($id){$row=DB::table('mapa_mobile_hospital_push_deliveries')->where('id',$id)->lockForUpdate()->first();
    if(in_array($row->status,['accepted','unregistered'],true)||($row->claimed_at&&$row->claimed_at>time()-40))return false;
    DB::table('mapa_mobile_hospital_push_deliveries')->where('id',$id)->update(['status'=>'sending','claimed_at'=>time(),'updated_at'=>now()]);return true;});
   if(!$claimed)continue;
   try{
    $fresh=DB::table('mapa_mobile_hospital_push_devices')->where('token_hash',$device->token_hash)->whereNull('revoked_at')->first();
    if(!$fresh||$fresh->session_hash!==$device->session_hash)continue;
    $result=$fcm->sendHospital(Crypt::decryptString($device->token_encrypted),$this->eventId,$device->session_hash,$this->issuedAt);
    DB::table('mapa_mobile_hospital_push_deliveries')->where('id',$id)->update(['status'=>$result,'claimed_at'=>null,'accepted_at'=>$result==='accepted'?time():null,'updated_at'=>now()]);
    if($result==='unregistered')DB::table('mapa_mobile_hospital_push_devices')->where('token_hash',$device->token_hash)->update(['revoked_at'=>(int)round(microtime(true)*1000)]);
   }catch(\Throwable $e){DB::table('mapa_mobile_hospital_push_deliveries')->where('id',$id)->update(['status'=>'retry','claimed_at'=>null,'updated_at'=>now()]);throw new \RuntimeException('MAPA hospital alert could not be delivered.');}
  }
 }
}

<?php
namespace App\MapaMobile;

use Fleetbase\FleetOps\Models\{Order,Payload,Place};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\{Crypt,DB};

/** Early hospital heads-up after a real MAPA crew accepts; no patient data leaves in push. */
class DeliverHospitalAssignmentPush implements ShouldQueue {
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public $tries=3;
    public $timeout=60;
    public function backoff(): array{return [15,45,90];}
    public function __construct(public string $orderUuid,public string $companyUuid,public string $placeUuid,
        public string $driverUuid,public string $vehicleUuid,public string $eventId,public int $issuedAt){}
    private function current(): bool {
        $order=Order::withoutGlobalScopes()->where(['uuid'=>$this->orderUuid,'company_uuid'=>$this->companyUuid])->whereNull('deleted_at')->first();
        if(!$order||$order->driver_assigned_uuid!==$this->driverUuid||$order->vehicle_assigned_uuid!==$this->vehicleUuid
            ||!is_string(data_get($order->meta,'mapa_voice_call_id'))||data_get($order->meta,'mapa_demo',false)
            ||data_get($order->meta,'simulated',false)||data_get($order->meta,'mapa_operational_test',false))return false;
        $payload=Payload::withoutGlobalScopes()->where(['uuid'=>$order->payload_uuid,'company_uuid'=>$this->companyUuid])->whereNull('deleted_at')->first();
        $place=$payload?Place::withoutGlobalScopes()->where(['uuid'=>$payload->dropoff_uuid,'company_uuid'=>$this->companyUuid])->whereNull('deleted_at')->first():null;
        return HospitalHandoverPolicy::assignedPlaceMatches($this->companyUuid,$this->placeUuid,$order->getAttributes(),$place?->getAttributes());
    }
    public function handle(FcmTransport $fcm,PushService $push): void {
        if(time()-$this->issuedAt>120||$this->issuedAt>time()+30||$this->companyUuid!==config('mapa.company_uuid')
            ||!HospitalHandoverService::available()||!$push->hospitalAvailable()||!$fcm->configured()||!$this->current())return;
        $devices=DB::table('mapa_mobile_hospital_push_devices')->where(['company_uuid'=>$this->companyUuid,'place_uuid'=>$this->placeUuid])
            ->whereNull('revoked_at')->where('expires_at','>',(int)round(microtime(true)*1000))->orderBy('token_hash')->cursor();
        foreach($devices as $device){
            if(time()-$this->issuedAt>120||!$this->current())return;
            try{$c=(new MobileService)->contextFromSessionHash($device->session_hash);}
            catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){DB::table('mapa_mobile_hospital_push_devices')->where('token_hash',$device->token_hash)->update(['revoked_at'=>(int)round(microtime(true)*1000)]);continue;}
            if($c['company']!==$this->companyUuid||$c['binding']->id!==$device->binding_id||$c['binding']->role!=='hospital'
                ||!$c['place']||$c['place']->uuid!==$this->placeUuid)continue;
            $id=hash('sha256',$this->eventId.$device->token_hash);
            DB::table('mapa_mobile_hospital_push_deliveries')->insertOrIgnore(['id'=>$id,'event_id'=>$this->eventId,'token_hash'=>$device->token_hash,'status'=>'pending','created_at'=>now(),'updated_at'=>now()]);
            $claimed=DB::transaction(function()use($id){$row=DB::table('mapa_mobile_hospital_push_deliveries')->where('id',$id)->lockForUpdate()->first();
                if(in_array($row->status,['accepted','unregistered'],true)||($row->claimed_at&&$row->claimed_at>time()-40))return false;
                DB::table('mapa_mobile_hospital_push_deliveries')->where('id',$id)->update(['status'=>'sending','claimed_at'=>time(),'updated_at'=>now()]);return true;});
            if(!$claimed)continue;
            try{
                if(!$this->current())return;
                $result=$fcm->sendHospital(Crypt::decryptString($device->token_encrypted),$this->eventId,$device->session_hash,$this->issuedAt);
                DB::table('mapa_mobile_hospital_push_deliveries')->where('id',$id)->update(['status'=>$result,'claimed_at'=>null,'accepted_at'=>$result==='accepted'?time():null,'updated_at'=>now()]);
                if($result==='unregistered')DB::table('mapa_mobile_hospital_push_devices')->where('token_hash',$device->token_hash)->update(['revoked_at'=>(int)round(microtime(true)*1000)]);
            }catch(\Throwable $e){DB::table('mapa_mobile_hospital_push_deliveries')->where('id',$id)->update(['status'=>'retry','claimed_at'=>null,'updated_at'=>now()]);throw new \RuntimeException('MAPA hospital assignment alert could not be delivered.');}
        }
    }
}

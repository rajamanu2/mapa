<?php
namespace App\MapaVoice;

use App\MapaMobile\FcmTransport;
use App\MapaMobile\MobileService;
use App\MapaMobile\PushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** A private wake-up hint for a still-actionable offer; the phone fetches the offer itself. */
class DeliverOfferPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $tries=3;
    public $timeout=60;
    public function backoff(): array { return [5,15,30]; }
    public function __construct(public string $offerId, public string $companyUuid, public string $eventId, public int $issuedAt) {}

    private function current(): ?object {
        $offer=DB::table('mapa_dispatch_offers')->where(['id'=>$this->offerId,'company_uuid'=>$this->companyUuid,'state'=>'offered'])
            ->where('expires_at','>',now())->first();
        if(!$offer)return null;
        // A queued job may outlive a pilot config change. Recheck exact IDs at
        // delivery time, before looking up any phone token or contacting FCM.
        if(!AutomaticDispatchScope::allowsCallId($offer->call_id)
            ||!AutomaticDispatchScope::allowsDriverUuid($offer->driver_uuid,$offer->call_id)
            ||(AutomaticDispatchScope::modeForCallId($offer->call_id)!=='all_live'
                &&DB::table('mapa_dispatch_offers')->where('call_id',$offer->call_id)
                    ->whereNotIn('driver_uuid',AutomaticDispatchScope::driverUuids($offer->call_id))->exists()))return null;
        $run=DB::table('mapa_dispatch_runs')->where(['call_id'=>$offer->call_id,'company_uuid'=>$this->companyUuid,'state'=>'offering'])->first();
        $call=DB::table('mapa_voice_calls')->where(['id'=>$offer->call_id,'company_uuid'=>$this->companyUuid,'state'=>'ready'])->first();
        if(!$run||!$call||$call->provider==='asrhealth'||(bool)$call->demo||(int)$run->call_revision!==(int)$call->revision)return null;
        return $offer;
    }
    public function handle(FcmTransport $fcm,PushService $push): void {
        if(time()-$this->issuedAt>120||$this->issuedAt>time()+30||$this->companyUuid!==config('mapa.company_uuid')
            ||!$push->available()||!$fcm->configured()||!AutomaticDispatch::available())return;
        $offer=$this->current();if(!$offer)return;
        $devices=DB::table('mapa_mobile_push_devices')->where(['company_uuid'=>$this->companyUuid,'driver_uuid'=>$offer->driver_uuid,'vehicle_uuid'=>$offer->vehicle_uuid])
            ->whereNull('revoked_at')->where('expires_at','>',(int)round(microtime(true)*1000))->limit(5)->get();
        foreach($devices as $device){
            if(!$this->current())return;
            try{$c=(new MobileService)->contextFromSessionHash($device->session_hash);}
            catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){DB::table('mapa_mobile_push_devices')->where('token_hash',$device->token_hash)->update(['revoked_at'=>(int)round(microtime(true)*1000)]);continue;}
            if($c['company']!==$this->companyUuid||$c['binding']->role!=='crew'||!$c['driver']||!$c['vehicle']
                ||$c['driver']->uuid!==$offer->driver_uuid||$c['vehicle']->uuid!==$offer->vehicle_uuid||!$c['driver']->online)continue;
            $id=hash('sha256',$this->eventId.$device->token_hash);
            DB::table('mapa_mobile_push_deliveries')->insertOrIgnore(['id'=>$id,'event_id'=>$this->eventId,'token_hash'=>$device->token_hash,'status'=>'pending','created_at'=>now(),'updated_at'=>now()]);
            $claimed=DB::transaction(function()use($id){$row=DB::table('mapa_mobile_push_deliveries')->where('id',$id)->lockForUpdate()->first();
                if(in_array($row->status,['accepted','unregistered'],true)||($row->claimed_at&&$row->claimed_at>time()-40))return false;
                DB::table('mapa_mobile_push_deliveries')->where('id',$id)->update(['status'=>'sending','claimed_at'=>time(),'updated_at'=>now()]);return true;});
            if(!$claimed)continue;
            try{
                if(!$this->current())return;
                $result=$fcm->send(Crypt::decryptString($device->token_encrypted),$this->eventId,$device->session_hash,$this->issuedAt);
                DB::table('mapa_mobile_push_deliveries')->where('id',$id)->update(['status'=>$result,'claimed_at'=>null,'accepted_at'=>$result==='accepted'?time():null,'updated_at'=>now()]);
                if($result==='unregistered')DB::table('mapa_mobile_push_devices')->where('token_hash',$device->token_hash)->update(['revoked_at'=>(int)round(microtime(true)*1000)]);
            }catch(\Throwable $e){DB::table('mapa_mobile_push_deliveries')->where('id',$id)->update(['status'=>'retry','claimed_at'=>null,'updated_at'=>now()]);throw new \RuntimeException('MAPA offer alert could not be delivered.');}
        }
    }
}

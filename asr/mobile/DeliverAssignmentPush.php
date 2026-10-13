<?php
namespace App\MapaMobile;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class DeliverAssignmentPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $tries = 3;
    public $timeout = 60;
    public function backoff(): array { return [15,45,90]; }
    public function __construct(public string $orderUuid, public string $companyUuid, public string $driverUuid,
        public ?string $vehicleUuid, public string $eventId, public int $issuedAt) {}

    protected function isTraining($model): bool { return (bool)data_get($model->meta,'mapa_demo',false) || (bool)data_get($model->meta,'simulated',false); }
    public function handle(FcmTransport $fcm, PushService $push): void
    {
        if (time() - $this->issuedAt > 120 || $this->companyUuid !== config('mapa.company_uuid')
            || !$push->available() || !$fcm->configured()) return;
        $order = Order::withoutGlobalScopes()->where('company_uuid',$this->companyUuid)->where('uuid',$this->orderUuid)->whereNull('deleted_at')->first();
        if (!$order || $this->isTraining($order) || $order->driver_assigned_uuid !== $this->driverUuid
            || $order->vehicle_assigned_uuid !== $this->vehicleUuid) return;
        $devices = DB::table('mapa_mobile_push_devices')->where(['company_uuid'=>$this->companyUuid,'driver_uuid'=>$this->driverUuid])
            ->whereNull('revoked_at')->where('expires_at','>',(int)round(microtime(true)*1000))->limit(5)->get();
        foreach ($devices as $device) {
            try { $c = (new MobileService)->contextFromSessionHash($device->session_hash); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
                DB::table('mapa_mobile_push_devices')->where('token_hash',$device->token_hash)->update(['revoked_at'=>(int)round(microtime(true)*1000)]); continue;
            }
            if ($c['binding']->role !== 'crew' || !$c['vehicle'] || $c['driver']->uuid !== $this->driverUuid
                || $c['vehicle']->uuid !== $device->vehicle_uuid || !$c['driver']->online
                || $this->isTraining($c['vehicle']) || $this->isTraining($c['driver'])
                || !Policy::canAcknowledge($this->companyUuid,$this->driverUuid,$c['vehicle']->uuid,$order->getAttributes())) continue;
            $id = hash('sha256',$this->eventId.$device->token_hash);
            DB::table('mapa_mobile_push_deliveries')->insertOrIgnore(['id'=>$id,'event_id'=>$this->eventId,'token_hash'=>$device->token_hash,
                'status'=>'pending','created_at'=>now(),'updated_at'=>now()]);
            $claimed = DB::transaction(function () use ($id) {
                $row = DB::table('mapa_mobile_push_deliveries')->where('id',$id)->lockForUpdate()->first();
                if (in_array($row->status,['accepted','unregistered'],true) || ($row->claimed_at && $row->claimed_at > time()-40)) return false;
                DB::table('mapa_mobile_push_deliveries')->where('id',$id)->update(['status'=>'sending','claimed_at'=>time(),'updated_at'=>now()]); return true;
            });
            if (!$claimed) continue;
            try {
                $result = $fcm->send(Crypt::decryptString($device->token_encrypted),$this->eventId,$device->session_hash,$this->issuedAt);
                DB::table('mapa_mobile_push_deliveries')->where('id',$id)->update(['status'=>$result,'claimed_at'=>null,
                    'accepted_at'=>$result === 'accepted' ? time() : null,'updated_at'=>now()]);
                if ($result === 'unregistered') DB::table('mapa_mobile_push_devices')->where('token_hash',$device->token_hash)->update(['revoked_at'=>(int)round(microtime(true)*1000)]);
            } catch (\Throwable $e) {
                DB::table('mapa_mobile_push_deliveries')->where('id',$id)->update(['status'=>'retry','claimed_at'=>null,'updated_at'=>now()]);
                throw new \RuntimeException('MAPA assignment alert could not be delivered.');
            }
        }
    }
}

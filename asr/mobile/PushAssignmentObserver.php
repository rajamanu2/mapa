<?php
namespace App\MapaMobile;

use Fleetbase\FleetOps\Models\Order;

class PushAssignmentObserver
{
    public function saved(Order $order): void
    {
        if (!$order->wasRecentlyCreated && !$order->wasChanged(['driver_assigned_uuid','vehicle_assigned_uuid','dispatched'])) return;
        $this->assigned($order);
    }
    public function assigned(Order $order): void
    {
        try { $this->schedule($order); }
        catch (\Throwable $e) { $this->warn(); }
        try { $this->scheduleHospital($order); }
        catch (\Throwable $e) { $this->warnHospital(); }
    }
    private function warn(): void
    {
        try { \Illuminate\Support\Facades\Log::warning('MAPA push assignment alert could not be queued; control must verify crew acknowledgement.'); }
        catch (\Throwable $ignored) { /* Optional alert/logging must never fail native dispatch. */ }
    }
    private function schedule(Order $order): void
    {
        if (!config('mapa_mobile.push.enabled',false) || $order->company_uuid !== config('mapa.company_uuid')
            || data_get($order->meta,'mapa_demo',false) || data_get($order->meta,'simulated',false)
            || !$order->driver_assigned_uuid || !$order->dispatched
            || !(new PushService)->available()) return;
        if (in_array(strtolower((string)$order->status),['created','scheduled','completed','canceled','cancelled','failed'],true)) return;
        // Only IDs and a change fingerprint are serialized into the queue, after the native transaction commits.
        $eventId = hash('sha256',json_encode([$order->uuid,$order->driver_assigned_uuid,$order->vehicle_assigned_uuid,
            (string)$order->updated_at],JSON_THROW_ON_ERROR));
        $job = new DeliverAssignmentPush($order->uuid,$order->company_uuid,$order->driver_assigned_uuid,$order->vehicle_assigned_uuid,$eventId,time());
        \Illuminate\Support\Facades\DB::afterCommit(function () use ($job) {
            try { \Illuminate\Support\Facades\Bus::dispatch($job); }
            catch (\Throwable $e) { $this->warn(); }
        });
    }
    private function scheduleHospital(Order $order): void
    {
        if (!(new PushService)->hospitalAvailable() || !HospitalHandoverService::available()
            || $order->company_uuid !== config('mapa.company_uuid') || !$order->dispatched
            || data_get($order->meta,'mapa_demo',false) || data_get($order->meta,'simulated',false)
            || data_get($order->meta,'mapa_operational_test',false)
            || !is_string(data_get($order->meta,'mapa_voice_call_id'))
            || !$order->driver_assigned_uuid || !$order->vehicle_assigned_uuid
            || in_array(strtolower((string)$order->status),['created','scheduled','completed','canceled','cancelled','failed'],true)) return;
        $payload=$order->payload;
        $place=$payload?->dropoff;
        if(!$place || !HospitalHandoverPolicy::assignedPlaceMatches($order->company_uuid,$place->uuid,$order->getAttributes(),$place->getAttributes()))return;
        $eventId=hash('sha256',json_encode(['hospital-assigned',$order->uuid,$place->uuid,$order->driver_assigned_uuid,
            $order->vehicle_assigned_uuid,(string)$order->updated_at],JSON_THROW_ON_ERROR));
        $job=new DeliverHospitalAssignmentPush($order->uuid,$order->company_uuid,$place->uuid,
            $order->driver_assigned_uuid,$order->vehicle_assigned_uuid,$eventId,time());
        \Illuminate\Support\Facades\DB::afterCommit(function()use($job){try{\Illuminate\Support\Facades\Bus::dispatch($job);}
            catch(\Throwable $e){$this->warnHospital();}});
    }
    private function warnHospital(): void
    {
        try { \Illuminate\Support\Facades\Log::warning('MAPA hospital assignment hint could not be queued; hospital polling remains active.'); }
        catch (\Throwable $ignored) {}
    }
}

<?php
namespace App\MapaMobile;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Atomic adapter for the reviewed ASR single-destination transport workflow.
 * No session-dependent native helpers, provider messages or pre-commit events.
 * Custom workflows and proof requirements stay with the dispatcher.
 */
final class TripWorkflow
{
    private const EDGES = ['created'=>['dispatched'],'dispatched'=>['started'],'started'=>['enroute'],'enroute'=>['completed'],'completed'=>[]];
    public function reason(Order $order, array $c): ?string
    {
        if (!config('mapa_mobile.trips_enabled',false)) return 'Mobile trip updates are not enabled.';
        $config=$order->orderConfig; $payload=$order->payload;
        if (!$config || $config->company_uuid!==$c['company'] || $config->deleted_at || !$payload || $payload->company_uuid!==$c['company'] || $payload->deleted_at)
            return 'The call configuration needs dispatcher review.';
        $flow=json_decode($config->getRawOriginal('flow') ?: '{}',true);
        if (!is_array($flow) || count($flow)!==count(self::EDGES)) return 'This workflow requires console updates.';
        foreach(self::EDGES as $code=>$next) {
            $a=$flow[$code]??null;
            if (!is_array($a) || ($a['code']??null)!==$code || ($a['activities']??null)!==$next
                || ($a['complete']??null)!==($code==='completed') || !empty($a['require_pod'])) return 'This workflow requires console updates.';
            foreach(['events','logic','actions','entities','options'] as $key) if (!empty($a[$key])) return 'This workflow has additional rules; use the console.';
            if (!is_string($a['status']??null) || strlen($a['status'])>190 || !is_string($a['details']??null) || strlen($a['details'])>2000
                || str_contains($a['status'].$a['details'],'{')) return 'This workflow requires console updates.';
        }
        if ($order->pod_required || $order->manifest_uuid || $order->transaction_uuid || $order->purchase_rate_uuid || $payload->return_uuid || $payload->current_waypoint_uuid)
            return 'Proof, manifest or extended transport steps require the console.';
        foreach(['waypoints','entities'] as $table) if (DB::table($table)->where('payload_uuid',$payload->uuid)->whereNull('deleted_at')->exists())
            return 'Multi-stop or entity transport requires the console.';
        foreach(['pickup_uuid','dropoff_uuid'] as $key) if (!$payload->$key || !DB::table('places')->where(['uuid'=>$payload->$key,'company_uuid'=>$c['company']])->whereNull('deleted_at')->exists())
            return 'Confirm the pickup and receiving destination with control.';
        if (!$order->tracking_number_uuid || !DB::table('tracking_numbers')->where(['uuid'=>$order->tracking_number_uuid,'company_uuid'=>$c['company'],'owner_uuid'=>$order->uuid])
                ->whereIn('owner_type',array_unique([$order->getMorphClass(),Order::class,'\\'.Order::class]))->whereNull('deleted_at')->exists())
            return 'The call tracking record needs dispatcher review.';
        return null;
    }
    public function next(Order $order, array $c): array
    {
        if ($this->reason($order,$c)!==null || !isset(self::EDGES[$order->status])) return [];
        $flow=json_decode($order->orderConfig->getRawOriginal('flow'),true);
        return array_map(fn($code)=>['code'=>$code,'label'=>$flow[$code]['status'],'complete'=>$code==='completed'],self::EDGES[$order->status]);
    }
    public function apply(Order $order, array $c, array $body): array
    {
        if ($reason=$this->reason($order,$c)) MobileService::fail($reason,409);
        $code=$body['activity_code']??null;
        if (!is_string($code) || !in_array($code,self::EDGES[$order->status]??[],true) || !in_array($code,['started','enroute','completed'],true))
            MobileService::fail('This is not the next configured trip step. Refresh the call.',409);
        if (($body['confirmed']??null)!==true) MobileService::fail('Explicit crew confirmation is required.',422);
        if ($code==='completed' && ($body['handover_confirmed']??null)!==true) MobileService::fail('Confirm that the receiving team has accepted handover.',422);
        if (!$c['driver']->online || ($c['driver']->current_job_uuid && $c['driver']->current_job_uuid!==$order->uuid)
            || ($code!=='started' && $c['driver']->current_job_uuid!==$order->uuid))
            MobileService::fail('Duty or the current trip changed. Contact control.',409);
        // A persisted, current-session-scope device fix is needed for the native
        // non-null tracking location. Never substitute a destination or (0,0).
        $receipt=DB::table('mapa_mobile_receipts')->where(['company_uuid'=>$c['company'],'user_uuid'=>$c['user']->uuid])
            ->where('result->_session_hash',$c['token_hash'])
            ->where('result->gps_evidence->driver_id',$c['driver']->public_id)->where('result->gps_evidence->vehicle_id',$c['vehicle']->public_id)
            ->orderByDesc('id')->first();
        $gps=$receipt ? data_get(json_decode($receipt->result,true),'gps_evidence') : null;
        $ack=DB::table('mapa_mobile_order_states')->where(['company_uuid'=>$c['company'],'order_uuid'=>$order->uuid])->value('acknowledged_at');
        if (!is_array($gps) || ($gps['source']??null)!=='device_gps' || ($gps['mock_location']??null)!==false || !Policy::freshFix($gps,(int)round(microtime(true)*1000),0)
            || !$ack || ($gps['recorded_at']??0)<$ack || ($gps['received_at']??0)<$ack)
            MobileService::fail('A fresh accurate phone GPS update is required. Resume sharing or contact control.',409);
        $completionEvidence=$code==='completed' ? TripCompletionEvidence::create($order,$c,$body,$gps) : [];
        $flow=json_decode($order->orderConfig->getRawOriginal('flow'),true); $activity=$flow[$code];
        $audit=['source'=>'mapa_crew','action_id'=>$body['action_id'],'user_id'=>$c['user']->public_id,
            'driver_id'=>$c['driver']->public_id,'vehicle_id'=>$c['vehicle']->public_id,'recorded_at'=>(int)round(microtime(true)*1000),
            'gps_recorded_at'=>$gps['recorded_at'],'gps_received_at'=>$gps['received_at'],'gps_accuracy_m'=>$gps['accuracy_m'],
            'crew_confirmed'=>true,'handover_confirmed'=>$code==='completed','proof_of_travel'=>false];
        if($completionEvidence) $audit['completion_evidence']=$completionEvidence;
        if(SourceDispatchSaga::enabled())(new SourceDispatchSaga)->enqueue($order,$c,$body,$code,$audit);
        $status=new TrackingStatus;
        $status->forceFill(['uuid'=>(string)Str::uuid(),'public_id'=>'status_'.bin2hex(random_bytes(12)),
            'company_uuid'=>$c['company'],'tracking_number_uuid'=>$order->tracking_number_uuid,'code'=>$code,
            'status'=>$activity['status'],'details'=>$activity['details'],'complete'=>$code==='completed',
            'location'=>new Point((float)$gps['latitude'],(float)$gps['longitude']),'meta'=>['mapa_mobile'=>$audit]]);
        $status->saveQuietly();
        DB::table('tracking_numbers')->where(['uuid'=>$order->tracking_number_uuid,'company_uuid'=>$c['company'],'owner_uuid'=>$order->uuid])->update(['status_uuid'=>$status->uuid,'updated_at'=>now()]);
        $meta=json_decode($order->getRawOriginal('meta') ?: '{}',true,512,JSON_THROW_ON_ERROR);
        if (!is_array($meta)) MobileService::fail('Order metadata requires console review.',409);
        $meta['mapa_mobile_trip'][$code]=$audit;
        $order->forceFill(['status'=>$code,'updated_by_uuid'=>$c['user']->uuid,'meta'=>$meta]);
        if ($code==='started') {
            $order->forceFill(['started'=>true,'started_at'=>$order->started_at ?? now()]);
            $c['driver']->updateQuietly(['current_job_uuid'=>$order->uuid]);
        }
        $order->saveQuietly();
        if ($code==='completed') DB::table('drivers')->where(['uuid'=>$c['driver']->uuid,'company_uuid'=>$c['company'],'current_job_uuid'=>$order->uuid])->update(['current_job_uuid'=>null,'updated_at'=>now()]);
        $order->refresh();
        $this->cacheInvalidation($order,$c);
        return ['status'=>$code,'tracking_id'=>$status->public_id,'completed'=>$code==='completed',...($completionEvidence ? ['completion_evidence'=>$completionEvidence] : [])];
    }

    /** Refresh only this tenant's native read caches after the transaction commits.
     * Deliberately no lifecycle event, webhook, notification or allocation dispatch.
     */
    private function cacheInvalidation(Order $order, array $c): void
    {
        $company=$c['company']; $orderUuid=$order->uuid;
        $tracking=\Fleetbase\FleetOps\Models\TrackingNumber::withoutGlobalScopes()
            ->where(['uuid'=>$order->tracking_number_uuid,'company_uuid'=>$company])->whereNull('deleted_at')->first();
        $models=[$order,$c['driver'],$c['vehicle'],$order->payload,$tracking];
        DB::afterCommit(function () use ($company,$orderUuid,$models) {
            $failed=false;
            foreach(['orders','routes','coordinates','drivers','vehicles','operations-monitor'] as $endpoint) {
                try {
                    // LiveCacheService uses a console session tenant. Mobile must
                    // form its exact documented keys from the authenticated scope.
                    \Illuminate\Support\Facades\Cache::increment("live:{$company}:{$endpoint}:version");
                    \Illuminate\Support\Facades\Cache::tags(["live:{$company}:{$endpoint}"])->flush();
                } catch (\Throwable $e) { $failed=true; }
            }
            try { \Illuminate\Support\Facades\Cache::forget("order:{$orderUuid}:tracker"); }
            catch (\Throwable $e) { $failed=true; }
            foreach($models as $model) {
                if (!$model || $model->company_uuid!==$company || !$model->getKey()) continue;
                try { $model->flushAttributesCache(); }
                catch (\Throwable $e) { $failed=true; }
            }
            // Persistence has already committed; a cache failure must not look
            // like an uncommitted trip update or repeat an operational mutation.
            if ($failed) \Illuminate\Support\Facades\Log::warning('MAPA mobile trip cache refresh failed.',[
                'company_uuid'=>$company,'order_uuid'=>$orderUuid,
            ]);
        });
    }
}

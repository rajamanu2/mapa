<?php
namespace App\MapaMobile;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\OrderConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Explicit crew-reported arrival evidence; never changes native/source trip stages. */
class TripArrivalService extends MobileService
{
    private const STAGES=['pickup_arrived'=>'started','hospital_arrived'=>'enroute'];
    public function nextArrivals(Order $order,array $c): array {
        if(!config('mapa_mobile.trips_enabled',false)||($c['binding']->role??null)!=='crew'||!$c['driver']||!$c['vehicle']
            ||!$c['driver']->online||$c['driver']->current_job_uuid!==$order->uuid||!$this->acknowledged($order)
            ||!Policy::canAcknowledge($c['company'],$c['driver']->uuid,$c['vehicle']->uuid,$order->getAttributes()))return [];
        $meta=self::meta($order->meta);$out=[];
        foreach(self::STAGES as $code=>$stage)if($order->status===$stage&&!isset($meta['mapa_mobile_arrivals'][$code]))
            $out[]=['code'=>$code,'label'=>$code==='pickup_arrived'?'Confirm pickup arrival':'Confirm hospital arrival'];
        return $out;
    }
    public function recordArrival(Request $request,string $id): array {
        if(!config('mapa_mobile.trips_enabled',false))self::fail('Crew arrival reporting is not enabled.',409);
        $body=$request->json()->all();
        if($request->query()||array_diff(array_keys($body),['action_id','expected_revision','arrival_code','confirmed','reported_at'])
            ||!is_string($body['action_id']??null)||!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/Di',$body['action_id'])||!is_string($body['expected_revision']??null)
            ||!preg_match('/^[a-f0-9]{64}$/D',$body['expected_revision'])||!is_string($body['arrival_code']??null)
            ||!isset(self::STAGES[$body['arrival_code']])||($body['confirmed']??null)!==true||!is_int($body['reported_at']??null)||$body['reported_at']<=0)self::fail('Confirm a valid arrival action and current revision.',422);
        return DB::transaction(function()use($request,$id,$body){
            $c=$this->context($request,true);
            if($c['binding']->role!=='crew'||!$c['driver']||!$c['vehicle'])self::fail('Arrival reporting requires assigned crew.',403);
            $order=$this->native(Order::class,$c['company'])->where('public_id',$id)->lockForUpdate()->first();
            if(!$order||!Policy::ownsOrder($c['company'],$c['driver']->uuid,$order->getAttributes()))self::fail('Assigned order not found.',404);
            if($order->vehicle_assigned_uuid!==$c['vehicle']->uuid||$c['driver']->vehicle_uuid!==$c['vehicle']->uuid)self::fail('Vehicle assignment changed.',409);
            $order->setRelation('payload',$this->native(Payload::class,$c['company'])->where('uuid',$order->payload_uuid)->lockForUpdate()->first());
            $order->setRelation('orderConfig',$this->native(OrderConfig::class,$c['company'])->where('uuid',$order->order_config_uuid)->lockForUpdate()->first());
            $state=DB::table('mapa_mobile_order_states')->where(['company_uuid'=>$c['company'],'order_uuid'=>$order->uuid])->lockForUpdate()->first();
            $key=['company_uuid'=>$c['company'],'user_uuid'=>$c['user']->uuid,'action_id'=>$body['action_id']];
            $hash=Policy::fingerprint($this->scopeHash($c).':arrival:'.$id.':'.($state->acknowledged_at??''),$body);
            $receipt=DB::table('mapa_mobile_receipts')->where($key)->first();
            if($receipt){if(!hash_equals($receipt->request_hash,$hash))self::fail('This action belongs to different details or an earlier assignment.',409);
                $saved=json_decode($receipt->result,true,32,JSON_THROW_ON_ERROR);unset($saved['_session_hash']);return [...$saved,'replayed'=>true];}
            if(!hash_equals($this->revision($order),$body['expected_revision'])||!$this->acknowledged($order))self::fail('Call or acknowledgement changed. Refresh and review.',409);
            if(!Policy::canAcknowledge($c['company'],$c['driver']->uuid,$c['vehicle']->uuid,$order->getAttributes())
                ||$order->status!==self::STAGES[$body['arrival_code']]||!$c['driver']->online||$c['driver']->current_job_uuid!==$order->uuid)
                self::fail('Arrival is not available at this trip stage or duty assignment.',409);
            foreach([$order,$c['driver'],$c['vehicle']] as $resource){$flags=self::meta($resource->meta??'{}');if(($flags['mapa_demo']??false)===true||($flags['simulated']??false)===true)self::fail('Simulated resources cannot record a live arrival.',422);}
            $meta=json_decode($order->getRawOriginal('meta')?:'{}',true,32,JSON_THROW_ON_ERROR);
            if(!is_array($meta)||(isset($meta['mapa_mobile_arrivals'])&&!is_array($meta['mapa_mobile_arrivals'])))self::fail('Arrival metadata needs dispatcher review.',409);
            if(isset($meta['mapa_mobile_arrivals'][$body['arrival_code']]))self::fail('An arrival was already recorded. Contact control if the assignment changed.',409);
            $gpsRow=DB::table('mapa_mobile_receipts')->where(['company_uuid'=>$c['company'],'user_uuid'=>$c['user']->uuid])
                ->where('result->_session_hash',$c['token_hash'])->where('result->gps_evidence->driver_id',$c['driver']->public_id)
                ->where('result->gps_evidence->vehicle_id',$c['vehicle']->public_id)->orderByDesc('id')->first();
            $gps=$gpsRow?self::meta($gpsRow->result)['gps_evidence']??null:null;$now=$this->arrivalNow();
            if($body['reported_at']<$now-120000||$body['reported_at']>$now+30000||!is_array($gps)
                ||!self::freshArrivalFix($gps,$now,(int)($state->acknowledged_at??0))||$gps['recorded_at']<$body['reported_at']-60000
                ||$gps['recorded_at']>$body['reported_at']+30000)self::fail('A recent confirmation and fresh real phone GPS receipt are required. Refresh and confirm arrival again.',422);
            $arrival=['code'=>$body['arrival_code'],'source'=>'crew_reported_arrival','confirmed'=>true,'independently_verified'=>false,
                'action_id'=>$body['action_id'],'company_uuid'=>$c['company'],'order_uuid'=>$order->uuid,'user_uuid'=>$c['user']->uuid,
                'driver_uuid'=>$c['driver']->uuid,'driver_id'=>$c['driver']->public_id,'vehicle_uuid'=>$c['vehicle']->uuid,'vehicle_id'=>$c['vehicle']->public_id,
                'acknowledged_at'=>(int)$state->acknowledged_at,'recorded_at'=>$now,'reported_at'=>$body['reported_at'],'native_stage'=>$order->status,
                'latitude'=>$gps['latitude'],'longitude'=>$gps['longitude'],'accuracy_m'=>$gps['accuracy_m'],
                'gps_recorded_at'=>$gps['recorded_at'],'gps_received_at'=>$gps['received_at']];
            $meta['mapa_mobile_arrivals'][$body['arrival_code']]=$arrival;$order->setAttribute('meta',$meta);$order->saveQuietly();$order->refresh();
            // Native metadata changes affect revision; preserve this acknowledgement's identity/time.
            DB::table('mapa_mobile_order_states')->where(['company_uuid'=>$c['company'],'order_uuid'=>$order->uuid])->update(['assignment_hash'=>$this->signature($order),'updated_at'=>now()]);
            $result=['ok'=>true,'replayed'=>false,'arrival'=>$arrival,'revision'=>$this->revision($order)];
            DB::table('mapa_mobile_receipts')->insert([...$key,'request_hash'=>$hash,'result'=>json_encode([...$result,'_session_hash'=>$c['token_hash']],JSON_THROW_ON_ERROR),'created_at'=>now()]);
            return $result;
        },3);
    }
    private static function meta($value): array {if(is_array($value))return $value;$decoded=json_decode((string)$value,true,32,JSON_THROW_ON_ERROR);return is_array($decoded)?$decoded:[];}
    protected function arrivalNow(): int {return (int)round(microtime(true)*1000);}
    public static function freshArrivalFix(array $gps,int $now,int $ack): bool {
        return ($gps['source']??null)==='device_gps'&&($gps['mock_location']??null)===false&&($gps['demo']??false)!==true&&($gps['simulated']??false)!==true
            &&Policy::freshFix($gps,$now,0)&&$gps['accuracy_m']<=50&&is_int($gps['recorded_at'])&&$gps['recorded_at']>=$ack
            &&$gps['recorded_at']<=$now&&$gps['recorded_at']>=$now-120000&&is_int($gps['received_at']??null)
            &&$gps['received_at']>=$gps['recorded_at']&&$gps['received_at']>=$ack&&$gps['received_at']<=$now&&$gps['received_at']>=$now-120000;
    }
}

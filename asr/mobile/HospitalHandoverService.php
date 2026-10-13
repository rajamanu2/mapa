<?php
namespace App\MapaMobile;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/** Explicit onboard report; never infers clinical urgency or dispatches messages. */
class HospitalHandoverService extends MobileService
{
    public static function available(): bool {
        return (bool)config('mapa_mobile.hospital_handover_enabled',false)
            && Schema::hasTable('mapa_mobile_hospital_handovers') && Schema::hasTable('mapa_mobile_hospital_responses');
    }
    private function enabled(): void { if(!self::available()) self::fail('Hospital handover is not enabled.',503); }
    private function destination(Order $order,string $company,bool $lock=false) {
        $q=$this->native(Payload::class,$company)->where('uuid',$order->payload_uuid);
        $payload=($lock?$q->lockForUpdate():$q)->first();
        if(!$payload || !$payload->dropoff_uuid) return null;
        $q=$this->native(Place::class,$company)->where('uuid',$payload->dropoff_uuid);
        return ($lock?$q->lockForUpdate():$q)->first();
    }
    public function onboard(Request $request,string $id): array {
        $this->enabled();
        $data=$request->validate(['revision'=>'required|string|size:64','condition'=>'required|in:'.implode(',',HospitalHandoverPolicy::CONDITIONS),
            'note'=>'required|string|max:2000','preparation_note'=>'nullable|string|max:1000']);
        return DB::transaction(function()use($request,$id,$data){
            $c=$this->context($request,true);
            if($c['binding']->role!=='crew' || !$c['vehicle']) self::fail('Assigned crew access required.');
            $order=$this->native(Order::class,$c['company'])->where('public_id',$id)->lockForUpdate()->first();
            if(!$order || !Policy::canAcknowledge($c['company'],$c['driver']->uuid,$c['vehicle']->uuid,$order->getAttributes())) self::fail('Active assigned trip not found.',404);
            if(data_get($order->meta,'mapa_operational_test',false)) self::fail('A no-patient pilot cannot create a patient handover.',409);
            if($order->vehicle_assigned_uuid!==$c['vehicle']->uuid) self::fail('Dispatcher must confirm the assigned ambulance.',409);
            if(!$this->acknowledged($order)) self::fail('Acknowledge this assignment first.',409);
            $place=$this->destination($order,$c['company'],true);
            if(!$place) self::fail('Dispatcher must set a valid receiving hospital.',409);
            if(!hash_equals($this->revision($order),$data['revision'])) self::fail('Trip changed. Refresh and review.',409);
            $report=['condition'=>$data['condition'],'note'=>trim($data['note']),'preparation_note'=>trim($data['preparation_note']??'')];
            if($report['note']==='') self::fail('Observed patient condition notes are required.',422);
            $hash=Policy::fingerprint('patient-onboard',$report);
            $key=['company_uuid'=>$c['company'],'order_uuid'=>$order->uuid];
            $existing=DB::table('mapa_mobile_hospital_handovers')->where($key)->first();
            if($existing){
                $changed=$existing->driver_uuid!==$c['driver']->uuid || $existing->vehicle_uuid!==$c['vehicle']->uuid || $existing->place_uuid!==$place->uuid;
                if($changed){
                    if($request->input('confirm_transfer')!==true)self::fail('Destination or crew changed. Review the patient report and explicitly confirm transfer to the current hospital.',409);
                    if(!Schema::hasTable('mapa_mobile_handover_revisions'))self::fail('Handover revision migration is required.',503);
                    DB::table('mapa_mobile_handover_revisions')->insert(['company_uuid'=>$c['company'],'handover_id'=>$existing->id,'revision'=>$existing->revision,
                        'changed_by'=>$c['user']->uuid,'previous_encrypted'=>Crypt::encryptString(json_encode($existing,JSON_THROW_ON_ERROR)),'created_at'=>now()]);
                    DB::table('mapa_mobile_hospital_handovers')->where('id',$existing->id)->update(['place_uuid'=>$place->uuid,'driver_uuid'=>$c['driver']->uuid,'vehicle_uuid'=>$c['vehicle']->uuid,
                        'created_by'=>$c['user']->uuid,'report_encrypted'=>Crypt::encryptString(json_encode($report,JSON_THROW_ON_ERROR)),
                        'report_hash'=>$hash,'status'=>'incoming','revision'=>$existing->revision+1,'updated_at'=>now()]);
                    $row=DB::table('mapa_mobile_hospital_handovers')->where('id',$existing->id)->first();
                    (new HospitalPushObserver)->created($row);
                    return ['ok'=>true,'transferred'=>true,'handover'=>$this->view($row,$order,$place,$c['company'])];
                }
                if(!hash_equals($existing->report_hash,$hash))self::fail('An onboard report already exists. Contact control to review changes.',409);
                return ['ok'=>true,'replayed'=>true,'handover'=>$this->view($existing,$order,$place,$c['company'])];
            }
            $uuid=(string)Str::uuid();
            DB::table('mapa_mobile_hospital_handovers')->insert([...$key,'id'=>$uuid,'place_uuid'=>$place->uuid,'driver_uuid'=>$c['driver']->uuid,
                'vehicle_uuid'=>$c['vehicle']->uuid,'created_by'=>$c['user']->uuid,'report_encrypted'=>Crypt::encryptString(json_encode($report,JSON_THROW_ON_ERROR)),
                'report_hash'=>$hash,'status'=>'incoming','revision'=>1,'created_at'=>now(),'updated_at'=>now()]);
            $row=DB::table('mapa_mobile_hospital_handovers')->where('id',$uuid)->first();
            (new HospitalPushObserver)->created($row);
            return ['ok'=>true,'handover'=>$this->view($row,$order,$place,$c['company'])];
        });
    }
    public function incoming(Request $request): array {
        $this->enabled(); $c=$this->context($request);
        if($c['binding']->role!=='hospital' || !$c['place']) self::fail('Hospital access required.');
        $items=[];
        foreach(DB::table('mapa_mobile_hospital_handovers')->where(['company_uuid'=>$c['company'],'place_uuid'=>$c['place']->uuid])->orderByDesc('created_at')->limit(100)->get() as $row){
            $order=$this->native(Order::class,$c['company'])->where('uuid',$row->order_uuid)->first();
            $place=$order?$this->destination($order,$c['company']):null;
            if($order && HospitalHandoverPolicy::receivingPlaceMatches($c['company'],$c['place']->uuid,(array)$row,$order->getAttributes(),$place?->getAttributes())) $items[]=$this->view($row,$order,$place,$c['company']);
        }
        $assigned=$this->assignedTrips($c);
        return ['incoming'=>$items,'assigned_trips'=>$assigned['items'],'assigned_trips_truncated'=>$assigned['truncated'],
            'capabilities'=>['hospital_assigned_trips'=>true],
            'delivery_mode'=>'authenticated_polling','push_available'=>(new PushService)->hospitalAvailable()];
    }
    /** Read-only pre-handover projection. No synthetic report IDs or response actions. */
    private function assignedTrips(array $c): array {
        $company=$c['company'];$hospital=$c['place']->uuid;$items=[];
        $payloads=$this->native(Payload::class,$company)->where('dropoff_uuid',$hospital)->select('uuid');
        $orders=$this->native(Order::class,$company)->whereIn('payload_uuid',$payloads)
            ->where('dispatched',true)->whereNotIn('status',['created','scheduled','completed','failed','cancelled','canceled'])
            ->orderByDesc('updated_at')->limit(101)->get();
        $truncated=$orders->count()>100;
        foreach($orders->take(100) as $order){
            if(data_get($order->meta,'mapa_operational_test',false))continue;
            $place=$this->destination($order,$company);
            if(!HospitalHandoverPolicy::assignedPlaceMatches($company,$hospital,$order->getAttributes(),$place?->getAttributes()))continue;
            $driver=$this->native(Driver::class,$company)->where('uuid',$order->driver_assigned_uuid)->first();
            $vehicle=$this->native(Vehicle::class,$company)->where('uuid',$order->vehicle_assigned_uuid)->first();
            if(!$driver||!$vehicle||$driver->vehicle_uuid!==$vehicle->uuid
                ||($vehicle->driver_uuid && $vehicle->driver_uuid!==$driver->uuid))continue;
            $report=DB::table('mapa_mobile_hospital_handovers')->where(['company_uuid'=>$company,'order_uuid'=>$order->uuid])->first();
            if($report && HospitalHandoverPolicy::receivingPlaceMatches($company,$hospital,(array)$report,$order->getAttributes(),$place?->getAttributes()))continue;
            $tracking=TripTrackingView::forOrder($order->uuid,$company,$order,$place);
            if(!$tracking)continue;
            // Recheck authorization facts after tracking reads. This is a polling snapshot, not a lock on dispatch.
            $fresh=$this->native(Order::class,$company)->where('uuid',$order->uuid)->first();
            $freshPlace=$fresh?$this->destination($fresh,$company):null;
            if(!$fresh||!HospitalHandoverPolicy::assignedPlaceMatches($company,$hospital,$fresh->getAttributes(),$freshPlace?->getAttributes())
                ||$fresh->driver_assigned_uuid!==$order->driver_assigned_uuid
                ||$fresh->vehicle_assigned_uuid!==$order->vehicle_assigned_uuid
                ||$fresh->payload_uuid!==$order->payload_uuid||$fresh->status!==$order->status)continue;
            $meta=is_object($order->meta)?(array)$order->meta:(is_array($order->meta)?$order->meta:[]);
            $review=$meta['mapa_dispatch_review']??[];$review=is_object($review)?(array)$review:$review;
            $items[]=['order_id'=>$order->public_id,'status'=>$order->status,
                'handover_state'=>'awaiting_crew_report','can_acknowledge_handover'=>false,
                'caller_reported'=>HospitalHandoverPolicy::callerReported(is_array($review)?$review:[]),
                'crew_observed'=>null,'destination'=>$this->placeView($place),
                'ambulance'=>['id'=>$vehicle->public_id,'name'=>$vehicle->name,'registration'=>$vehicle->plate_number],
                'contacts'=>TripContacts::forOrder($order,$company,$place),'tracking'=>$tracking,
                'updated_at'=>$order->updated_at?->toISOString()];
        }
        return ['items'=>$items,'truncated'=>$truncated];
    }
    /** Controller overview requires a current, explicitly bound staff session. */
    public function overview(Request $request): array {
        $this->enabled(); $c=$this->context($request);
        if(!in_array($c['binding']->role,['operator','admin','superadmin'],true)) self::fail('Controller access required.');
        $items=[];
        foreach(DB::table('mapa_mobile_hospital_handovers')->where('company_uuid',$c['company'])->orderByDesc('created_at')->limit(100)->get() as $row){
            $order=$this->native(Order::class,$c['company'])->where('uuid',$row->order_uuid)->first();
            $place=$this->native(Place::class,$c['company'])->where('uuid',$row->place_uuid)->first();
            if(!$order || !$place) continue;
            $destination=$this->destination($order,$c['company']);
            $current=HospitalHandoverPolicy::receivingPlaceMatches($c['company'],$row->place_uuid,(array)$row,$order->getAttributes(),$destination?->getAttributes());
            $items[]=[...$this->view($row,$order,$place,$c['company'],$current),'current'=>$current];
        }
        return ['handovers'=>$items,'delivery_mode'=>'authenticated_polling','push_available'=>(new PushService)->hospitalAvailable()];
    }
    public function respond(Request $request,string $id): array {
        $this->enabled(); $data=$request->validate(['revision'=>'required|integer|min:1','status'=>'required|in:'.implode(',',HospitalHandoverPolicy::RESPONSES)]);
        return DB::transaction(function()use($request,$id,$data){
            $c=$this->context($request,true);
            if($c['binding']->role!=='hospital' || !$c['place']) self::fail('Hospital access required.');
            // Same lock order as crew write: native order, payload/place, then report.
            $row=DB::table('mapa_mobile_hospital_handovers')->where(['id'=>$id,'company_uuid'=>$c['company'],'place_uuid'=>$c['place']->uuid])->first();
            if(!$row) self::fail('Incoming trip not found.',404);
            $order=$this->native(Order::class,$c['company'])->where('uuid',$row->order_uuid)->lockForUpdate()->first();
            $place=$order?$this->destination($order,$c['company'],true):null;
            $row=DB::table('mapa_mobile_hospital_handovers')->where('id',$id)->lockForUpdate()->first();
            if(!$order || !HospitalHandoverPolicy::receivingPlaceMatches($c['company'],$c['place']->uuid,(array)$row,$order->getAttributes(),$place?->getAttributes())) self::fail('Incoming trip destination changed or trip ended.',409);
            if((int)$row->revision!==(int)$data['revision']) self::fail('Handover changed. Refresh and review.',409);
            $revision=$row->revision+1;
            DB::table('mapa_mobile_hospital_handovers')->where('id',$id)->update(['status'=>$data['status'],'revision'=>$revision,'updated_at'=>now()]);
            DB::table('mapa_mobile_hospital_responses')->insert(['handover_id'=>$id,'company_uuid'=>$c['company'],'user_uuid'=>$c['user']->uuid,'status'=>$data['status'],'revision'=>$revision,'created_at'=>now()]);
            $row->status=$data['status']; $row->revision=$revision; $row->updated_at=now()->toDateTimeString();
            return ['ok'=>true,'handover'=>$this->view($row,$order,$place,$c['company'])];
        });
    }
    private function view(object $row,Order $order,Place $place,string $company,bool $current=true): array {
        $vehicle=$this->native(Vehicle::class,$company)->where('uuid',$row->vehicle_uuid)->first();
        return [...json_decode(Crypt::decryptString($row->report_encrypted),true,512,JSON_THROW_ON_ERROR),
            'id'=>$row->id,'order_id'=>$order->public_id,'status'=>$row->status,'revision'=>(int)$row->revision,'created_at'=>$row->created_at,'updated_at'=>$row->updated_at,
            'destination'=>$this->placeView($place),'ambulance'=>$vehicle?['id'=>$vehicle->public_id,'name'=>$vehicle->name,'registration'=>$vehicle->plate_number]:null,
            'contacts'=>$current?TripContacts::forOrder($order,$company,$place):TripContacts::empty(),
            'tracking'=>$current?TripTrackingView::forOrder($order->uuid,$company,$order,$place):null];
    }
}

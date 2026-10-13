<?php
namespace App\MapaMobile;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\LaravelMysqlSpatial\Types\Point;

/** Call-scoped caller capabilities and separately authenticated GPS device intake. */
class ConnectedTrackingService extends MobileService
{
    private function clock(): int { return (int)round(microtime(true)*1000); }
    private function enabled(string $flag): void {
        if(!config('mapa_mobile.'.$flag,false) || !Schema::hasTable('mapa_tracking_links') || !Schema::hasTable('mapa_tracking_devices')) self::fail('This tracking integration is not enabled.',503);
    }
    protected function staff(Request $r,bool $lock=false): array {
        $c=$this->context($r,$lock);
        if(!in_array($c['binding']->role,['operator','admin','superadmin'],true)) self::fail('A MAPA operator or administrator is required.');
        return $c;
    }
    private function deviceAdmin(Request $r,bool $lock=false): array {
        $c=$this->staff($r,$lock);
        if(!in_array($c['binding']->role,['admin','superadmin'],true)) self::fail('GPS device management requires an administrator.');
        return $c;
    }
    private function table(string $name,string $company) { return DB::table($name)->where('company_uuid',$company)->whereNull('deleted_at'); }
    private function call(string $id,string $company,bool $lock=false): object {
        $q=DB::table('mapa_voice_calls')->where(['id'=>$id,'company_uuid'=>$company]);
        $call=($lock?$q->lockForUpdate():$q)->first();
        if(!$call) self::fail('Call not found.',404);
        SourceCallStore::assertCall($call);
        if(ConnectedTrackingPolicy::closed($call->state)) self::fail('This call has ended.',410);
        if($call->order_uuid){
            $q=$this->table('orders',$company)->where('uuid',$call->order_uuid);
            $order=($lock?$q->lockForUpdate():$q)->first();
            if(!$order || ConnectedTrackingPolicy::closed($order->status)) self::fail('This trip has ended.',410);
        }
        return $call;
    }
    public function issue(Request $r,string $id): array {
        $this->enabled('caller_tracking_enabled');
        if($r->input('consent_to_message')!==true) self::fail('Confirm permission to send this caller a trip link.',422);
        $scope=$r->input('scope','pickup');
        if(!in_array($scope,['pickup','full_journey'],true))self::fail('Invalid trip link scope.',422);
        $replace=$r->input('replace_existing',false);
        if(!is_bool($replace))self::fail('Replacing an existing link must be an explicit choice.',422);
        if($scope==='full_journey'&&!Schema::hasColumn('mapa_tracking_links','scope'))self::fail('Journey tracking migration is required.',503);
        return DB::transaction(function()use($r,$id,$scope,$replace){
            $c=$this->staff($r,true); $call=$this->call($id,$c['company'],true);
            if($call->demo) self::fail('Caller links are unavailable for training calls.',409);
            if(ConnectedTrackingPolicy::supervisedPilot($call)) self::fail('A no-patient pilot has no caller or tracking link.',409);
            $extra=[];
            if($scope==='full_journey'){
                $phone=$this->callback($call);
                if($phone==='')self::fail('Save a valid callback number before creating a journey link.',422);
                $extra=['scope'=>$scope,'callback_hash'=>hash('sha256',$phone),'order_uuid'=>$call->order_uuid];
            }
            $now=$this->clock(); $token=bin2hex(random_bytes(32)); $uuid=(string)Str::uuid();
            $expiry=$now+min(240,max(5,(int)config('mapa_mobile.tracking_link_minutes',120)))*60000;
            // A second automatic issue must not kill a link the caller already opened.
            // A link bound to an old callback or replaced order is unusable anyway.
            $current=DB::table('mapa_tracking_links')->where(['company_uuid'=>$c['company'],'call_id'=>$id])
                ->whereNull('revoked_at')->where('expires_at','>',$now)->get();
            $usable=$current->contains(function($link)use($call){
                if(($link->scope??'pickup')!=='full_journey')return true;
                return hash_equals((string)($link->callback_hash??''),hash('sha256',$this->callback($call)))
                    &&($link->order_uuid===null||$link->order_uuid===$call->order_uuid);
            });
            if($usable&&!$replace)self::fail('A private tracker link is already active for this call. Replace it only after confirming with the caller.',409);
            DB::table('mapa_tracking_links')->where(['company_uuid'=>$c['company'],'call_id'=>$id])->whereNull('revoked_at')->update(['revoked_at'=>$now,'updated_at'=>now()]);
            DB::table('mapa_tracking_links')->insert([...$extra,'id'=>$uuid,'company_uuid'=>$c['company'],'call_id'=>$id,'token_hash'=>hash('sha256',$token),'created_by'=>$c['user']->uuid,'expires_at'=>$expiry,'created_at'=>now(),'updated_at'=>now()]);
            $origin=rtrim((string)config('mapa_mobile.tracking_origin',''),'/'); $url=null;
            $parts=parse_url($origin);
            if(filter_var($origin,FILTER_VALIDATE_URL) && ($parts['scheme']??'')==='https' && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment']) && empty($parts['path'])) $url=$origin.'/track/#'.$token;
            return ['id'=>$uuid,'token'=>$token,'expires_at'=>$expiry,'url'=>$url,'scope'=>$scope,'message_sent'=>false];
        });
    }
    private function callback(object $call): string {
        $review=json_decode($call->review?:($call->draft?:'{}'),true);
        try{return CallerPhone::normalize(is_array($review)?($review['callback_number']??''):'');}
        catch(\InvalidArgumentException $e){return '';}
    }
    public function revoke(Request $r,string $id): array {
        $this->enabled('caller_tracking_enabled');
        return DB::transaction(function()use($r,$id){
            $c=$this->staff($r,true);
            $link=DB::table('mapa_tracking_links')->where(['id'=>$id,'company_uuid'=>$c['company']])->first();
            if(!$link) self::fail('Link not found.',404);
            // Issuance and public writes serialize on the call, then the link.
            DB::table('mapa_voice_calls')->where(['id'=>$link->call_id,'company_uuid'=>$c['company']])->lockForUpdate()->first();
            DB::table('mapa_tracking_links')->where('id',$id)->update(['revoked_at'=>$this->clock(),'updated_at'=>now()]);
            return ['ok'=>true];
        });
    }
    private function link(Request $r,bool $lock=false): array {
        $raw=(string)$r->bearerToken();
        if(!ConnectedTrackingPolicy::token($raw)) self::fail('A valid trip link is required.',401);
        $q=DB::table('mapa_tracking_links')->where('token_hash',hash('sha256',$raw))->where('company_uuid',$this->company());
        $link=$q->first();
        if(!ConnectedTrackingPolicy::active($link,$this->clock())) self::fail('Trip link expired or was revoked.',410);
        $call=$this->call($link->call_id,$link->company_uuid,$lock);
        if(ConnectedTrackingPolicy::supervisedPilot($call)) self::fail('This no-patient pilot link is unavailable.',410);
        if($lock){$link=$q->lockForUpdate()->first();if(!ConnectedTrackingPolicy::active($link,$this->clock()))self::fail('Trip link expired or was revoked.',410);}
        if($call->demo) self::fail('This trip link is unavailable.',410);
        if(($link->scope??'pickup')==='full_journey'){
            if(!is_string($link->callback_hash)||!hash_equals($link->callback_hash,hash('sha256',$this->callback($call))))self::fail('Callback number changed. Request a new trip link.',410);
            if($link->order_uuid!==null&&$link->order_uuid!==$call->order_uuid)self::fail('Trip changed. Request a new trip link.',410);
            if($link->order_uuid===null&&$call->order_uuid!==null){
                if(!$lock)self::fail('Trip must be refreshed.',409);
                DB::table('mapa_tracking_links')->where('id',$link->id)->update(['order_uuid'=>$call->order_uuid,'updated_at'=>now()]);
                $link->order_uuid=$call->order_uuid;
            }
        }
        return [$link,$call];
    }
    public function locations(Request $r,string $id): array {
        $this->enabled('caller_tracking_enabled');$c=$this->staff($r);$call=$this->call($id,$c['company']);
        $columns=['l.*','p.latitude','p.longitude','p.accuracy_m','p.recorded_at','p.consented_at'];
        if(Schema::hasColumn('mapa_caller_locations','pickup_confirmation'))$columns[]='p.pickup_confirmation';
        $items=DB::table('mapa_tracking_links as l')->leftJoin('mapa_caller_locations as p',function($join){$join->on('p.link_id','=','l.id')->on('p.company_uuid','=','l.company_uuid');})
            ->where('l.company_uuid',$c['company'])->where('l.call_id',$id)->whereNull('l.revoked_at')->where('l.expires_at','>',$this->clock())
            ->select($columns)->get();
        $items=$items->filter(fn($v)=>($v->scope??'pickup')!=='full_journey'||(
            hash_equals((string)$v->callback_hash,hash('sha256',$this->callback($call)))
            &&($v->order_uuid===null||$v->order_uuid===$call->order_uuid)));
        $order=$call->order_uuid?$this->native(\Fleetbase\FleetOps\Models\Order::class,$c['company'])->where('uuid',$call->order_uuid)->first():null;
        $onboard=$order&&Schema::hasTable('mapa_mobile_hospital_handovers')&&DB::table('mapa_mobile_hospital_handovers')->where(['company_uuid'=>$c['company'],'order_uuid'=>$order->uuid])->exists();
        $pickupAllowed=!$onboard&&(!$order||$order->status!=='enroute');
        return ['locations'=>$items->map(fn($v)=>['link_id'=>$v->id,'expires_at'=>(int)$v->expires_at,'location'=>$v->recorded_at?$this->position($v,'caller_browser'):null,
                'caller_pickup_confirmation'=>$pickupAllowed?CallerPickupEvidence::read($v,$this->clock()):null])->values()->all(),
            'pickup_requires_operator_confirmation'=>true,'order_revision'=>$order?$this->revision($order):null,
            'can_confirm_live_pickup'=>$order&&$order->dispatched&&in_array($order->status,['dispatched','started'],true)&&!$onboard&&Schema::hasTable('mapa_caller_pickup_confirmations')];
    }
    public function location(Request $r): array {
        $this->enabled('caller_tracking_enabled');
        if($r->input('consent')!==true) self::fail('Location sharing requires your permission.',422);
        $confirm=$r->input('confirm_pickup',false);
        if(!is_bool($confirm))self::fail('Pickup confirmation must be an explicit choice.',422);
        if($confirm&&!Schema::hasColumn('mapa_caller_locations','pickup_confirmation'))self::fail('Caller pickup confirmation is being prepared. Contact control.',503);
        $fix=$r->validate(['latitude'=>'required|numeric','longitude'=>'required|numeric','accuracy_m'=>'required|numeric','recorded_at'=>'required|integer']);
        return DB::transaction(function()use($r,$fix,$confirm){
            [$link,$call]=$this->link($r,true);
            $order=$call->order_uuid?$this->table('orders',$link->company_uuid)->where('uuid',$call->order_uuid)->first():null;
            if($order && ($order->status==='enroute' || (Schema::hasTable('mapa_mobile_hospital_handovers') && DB::table('mapa_mobile_hospital_handovers')->where(['company_uuid'=>$link->company_uuid,'order_uuid'=>$order->uuid])->exists()))) self::fail('Pickup is complete. Caller location sharing has ended.',409);
            $last=DB::table('mapa_caller_locations')->where(['link_id'=>$link->id,'company_uuid'=>$link->company_uuid])->lockForUpdate()->first();
            $now=$this->clock();
            if(!ConnectedTrackingPolicy::fix($fix,$now))self::fail('Location is stale or inaccurate. Request a fresh location.',422);
            $fix=CallerPickupEvidence::fix($fix);$evidence=CallerPickupEvidence::read($last,$now);
            if($confirm&&$evidence&&CallerPickupEvidence::same($evidence,$fix))
                return ['ok'=>true,'replayed'=>true,'pickup_requires_operator_confirmation'=>true,'recorded_at'=>$fix['recorded_at'],'caller_pickup_confirmation'=>$evidence];
            $sameLatest=$confirm&&$last&&CallerPickupEvidence::same((array)$last,$fix);
            if(!$sameLatest&&!ConnectedTrackingPolicy::fix($fix,$now,(int)($last->recorded_at??0)))self::fail('Location is out of order. Request a fresh location before confirming pickup.',422);
            $extra=$confirm?['pickup_confirmation'=>json_encode([...$fix,'confirmed_at'=>$now],JSON_THROW_ON_ERROR)]:[];
            DB::table('mapa_caller_locations')->updateOrInsert(['link_id'=>$link->id],['company_uuid'=>$link->company_uuid,...$fix,...$extra,'consented_at'=>$last->consented_at??$now,'created_at'=>$last->created_at??now(),'updated_at'=>now()]);
            if($confirm)$evidence=CallerPickupEvidence::read((object)$extra,$now);
            return ['ok'=>true,'replayed'=>false,'pickup_requires_operator_confirmation'=>true,'recorded_at'=>$fix['recorded_at'],'caller_pickup_confirmation'=>$evidence];
        });
    }
    public function view(Request $r): array {
        $this->enabled('caller_tracking_enabled');
        return DB::transaction(function()use($r){
            [$link,$call]=$this->link($r,true);$ambulance=null;
            if(($link->scope??'pickup')==='full_journey'){
                $order=$call->order_uuid?$this->native(\Fleetbase\FleetOps\Models\Order::class,$link->company_uuid)->where('uuid',$call->order_uuid)->first():null;
                $payload=$order?$this->payload($order,$link->company_uuid):null;
                $trip=TripTrackingView::forOrder($call->order_uuid,$link->company_uuid,$order);
                $contacts=TripContacts::forOrder($order,$link->company_uuid,$payload?->dropoff,true);
                // Callback is already bound to this capability by link(); never expose
                // an unrelated historical order callback or a patient's name.
                $contacts['caller']=TripContacts::contact('Callback number',$this->callback($call),'Callback number');
                return [...$trip,'expires_at'=>(int)$link->expires_at,'scope'=>'full_journey',
                    ...$this->callerLocationView($link,$trip['caller_location_allowed']===true),
                    'contacts'=>$contacts,
                    'status'=>$trip['stage']==='to_hospital'?'pickup_complete':($trip['ambulance']?'ambulance_assigned':'awaiting_assignment')];
            }
            $order=$call->order_uuid?$this->table('orders',$link->company_uuid)->where('uuid',$call->order_uuid)->first():null;
            $onboard=$order && Schema::hasTable('mapa_mobile_hospital_handovers') && DB::table('mapa_mobile_hospital_handovers')->where(['company_uuid'=>$link->company_uuid,'order_uuid'=>$order->uuid])->exists();
            // Caller link exposes only the pickup leg, never the patient's hospital journey.
            $approaching=$order && $order->dispatched && in_array($order->status,['dispatched','started'],true) && !$onboard;
            if($approaching && $order->vehicle_assigned_uuid){
                $v=$this->table('vehicles',$link->company_uuid)->where('uuid',$order->vehicle_assigned_uuid)->first();
                if($v)$ambulance=['name'=>$v->name,'registration'=>$v->plate_number,'location'=>$this->vehiclePosition($link->company_uuid,$v,$order->driver_assigned_uuid)];
            }
            $pickupAllowed=!$onboard&&(!$order||$order->status!=='enroute');
            $trip=$approaching?TripTrackingView::forOrder($order->uuid,$link->company_uuid):null;
            return ['expires_at'=>(int)$link->expires_at,'status'=>$pickupAllowed?($ambulance?'ambulance_assigned':'awaiting_assignment'):'pickup_complete',
                'ambulance'=>$ambulance,'eta'=>null,'caller_location_allowed'=>$pickupAllowed,'pickup'=>$trip['pickup']??null,
                'planned_road_route'=>$trip['planned_road_route']??null,...$this->callerLocationView($link,$pickupAllowed)];
        });
    }
    private function callerLocationView(object $link,bool $pickupAllowed): array {
        $position=DB::table('mapa_caller_locations')->where(['link_id'=>$link->id,'company_uuid'=>$link->company_uuid])->first();
        return ['caller_location'=>$position?$this->position($position,'caller_browser'):null,
            'caller_pickup_confirmation'=>$pickupAllowed?CallerPickupEvidence::read($position,$this->clock()):null];
    }
    private function position(object $p,string $source): array {
        $fix=['latitude'=>(float)$p->latitude,'longitude'=>(float)$p->longitude,'accuracy_m'=>$p->accuracy_m===null?null:(float)$p->accuracy_m,'recorded_at'=>(int)$p->recorded_at];
        if ($source==='hardware_gps') {
            $fix += ['source_protocol'=>$p->source_protocol??null,'gps_valid'=>isset($p->gps_valid)?(bool)$p->gps_valid:null,'satellites'=>isset($p->satellites)?(int)$p->satellites:null];
            if (($p->source_protocol??null)==='way4track') $fix += ['provider_received_at'=>isset($p->provider_received_at)?(int)$p->provider_received_at:null,
                'position_quality'=>'provider_reported','dispatch_eligible'=>false];
        }
        return [...$fix,'source'=>$source,'stale'=>!($source==='hardware_gps'?HardwareFixPolicy::fresh($fix,$this->clock()):ConnectedTrackingPolicy::fix($fix,$this->clock()))];
    }
    public function vehiclePosition(string $company,object $v,?string $driver): ?array {
        $sources=$this->vehicleLocationSources($company,$v,$driver);
        foreach(['ambulance_gps','driver_phone'] as $key) if($sources[$key] && !$sources[$key]['stale']) return $sources[$key];
        return null;
    }
    /** Keep independent fixes visible without mislabelling phone GPS as hardware. */
    public function vehicleLocationSources(string $company,object $v,?string $driver): array {
        $sources=['ambulance_gps'=>null,'driver_phone'=>null];
        if($v->company_uuid!==$company) return $sources;
        $p=Schema::hasTable('mapa_device_positions')&&Schema::hasTable('mapa_tracking_devices')?DB::table('mapa_device_positions as p')->join('mapa_tracking_devices as d','d.id','=','p.device_id')
            ->where('p.company_uuid',$company)->where('p.vehicle_uuid',$v->uuid)->where('d.company_uuid',$company)->where('d.vehicle_uuid',$v->uuid)->whereNull('d.revoked_at')->orderByDesc('p.recorded_at')->select('p.*')->first():null;
        if($p) $sources['ambulance_gps']=$this->position($p,'hardware_gps');
        if($driver&&Schema::hasTable('mapa_mobile_receipts')){
            $d=$this->table('drivers',$company)->where('uuid',$driver)->where('vehicle_uuid',$v->uuid)->first();
            if($d){
                $receipt=DB::table('mapa_mobile_receipts')->where('company_uuid',$company)->where('user_uuid',$d->user_uuid)
                    ->where('result->gps_evidence->driver_id',$d->public_id)->where('result->gps_evidence->vehicle_id',$v->public_id)->orderByDesc('id')->first();
                $e=$receipt?(json_decode($receipt->result,true)['gps_evidence']??null):null;
                if(is_array($e) && ($e['source']??'')==='device_gps' && ($e['mock_location']??null)===false
                    && isset($e['latitude'],$e['longitude'],$e['recorded_at']) && array_key_exists('accuracy_m',$e)) $sources['driver_phone']=$this->position((object)$e,'crew_phone');
            }
        }
        // No old coordinates advertised as a live position.
        return $sources;
    }
    public function devices(Request $r): array {
        $this->enabled('device_ingest_enabled');$c=$this->deviceAdmin($r);$items=[];
        foreach(DB::table('mapa_tracking_devices')->where('company_uuid',$c['company'])->orderBy('label')->limit(500)->get() as $d){
            $v=$this->table('vehicles',$c['company'])->where('uuid',$d->vehicle_uuid)->first();
            $p=DB::table('mapa_device_positions')->where(['device_id'=>$d->id,'company_uuid'=>$c['company']])->first();
            $items[]=['id'=>$d->id,'label'=>$d->label,'external_id'=>$d->external_id,'revoked_at'=>$d->revoked_at,'vehicle_id'=>$v?->public_id,'vehicle_name'=>$v?->name,'registration'=>$v?->plate_number,'location'=>$p?$this->position($p,'hardware_gps'):null];
        }
        $vehicles=$this->table('vehicles',$c['company'])->orderBy('plate_number')->limit(1000)->get()->filter(fn($v)=>$this->eligibleDeviceVehicle($v))->map(fn($v)=>['id'=>$v->public_id,'name'=>$v->name,'registration'=>$v->plate_number])->values()->all();
        return ['devices'=>$items,'vehicles'=>$vehicles];
    }
    private function eligibleDeviceVehicle(object $v): bool {
        $meta=json_decode($v->meta?:'{}',true);
        return is_array($meta) && empty($meta['mapa_demo']) && empty($meta['simulated']) && empty($meta['archived'])
            && !in_array(strtolower((string)$v->status),['archived','inactive','deleted','retired'],true);
    }
    public function createDevice(Request $r): array {
        $this->enabled('device_ingest_enabled');
        $b=$r->validate(['vehicle_id'=>'required|string|max:100','external_id'=>'required|string|max:100|regex:/^[A-Za-z0-9._:-]+$/D','label'=>'required|string|max:150']);
        return DB::transaction(function()use($r,$b){
            $c=$this->deviceAdmin($r,true);
            // Tenant lock makes external-ID registration race safe.
            DB::table('companies')->where('uuid',$c['company'])->lockForUpdate()->first();
            $v=$this->table('vehicles',$c['company'])->where('public_id',$b['vehicle_id'])->lockForUpdate()->first();
            if(!$v)self::fail('Ambulance not found.',404);
            if(!$this->eligibleDeviceVehicle($v))self::fail('Select an active real ambulance.',422);
            if(DB::table('mapa_tracking_devices')->where(['company_uuid'=>$c['company'],'external_id'=>$b['external_id']])->exists())self::fail('This device is already registered. Revoke it before arranging a replacement identifier.',409);
            if(DB::table('mapa_tracking_devices')->where(['company_uuid'=>$c['company'],'vehicle_uuid'=>$v->uuid])->whereNull('revoked_at')->exists())self::fail('This ambulance already has an active device. Revoke it first.',409);
            $secret=bin2hex(random_bytes(32));$id=(string)Str::uuid();
            DB::table('mapa_tracking_devices')->insert(['id'=>$id,'company_uuid'=>$c['company'],'vehicle_uuid'=>$v->uuid,'external_id'=>$b['external_id'],'label'=>$b['label'],'secret_hash'=>hash('sha256',$secret),'created_by'=>$c['user']->uuid,'created_at'=>now(),'updated_at'=>now()]);
            return ['id'=>$id,'secret'=>$secret,'vehicle_id'=>$v->public_id,'protocol'=>'mapa-device-json-v1'];
        });
    }
    public function revokeDevice(Request $r,string $id): array {
        $this->enabled('device_ingest_enabled');
        return DB::transaction(function()use($r,$id){
            $c=$this->deviceAdmin($r,true);$d=DB::table('mapa_tracking_devices')->where(['id'=>$id,'company_uuid'=>$c['company']])->lockForUpdate()->first();
            if(!$d)self::fail('Device not found.',404);
            DB::table('mapa_tracking_devices')->where('id',$id)->update(['revoked_at'=>$this->clock(),'updated_at'=>now()]);
            return ['ok'=>true];
        });
    }
    public function ingest(Request $r): array {
        $this->enabled('device_ingest_enabled');
        if(!Schema::hasTable('mapa_device_position_history') || !Schema::hasTable('mapa_vehicle_position_states'))self::fail('GPS history and projection migration is required.',503);
        $raw=(string)$r->bearerToken();
        if(!ConnectedTrackingPolicy::token($raw))self::fail('A valid device credential is required.',401);
        $gt06=$r->input('source_protocol')==='gt06';
        $way4track=$r->input('source_protocol')==='way4track';
        if(($gt06||$way4track) && !Schema::hasColumn('mapa_device_positions','source_protocol'))self::fail('Hardware quality migration is required.',503);
        if($way4track && (!Schema::hasColumn('mapa_device_positions','provider_received_at') || !Schema::hasColumn('mapa_device_position_history','provider_received_at')))self::fail('Provider receipt migration is required.',503);
        $b=$r->validate(['latitude'=>'required|numeric','longitude'=>'required|numeric','accuracy_m'=>($gt06||$way4track)?'present|nullable|numeric':'required|numeric','recorded_at'=>'required|integer',
            'source_protocol'=>'sometimes|in:gt06,way4track','gps_valid'=>$gt06?'required|boolean':'prohibited','satellites'=>$gt06?'required|integer|min:4|max:15':'prohibited',
            'position_quality'=>$way4track?'required|in:provider_reported':'prohibited','provider_received_at'=>$way4track?'required|integer':'prohibited',
            'external_id'=>($gt06||$way4track)?'required|string|regex:/^[0-9]{15}$/D':'prohibited']);
        return DB::transaction(function()use($r,$raw,$b){
            $d=DB::table('mapa_tracking_devices')->where(['secret_hash'=>hash('sha256',$raw),'company_uuid'=>$this->company()])->lockForUpdate()->first();
            if(!$d || $d->revoked_at!==null)self::fail('Device credential expired or was revoked.',401);
            if(isset($b['external_id']) && !hash_equals($d->external_id,$b['external_id']))self::fail('Device identity does not match its credential.',401);
            unset($b['external_id']);
            if(in_array($b['source_protocol']??null,['gt06','way4track'],true)){
                $b['latitude']=round((float)$b['latitude'],7);$b['longitude']=round((float)$b['longitude'],7);
            }
            $vehicle=$this->native(Vehicle::class,$d->company_uuid)->where('uuid',$d->vehicle_uuid)->lockForUpdate()->first();
            if(!$vehicle || !$this->eligibleDeviceVehicle((object)$vehicle->getRawOriginal()))self::fail('Device ambulance is unavailable.',409);
            $last=DB::table('mapa_device_positions')->where('device_id',$d->id)->lockForUpdate()->first();
            // Receipt time cannot freshen an old provider fix, even on a duplicate retry.
            if(($b['source_protocol']??null)==='way4track' && !Way4TrackFixPolicy::fresh($b,$this->clock()))self::fail('Provider location is invalid, stale or has inconsistent timestamps.',422);
            if(in_array($b['source_protocol']??null,['gt06','way4track'],true) && $last && HardwareFixPolicy::same($b,$last))return ['ok'=>true,'duplicate'=>true,'recorded_at'=>(int)$last->recorded_at,'source'=>'hardware_gps','map_updated'=>false];
            if(!HardwareFixPolicy::fresh($b,$this->clock(),(int)($last->recorded_at??0)))self::fail('GPS fix is invalid, stale, inaccurate or out of order.',422);
            unset($b['position_quality']); // Explicit at ingress; derived from retained provider identity on reads.
            if(Schema::hasColumn('mapa_device_positions','source_protocol'))$b+=['source_protocol'=>null,'gps_valid'=>null,'satellites'=>null];
            if(Schema::hasColumn('mapa_device_positions','provider_received_at'))$b+=['provider_received_at'=>null];
            // Hardware locates the ambulance, never proves a driver's presence or duty.
            $position=['location'=>new Point((float)$b['latitude'],(float)$b['longitude']),
                'latitude'=>(float)$b['latitude'],'longitude'=>(float)$b['longitude'],'heading'=>null];
            $projected=VehiclePositionProjection::apply($vehicle,$position,(int)$b['recorded_at'],'hardware_gps',$d->id);
            DB::table('mapa_device_positions')->updateOrInsert(['device_id'=>$d->id],['company_uuid'=>$d->company_uuid,'vehicle_uuid'=>$d->vehicle_uuid,...$b,'created_at'=>$last->created_at??now(),'updated_at'=>now()]);
            DB::table('mapa_device_position_history')->insert(['device_id'=>$d->id,'company_uuid'=>$d->company_uuid,'vehicle_uuid'=>$d->vehicle_uuid,...$b,'received_at'=>$this->clock(),'created_at'=>now()]);
            if($projected){
                DB::afterCommit(function()use($vehicle,$d){
                    try{event(new CrewVehicleLocationChanged($vehicle,$d->company_uuid,['source'=>'hardware_gps']));}
                    catch(\Throwable $e){try{\Illuminate\Support\Facades\Log::warning('MAPA hardware map broadcast unavailable.',['exception_class'=>get_class($e)]);}catch(\Throwable $ignored){}}
                });
            }
            return ['ok'=>true,'recorded_at'=>(int)$b['recorded_at'],'source'=>'hardware_gps','map_updated'=>$projected];
        });
    }
}

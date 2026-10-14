<?php
namespace App\MapaVoice;
use App\MapaMobile\MobileService;
use App\MapaMobile\CallerPhone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Native MAPA review and dispatch. Provider input never executes an assignment. */
class VoiceService
{
    private const SUPERVISED_PILOT_LABEL='MAPA PILOT — NO PATIENT';
    public static function fail(string $message,int $status=403): never {throw new HttpException($status,$message);}
    protected function company(): string {
        $id=(string)config('mapa.company_uuid');
        if(!config('mapa_voice.enabled')||!Policy::uuid($id)||!Schema::hasTable('mapa_voice_calls'))self::fail('MAPA voice is not configured.',503);
        return $id;
    }
    protected function staff(Request $r,bool $lock=false): array {
        $this->company(); $c=(new MobileService)->context($r,$lock);
        if(!in_array($c['binding']->role,['operator','admin','superadmin'],true))self::fail('A MAPA operator or administrator is required.');
        return $c;
    }
    protected function native(string $table,string $company){return DB::table($table)->where('company_uuid',$company)->whereNull('deleted_at');}
    protected function lockTenant(string $company): void {
        if(!DB::table('companies')->where('uuid',$company)->whereNull('deleted_at')->lockForUpdate()->first())self::fail('MAPA company is unavailable.',503);
    }
    protected function call(string $id,string $company,bool $lock=false) {
        $q=DB::table('mapa_voice_calls')->where(['id'=>$id,'company_uuid'=>$company]);
        $v=($lock?$q->lockForUpdate():$q)->first(); if(!$v)self::fail('Call not found.',404);return $v;
    }
    protected function view(object $v): array {
        $draft=Policy::meta($v->draft);
        $review=$v->review?Policy::meta($v->review):null;
        $order=$v->order_uuid?$this->native('orders',$v->company_uuid)->where('uuid',$v->order_uuid)->first():null;
        $state=$order?DB::table('mapa_mobile_order_states')->where(['company_uuid'=>$v->company_uuid,'order_uuid'=>$order->uuid,'driver_uuid'=>$order->driver_assigned_uuid])->first():null;
        $native=$state?Order::withoutGlobalScopes()->where('company_uuid',$v->company_uuid)->where('uuid',$order->uuid)->first():null;
        $ack=$native&&hash_equals($state->assignment_hash,hash('sha256',json_encode(Policy::canonical($native->getAttributes()),JSON_THROW_ON_ERROR)));
        $driver=$order?$this->native('drivers',$v->company_uuid)->where('uuid',$order->driver_assigned_uuid)->first():null;
        $vehicle=$order?$this->native('vehicles',$v->company_uuid)->where('uuid',$order->vehicle_assigned_uuid)->first():null;
        $progress=CommandDeskPolicy::tripProgress($order,$driver,$vehicle,$ack?(int)($state->acknowledged_at??0):null,(int)round(microtime(true)*1000));
        $automaticAvailable=class_exists(AutomaticDispatch::class)&&AutomaticDispatch::available();
        $automaticReason=$v->provider==='asrhealth'?'source_owned':(!$automaticAvailable?'not_commissioned':
            (AutomaticDispatchScope::allowsCallId($v->id)?null:'pilot_scope'));
        return ['id'=>$v->id,'revision'=>(int)$v->revision,'state'=>$v->state,'demo'=>(bool)$v->demo,'source'=>$v->provider,
            'supervised_pilot'=>($draft['supervised_pilot']??false)===true,
            'automatic_dispatch_available'=>$automaticReason===null,
            'automatic_dispatch_unavailable_reason'=>$automaticReason,
            'caller'=>$v->caller,'called'=>$v->called,'provider_status'=>$v->provider_status,'transcript'=>$v->transcript??'',
            'source_owned'=>$v->provider==='asrhealth','draft'=>$draft,'review'=>$review,
            'order_id'=>$order?->public_id,'order_status'=>$order?->status,'acknowledged'=>$progress['accepted'],
            'trip_progress'=>$progress,
            'automatic_dispatch'=>class_exists(AutomaticDispatch::class)?(new AutomaticDispatch)->status($v->id,$v->company_uuid):null,
            'created_at'=>\Carbon\Carbon::parse($v->created_at,'UTC')->toIso8601String(),'updated_at'=>\Carbon\Carbon::parse($v->updated_at,'UTC')->toIso8601String()];
    }
    public function index(Request $r): array {
        $c=$this->staff($r);
        $sourceLocations=[];$sourceDirectory=null;$withDirectory=$r->query('source_locations')==='1';
        if($withDirectory)try {
            $sourceState=\App\MapaMobile\SourceInventoryController::verifiedState($c['company']);
            if(time()-$sourceState['received_at']<=120){$sourceDirectory=SourceIntakeDirectory::get($c['company']);$sourceLocations=array_map(static fn($row)=>['name'=>(string)$row['name']],$sourceState['snapshot']['datasets']['hospitals']);}
        } catch(\Throwable $e) { /* Manual intake remains available without source sync. */ }
        $calls=DB::table('mapa_voice_calls')->where('company_uuid',$c['company']);
        if(!config('mapa_voice.demo_enabled'))$calls->where('demo',false);
        return ['contract'=>Policy::CONTRACT,'calls'=>$calls->orderByDesc('updated_at')->limit(100)->get()->map(fn($v)=>$this->view($v))->all(),
            'capabilities'=>['demo'=>$c['binding']->role!=='operator'&&(bool)config('mapa_voice.demo_enabled'),'live_intake'=>(bool)config('mapa_voice.live_enabled'),
                'manual_intake'=>(bool)config('mapa_voice.manual_enabled'),
                'supervised_pilot'=>$c['binding']->role!=='operator'&&(bool)config('mapa_voice.manual_enabled')&&(bool)config('mapa_voice.supervised_pilot_intake_enabled'),
                'location_search'=>(bool)config('mapa_voice.geocoding_enabled'),
                'caller_tracking'=>(bool)config('mapa_mobile.caller_tracking_enabled') && Schema::hasTable('mapa_tracking_links'),
                'hospital_handover'=>\App\MapaMobile\HospitalHandoverService::available(),
                'device_tracking'=>(bool)config('mapa_mobile.device_ingest_enabled') && Schema::hasTable('mapa_tracking_devices'),
                'automatic_dispatch'=>class_exists(AutomaticDispatch::class)&&AutomaticDispatch::available(),
                'command_desk'=>class_exists(CommandDeskService::class),
                'dispatch_requires_confirmation'=>true,'push_notifications'=>false,'road_eta'=>false,'extraction'=>(new DraftExtractor)->ready()],
            'pickup_map'=>PickupMapConfig::get(),
            'server_time'=>(int)round(microtime(true)*1000)]+($withDirectory?['source_locations'=>$sourceLocations,'source_directory'=>$sourceDirectory]:[]);
    }
    /** Read-only native statistics. Authentication and tenant scope precede every query. */
    public function commandDesk(Request $r): array {
        $c=$this->staff($r);
        if(!class_exists(CommandDeskService::class))self::fail('Command desk is unavailable.',503);
        $filters=$r->validate(['days'=>'sometimes|integer|min:1|max:90','page'=>'sometimes|integer|min:1|max:10000','driver_id'=>'sometimes|string|max:120']);
        $result=(new CommandDeskService)->read($c,$filters);
        return ['contract'=>'mapa-command-desk-v1',...$result,
            'trip_monitoring'=>$result['trip_monitoring']??$result['active_trips']??[],
            'server_time'=>(int)round(microtime(true)*1000)];
    }
    public function show(Request $r,string $id): array {
        $c=$this->staff($r);$call=$this->call($id,$c['company']);$query=$r->query('q','');
        if(!is_string($query)||mb_strlen($query)>200||strlen($query)>800)self::fail('Enter a location, ambulance name or registration up to 200 characters.',422);
        $rawPage=$r->query('destination_page','1');
        if(!is_string($rawPage)||!preg_match('/^[1-9][0-9]{0,4}$/D',$rawPage))
            self::fail('Invalid destination page.',422);
        $destinationPage=(int)$rawPage;
        // Apply tenant, live/DEMO and receiving-place eligibility BEFORE pagination.
        // Stable tie-breakers avoid name collisions changing page membership.
        $destinationRows=$this->receivingPlaces($c['company'],(bool)$call->demo)
            ->orderBy('name')->orderBy('public_id')->orderBy('uuid')
            ->offset(($destinationPage-1)*200)->limit(201)->get();
        $places=$destinationRows->take(200)->map(fn($p)=>['id'=>$p->public_id,'name'=>$p->name,'address'=>$p->street1??''])->values()->all();
        $savedDestination=Policy::meta($call->review)['dropoff_id']??null;
        $selectedDestination=is_string($savedDestination)?$this->receivingPlaces($c['company'],(bool)$call->demo)->where('public_id',$savedDestination)->first():null;
        $directory=$this->directory($call,trim($query));
        $recommendations=class_exists(RecommendationService::class)&&class_exists(AutomaticDispatch::class)
            ?(new AutomaticDispatch)->recommendationsForCall($call,$directory)
            :['ranked'=>[],'unavailable'=>[],'status'=>'unavailable','basis'=>'private_road_travel_time'];
        return ['contract'=>Policy::CONTRACT,'call'=>$this->view($call),...$directory,'recommendations'=>$recommendations,'places'=>$places,
            'selected_destination'=>$selectedDestination?['id'=>$selectedDestination->public_id,'name'=>$selectedDestination->name,'address'=>$selectedDestination->street1??'']:null,
            'destination_page'=>$destinationPage,'destination_page_size'=>200,'destinations_has_more'=>$destinationRows->count()>200];
    }
    /** Shared by option listing, review and dispatch; never trust a browser-only filter. */
    protected function receivingPlaces(string $company,bool $demo) {
        $query=$this->native('places',$company)->where(function($q){
            // Exclude ONLY this module's generated intake pickups, not a receiving
            // hospital which also happens to be used as another trip's pickup.
            $q->whereNull('_key')->orWhere('_key','<>','mapa-voice-v1')
                ->orWhereNull('public_id')->orWhereRaw('public_id NOT REGEXP ?',['^place_mapa_voice_[0-9a-f]{32}$'])
                ->orWhereNull('meta->mapa_voice_call_id')->orWhereNull('meta->mapa_voice_source')
                ->orWhere('meta->mapa_voice_source','<>','dispatcher_confirmed');
        });
        // Match Policy::demo exactly: JSON boolean true, not numeric/string true.
        // Missing/null/non-boolean flags are live, just as Policy::demo specifies.
        return $query->whereRaw("COALESCE((JSON_TYPE(JSON_EXTRACT(meta, '$.mapa_demo')) = 'BOOLEAN' AND JSON_UNQUOTE(JSON_EXTRACT(meta, '$.mapa_demo')) = 'true'), 0) = ?",[$demo?1:0]);
    }
    public function locations(Request $r): array {
        $this->staff($r);
        return ['contract'=>Policy::CONTRACT,...(new LocationSearch)->search($r)];
    }
    public function nearby(Request $r): array {
        $this->staff($r);
        return ['contract'=>Policy::CONTRACT,...(new LocationSearch)->nearby($r)];
    }
    protected function workflowEnabled(object $call): bool {
        if((bool)$call->demo)return (bool)config('mapa_voice.demo_enabled');
        return (bool)config($call->provider==='manual'?'mapa_voice.manual_enabled':'mapa_voice.live_enabled');
    }
    protected function supervisedPilot(object $call): bool {
        return (Policy::meta($call->draft)['supervised_pilot']??false)===true;
    }
    /** The no-patient drill uses an operator-confirmed map point, never a claimed caller confirmation. */
    protected function checkSupervisedPilotDetails(array $values): void {
        if(trim((string)($values['caller_name']??''))!==self::SUPERVISED_PILOT_LABEL
            ||stripos((string)($values['reported_problem']??''),'NO PATIENT')===false)
            self::fail('Label this drill MAPA PILOT — NO PATIENT and state clearly that there is no patient.',422);
        if(trim((string)($values['callback_number']??''))!=='')
            self::fail('A no-patient pilot cannot contain a caller number.',422);
        foreach(['patient_name','patient_gender','incident_type','consciousness','breathing','scene_safety',
            'destination_phc_id','destination_facility_name'] as $key)
            if(trim((string)($values[$key]??''))!=='')
                self::fail('A no-patient pilot cannot contain patient or hospital handover details.',422);
        if(isset($values['patient_count'])||isset($values['patient_age']))
            self::fail('A no-patient pilot cannot contain patient or hospital handover details.',422);
        if(trim((string)($values['pickup_address']??''))==='' || !isset($values['latitude'],$values['longitude']))
            self::fail('Choose the exact no-patient test point on the map.',422);
    }
    protected function audit(object $call,?string $user,string $action,array $details=[]): void {
        DB::table('mapa_voice_audit')->insert(['company_uuid'=>$call->company_uuid,'call_id'=>$call->id,'user_uuid'=>$user,
            'action'=>$action,'revision'=>$call->revision,'details'=>json_encode($details,JSON_THROW_ON_ERROR),'created_at'=>now()]);
    }
    public function bridge(Request $r): array {
        $company=$this->company();$secret=(string)config('mapa_voice.bridge_secret');$token=(string)$r->bearerToken();
        if(strlen($secret)<32||!hash_equals($secret,$token))self::fail('Bridge authorization required.',401);
        $b=$r->validate(['event_id'=>'required|uuid','event_type'=>'required|in:stream.started,transcript.final,stream.ended,stream.failed',
            'account_sid'=>'required|string|max:128','call_sid'=>'required|string|max:128','stream_sid'=>'required|string|max:128',
            'sequence'=>'required|integer|min:0|max:2147483647','occurred_at'=>'required|date','demo'=>'sometimes|boolean',
            'payload'=>'required|array','payload.caller'=>'sometimes|nullable|string|max:40','payload.to'=>'sometimes|nullable|string|max:40',
            'payload.text'=>'sometimes|string|max:16000','payload.language'=>'sometimes|in:en,te,unknown','payload.reason'=>'sometimes|string|max:500',
            'payload.draft'=>'sometimes|array']);
        $demo=($b['demo']??false)===true;
        if($demo?!config('mapa_voice.demo_enabled'):!config('mapa_voice.live_enabled'))self::fail('This voice intake mode is disabled.',503);
        if(!$demo&&(!(string)config('mapa_voice.exotel_account_sid')||!hash_equals((string)config('mapa_voice.exotel_account_sid'),$b['account_sid'])))self::fail('Provider account is not configured.',403);
        if($b['event_type']==='transcript.final'&&trim($b['payload']['text']??'')==='')self::fail('A final transcript is required.',422);
        $hash=Policy::fingerprint('bridge:'.$company,$r->json()->all());
        return DB::transaction(function()use($company,$b,$demo,$hash){
            $this->lockTenant($company);
            $existing=DB::table('mapa_voice_events')->where('id',$b['event_id'])->first();
            if($existing){
                if($existing->company_uuid!==$company||!hash_equals($existing->request_hash,$hash))self::fail('Event ID was already used with different details.',409);
                $v=$this->call($existing->call_id,$company);return ['ok'=>true,'replayed'=>true,'call_id'=>$v->id,'revision'=>(int)$v->revision];
            }
            $call=DB::table('mapa_voice_calls')->where(['company_uuid'=>$company,'provider'=>'exotel','provider_call_id'=>$b['call_sid']])->lockForUpdate()->first();
            if(!$call){
                $id=(string)Str::uuid(); DB::table('mapa_voice_calls')->insert(['id'=>$id,'company_uuid'=>$company,'provider'=>'exotel',
                    'provider_call_id'=>$b['call_sid'],'account_sid'=>$b['account_sid'],'demo'=>$demo,'state'=>'needs_review','provider_status'=>'active',
                    'revision'=>1,'last_sequence'=>0,'caller'=>$b['payload']['caller']??null,'called'=>$b['payload']['to']??null,'draft'=>'{}','created_at'=>now(),'updated_at'=>now()]);
                $call=$this->call($id,$company,true);$fresh=true;
            }else $fresh=false;
            if((bool)$call->demo!==$demo||$call->account_sid!==$b['account_sid'])self::fail('Provider call identity changed.',409);
            if(DB::table('mapa_voice_events')->where(['call_id'=>$call->id,'sequence'=>$b['sequence']])->exists())self::fail('Call sequence was already used by another event. Review the bridge journal.',409);
            if(DB::table('mapa_voice_events')->where('call_id',$call->id)->count()>=500)self::fail('Call event limit reached.',422);
            // Reordered lower sequences are acknowledged and audited, never used to overwrite newer state.
            $apply=$fresh||$b['sequence']>$call->last_sequence;
            $change=[];
            if($apply){
                $change=['last_sequence'=>$b['sequence'],'revision'=>$call->revision+1,'updated_at'=>now()];
                if($b['event_type']==='stream.failed')$change['provider_status']='failed';
                elseif($b['event_type']==='stream.ended'&&$call->provider_status!=='failed')$change['provider_status']='ended';
                if($b['event_type']==='transcript.final'){
                    $transcript=trim(($call->transcript??'')."\n".$b['payload']['text']);
                    if(strlen($transcript)>32000)self::fail('Call transcript limit reached.',422);
                    $change['transcript']=$transcript;
                    $draft=Policy::meta($call->draft);foreach(['caller_name'=>200,'callback_number'=>40,'reported_problem'=>2000,'pickup_address'=>1000,'landmark'=>500] as $key=>$limit){
                        $val=$b['payload']['draft'][$key]??null;if(is_string($val))$draft[$key]=mb_substr($val,0,$limit);
                    }
                    if($draft)$change['draft']=json_encode($draft,JSON_THROW_ON_ERROR);
                    if($call->state==='ready')$change['state']='needs_review';
                }
                DB::table('mapa_voice_calls')->where('id',$call->id)->update($change);
            }
            DB::table('mapa_voice_events')->insert(['id'=>$b['event_id'],'company_uuid'=>$company,'call_id'=>$call->id,'sequence'=>$b['sequence'],
                'event_type'=>$b['event_type'],'request_hash'=>$hash,'occurred_at'=>date('Y-m-d H:i:s',strtotime($b['occurred_at'])),'applied'=>$apply,'created_at'=>now(),'updated_at'=>now()]);
            $call=$this->call($call->id,$company);$this->audit($call,null,$apply?'provider_event':'reordered_event',['event_id'=>$b['event_id'],'type'=>$b['event_type']]);
            return ['ok'=>true,'replayed'=>false,'call_id'=>$call->id,'revision'=>(int)$call->revision];
        },3);
    }
    public function mutate(Request $r,string $operation,?string $id=null): array {
        $b=$r->json()->all();if(!Policy::uuid($b['action_id']??null))self::fail('A UUID action_id is required.',422);
        if($operation==='dispatch'&&\App\MapaMobile\SourceDispatchSaga::enabled())$this->prepareCoordinatedDispatch($r,$id,$b);
        $preflight=$this->staff($r);
        return DB::transaction(function()use($r,$b,$operation,$id,$preflight){
            $this->lockTenant($preflight['company']);$c=$this->staff($r,true);
            if($c['company']!==$preflight['company'])self::fail('Dispatcher access changed. Sign in again.',401);
            $scope=$c['company'].':'.$c['user']->uuid.':'.$c['binding']->role.':'.$c['binding']->revision.':'.$operation.':'.$id;
            $hash=Policy::fingerprint($scope,$b);$key=['company_uuid'=>$c['company'],'user_uuid'=>$c['user']->uuid,'action_id'=>$b['action_id']];
            $receipt=DB::table('mapa_voice_receipts')->where($key)->first();
            if($receipt){if(!hash_equals($receipt->request_hash,$hash))self::fail('action_id was already used with different details.',409);return [...json_decode($receipt->result,true,512,JSON_THROW_ON_ERROR),'replayed'=>true];}
            if($operation==='demo'){
                if($c['binding']->role==='operator')self::fail('Training call creation requires an administrator.',403);
                $result=$this->demo($c);
            }
            elseif($operation==='manual')$result=$this->manual($r,$c);
            else{
                $call=$this->call($id,$c['company'],true);
                if($c['binding']->role==='operator' && $this->supervisedPilot($call))
                    self::fail('Supervised pilot changes require an administrator.',403);
                if(!is_int($b['expected_revision']??null)||$b['expected_revision']!==(int)$call->revision)self::fail('Call changed. Refresh and review.',409);
                if(in_array($call->state,['dispatched','dismissed'],true))self::fail('Call has already been resolved.',409);
                if($call->provider==='asrhealth')self::fail('ASR-owned incidents use the original dispatch workflow. This record provides caller tracking only.',409);
                if(!$this->workflowEnabled($call))self::fail('This call workflow is disabled.',503);
                if(Schema::hasTable('mapa_dispatch_runs')&&DB::table('mapa_dispatch_runs')->where('call_id',$id)->whereIn('state',['offering','searching'])->exists())self::fail('Stop automatic offers before editing or manually dispatching this call.',409);
                if($operation==='review')$result=$this->review($r,$call,$c);
                elseif($operation==='dispatch'){
                    if($this->supervisedPilot($call))self::fail('A no-patient pilot may only use the scoped automatic offer workflow.',409);
                    $result=$this->dispatch($r,$call,$c);
                }
                elseif($operation==='dismiss'){
                    $r->validate(['reason'=>'required|string|max:1000']);
                    DB::table('mapa_voice_calls')->where('id',$id)->update(['state'=>'dismissed','dismiss_reason'=>$b['reason'],'revision'=>$call->revision+1,'updated_at'=>now()]);
                    $call=$this->call($id,$c['company']);$this->audit($call,$c['user']->uuid,'dismissed');$result=['ok'=>true,'call'=>$this->view($call)];
                }else self::fail('Unsupported voice operation.',404);
            }
            DB::table('mapa_voice_receipts')->insert([...$key,'request_hash'=>$hash,'result'=>json_encode($result,JSON_THROW_ON_ERROR),'created_at'=>now()]);
            return [...$result,'replayed'=>false];
        },3);
    }
    public function extract(Request $r,string $id): array {
        $c=$this->staff($r);$b=$r->json()->all();
        if(!Policy::uuid($b['action_id']??null)||!is_int($b['expected_revision']??null))self::fail('action_id and expected_revision are required.',422);
        $scope=$c['company'].':'.$c['user']->uuid.':'.$c['binding']->role.':'.$c['binding']->revision.':extract:'.$id;
        $hash=Policy::fingerprint($scope,$b);$key=['company_uuid'=>$c['company'],'user_uuid'=>$c['user']->uuid,'action_id'=>$b['action_id']];
        $replay=function()use($key,$hash){$receipt=DB::table('mapa_voice_receipts')->where($key)->first();if(!$receipt)return null;
            if(!hash_equals($receipt->request_hash,$hash))self::fail('action_id was already used with different details.',409);
            return [...json_decode($receipt->result,true,512,JSON_THROW_ON_ERROR),'replayed'=>true];};
        if($done=$replay())return $done;
        $call=$this->call($id,$c['company']);
        if($call->provider==='asrhealth')self::fail('ASR call details must be refreshed from the source.',409);
        if($this->supervisedPilot($call))self::fail('A no-patient pilot draft cannot be replaced by transcript extraction.',409);
        if(in_array($call->state,['dispatched','dismissed'],true)||$b['expected_revision']!==(int)$call->revision)self::fail('Call changed. Refresh and review.',409);
        if(!$this->workflowEnabled($call))self::fail('This call workflow is disabled.',503);
        // Network work occurs outside locks and cannot publish a stale model result.
        try{$draft=(new DraftExtractor)->extract($call->transcript??'');}
        catch(\RuntimeException $e){self::fail('Draft extraction is unavailable. Enter and confirm the details manually.',503);}
        $preflightCompany=$c['company'];
        return DB::transaction(function()use($r,$id,$b,$key,$hash,$draft,$replay,$scope,$preflightCompany){
            $this->lockTenant($preflightCompany);$c=$this->staff($r,true);
            $freshScope=$c['company'].':'.$c['user']->uuid.':'.$c['binding']->role.':'.$c['binding']->revision.':extract:'.$id;
            if(!hash_equals($scope,$freshScope))self::fail('Dispatcher access changed. Sign in again.',401);
            if($done=$replay())return $done;
            $call=$this->call($id,$c['company'],true);
            if($this->supervisedPilot($call))self::fail('A no-patient pilot draft cannot be replaced by transcript extraction.',409);
            if(in_array($call->state,['dispatched','dismissed'],true)||$b['expected_revision']!==(int)$call->revision)self::fail('Call changed while extracting. Refresh and review.',409);
            DB::table('mapa_voice_calls')->where('id',$id)->update(['draft'=>json_encode($draft,JSON_THROW_ON_ERROR),'state'=>'needs_review','revision'=>$call->revision+1,'updated_at'=>now()]);
            $call=$this->call($id,$c['company']);$this->audit($call,$c['user']->uuid,'draft_extracted');$result=['ok'=>true,'call'=>$this->view($call)];
            DB::table('mapa_voice_receipts')->insert([...$key,'request_hash'=>$hash,'result'=>json_encode($result,JSON_THROW_ON_ERROR),'created_at'=>now()]);
            return [...$result,'replayed'=>false];
        },3);
    }
    protected function intakeRules(): array {
        return ['caller_name'=>'nullable|string|max:200','callback_number'=>'nullable|string|max:40',
            'patient_name'=>'nullable|string|max:200','patient_age'=>'nullable|integer|min:0|max:120','patient_gender'=>'nullable|in:unknown,Male,Female',
            'incident_type'=>'nullable|in:Obstetric Emergency,Trauma,Medical,Neonatal,Referral Transfer,Other','intake_priority'=>'nullable|in:emergency,urgent,routine',
            'origin_phc_name'=>'nullable|string|max:200','origin_secretariat_name'=>'nullable|string|max:200',
            'origin_phc_id'=>'nullable|string|max:64|regex:/^[A-Za-z0-9_-]+$/','origin_secretariat_id'=>'nullable|string|max:64|regex:/^[A-Za-z0-9_-]+$/',
            'origin_village_id'=>'nullable|string|max:64|regex:/^[A-Za-z0-9_-]+$/','destination_phc_id'=>'nullable|string|max:64|regex:/^[A-Za-z0-9_-]+$/',
            'destination_facility_name'=>'nullable|string|max:200','dispatcher_notes'=>'nullable|string|max:2000',
            'reported_problem'=>'required|string|max:2000','pickup_address'=>'nullable|string|max:1000','landmark'=>'nullable|string|max:500',
            'village'=>'nullable|string|max:200','mandal'=>'nullable|string|max:200','access_directions'=>'nullable|string|max:1000',
            'patient_count'=>'nullable|integer|min:1|max:99','consciousness'=>'nullable|in:awake,not_awake,unknown',
            'breathing'=>'nullable|in:normal,not_normal,unknown','scene_safety'=>'nullable|in:safe,unsafe,unknown',
            'latitude'=>'nullable|numeric|between:-90,90','longitude'=>'nullable|numeric|between:-180,180',
            'coordinates_confirmed'=>'sometimes|boolean',
            'priority'=>'nullable|in:routine,urgent,emergency','vehicle_requirement'=>'nullable|in:any,bls,als',
            'pickup_confirmed_at_creation'=>'sometimes|boolean','supervised_pilot'=>'sometimes|boolean',
            'pilot_pickup_confirmed_by_operator'=>'sometimes|boolean'];
    }
    protected function normalizeCallback(mixed $value): string {
        try{return CallerPhone::normalize($value);}
        catch(\InvalidArgumentException $e){throw ValidationException::withMessages(['callback_number'=>CallerPhone::ERROR]);}
    }
    protected function manual(Request $r,array $c): array {
        if(!config('mapa_voice.manual_enabled'))self::fail('Manual call intake is disabled.',503);
        $v=$r->validate([...$this->intakeRules(),'demo'=>'sometimes|boolean']);
        $v=SourceIntakeDirectory::validate($v,$c['company']);
        $v['callback_number']=$this->normalizeCallback($v['callback_number']??null);
        $demo=$r->json('demo',false);
        if(!is_bool($demo))self::fail('Select live intake or DEMO training explicitly.',422);
        if($c['binding']->role==='operator' && ($demo || ($v['supervised_pilot']??false)===true))
            self::fail('Training and supervised pilot creation require an administrator.',403);
        if($demo&&!config('mapa_voice.demo_enabled'))self::fail('Training calls are disabled.',503);
        unset($v['demo']);
        $v['priority']=$v['priority']??$v['intake_priority']??'routine';
        $v['vehicle_requirement']=$v['vehicle_requirement']??'any';
        if(($v['supervised_pilot']??false)===true){
            if(!(bool)config('mapa_voice.supervised_pilot_intake_enabled'))self::fail('Supervised pilot intake is disabled.',503);
            if($demo)self::fail('A live crew drill cannot be entered as a simulated training call.',422);
            $this->checkSupervisedPilotDetails($v);
            if(($v['pickup_confirmed_at_creation']??false)!==false || ($v['coordinates_confirmed']??false)!==false
                ||($v['pilot_pickup_confirmed_by_operator']??false)!==false)
                self::fail('Create the pilot as an unreviewed draft; confirm its test point separately.',422);
            $v['mapa_operational_test']=true;
        }elseif(($v['pilot_pickup_confirmed_by_operator']??false)===true)
            self::fail('Pilot test-point confirmation is only valid for a supervised pilot.',422);
        if(($v['pickup_confirmed_at_creation']??false)===true){
            if(!isset($v['latitude'],$v['longitude'])||trim((string)($v['pickup_address']??''))==='')
                self::fail('A confirmed pickup needs its address and both coordinates.',422);
            $v['pickup_confirmed_at_creation_by']=$c['user']->uuid;
        }
        // Draft coordinates are never authoritative. Review must independently confirm them.
        $v['coordinates_confirmed']=false;
        if($demo){
            foreach(['caller_name'=>200,'reported_problem'=>2000,'patient_name'=>200] as $key=>$max){
                $value=(string)($v[$key]??'Caller');
                $v[$key]=str_starts_with($value,'DEMO ')?$value:'DEMO '.mb_substr($value,0,$max-5);
            }
        }
        $id=(string)Str::uuid();
        DB::table('mapa_voice_calls')->insert(['id'=>$id,'company_uuid'=>$c['company'],'provider'=>'manual','provider_call_id'=>$id,
            'demo'=>$demo,'state'=>'needs_review','provider_status'=>'operator_entered','revision'=>1,'last_sequence'=>0,
            'caller'=>$v['callback_number']??'','called'=>'','transcript'=>$demo?'DEMO operator training record. No actual caller or patient.':'Operator-entered call details; no audio transcription.',
            'draft'=>json_encode($v,JSON_THROW_ON_ERROR),'created_at'=>now(),'updated_at'=>now()]);
        $call=$this->call($id,$c['company']);$this->audit($call,$c['user']->uuid,'manual_created',
            ['demo'=>$demo,'supervised_pilot'=>($v['supervised_pilot']??false)===true]);
        return ['ok'=>true,'call'=>$this->view($call)];
    }
    protected function demo(array $c): array {
        if(!config('mapa_voice.demo_enabled'))self::fail('Training calls are disabled.',503);
        $id=(string)Str::uuid();$draft=['caller_name'=>'DEMO Caller','reported_problem'=>'DEMO fictional transport request for a supervised presentation.',
            'pickup_address'=>'DEMO pickup near Paderu','landmark'=>'DEMO training point'];
        DB::table('mapa_voice_calls')->insert(['id'=>$id,'company_uuid'=>$c['company'],'provider'=>'training','provider_call_id'=>$id,'demo'=>true,
            'state'=>'needs_review','provider_status'=>'ended','revision'=>1,'last_sequence'=>1,'caller'=>'','called'=>'',
            'transcript'=>'DEMO TRAINING CALL — no real patient or emergency. Please arrange a demonstration ambulance pickup near Paderu. The dispatcher must confirm the map point and receiving hospital.',
            'draft'=>json_encode($draft,JSON_THROW_ON_ERROR),'created_at'=>now(),'updated_at'=>now()]);
        // Only the explicitly provisioned, fictional voice fixture may have its
        // simulated fix refreshed. Never refresh or put real resources on duty.
        $driver=$this->native('drivers',$c['company'])->where('public_id','driver_mapa_voice_demo_01')->lockForUpdate()->first();
        $vehicle=$driver?$this->native('vehicles',$c['company'])->where('uuid',$driver->vehicle_uuid)->where('public_id','vehicle_mapa_voice_demo_01')->lockForUpdate()->first():null;
        if($driver&&$vehicle&&Policy::allowedResource(true,$driver->meta,$vehicle->meta)
            &&(Policy::meta($driver->meta)['demo_dataset']??'')==='mapa-voice-demo-v1'
            &&(Policy::meta($vehicle->meta)['demo_dataset']??'')==='mapa-voice-demo-v1'
            &&!$this->busy($c['company'],$driver->uuid,$vehicle->uuid)){
            $point=DB::raw("ST_GeomFromText('POINT(82.669 18.083)', 0)");
            $fix=['location'=>$point,'latitude'=>18.083,'longitude'=>82.669,'online'=>1,'updated_at'=>now()];
            DB::table('drivers')->where('uuid',$driver->uuid)->update($fix);
            DB::table('vehicles')->where('uuid',$vehicle->uuid)->update(['location'=>$point,'online'=>1,'updated_at'=>now()]);
            DB::table('mapa_mobile_driver_states')->updateOrInsert(['driver_uuid'=>$driver->uuid],['company_uuid'=>$c['company'],
                'recorded_at'=>(int)round(microtime(true)*1000),'created_at'=>now(),'updated_at'=>now()]);
        }
        $call=$this->call($id,$c['company']);$this->audit($call,$c['user']->uuid,'demo_created',['simulated'=>true]);
        return ['ok'=>true,'call'=>$this->view($call)];
    }
    protected function review(Request $r,object $call,array $c): array {
        $v=$r->validate([...$this->intakeRules(),'confirmed'=>'required|accepted','caller_name'=>'nullable|string|max:200',
            'reported_problem'=>'required|string|max:2000','pickup_address'=>'required|string|max:1000','landmark'=>'nullable|string|max:500',
            'latitude'=>'required|numeric|between:-90,90','longitude'=>'required|numeric|between:-180,180',
            'priority'=>'required|in:routine,urgent,emergency','vehicle_requirement'=>'required|in:any,bls,als','dropoff_id'=>'required|string|max:100']);
        $v=SourceIntakeDirectory::validate($v,$c['company']);
        $v['callback_number']=$this->normalizeCallback($v['callback_number']??null);
        if(($r->json('confirmed'))!==true)self::fail('Explicit dispatcher confirmation is required.',422);
        if($this->supervisedPilot($call)){
            if($call->provider!=='manual'||(bool)$call->demo
                ||($v['supervised_pilot']??false)!==true
                ||($v['pilot_pickup_confirmed_by_operator']??false)!==true
                ||($v['coordinates_confirmed']??false)!==false
                ||!(bool)config('mapa_voice.supervised_pilot_intake_enabled')
                ||!class_exists(AutomaticDispatch::class)||!AutomaticDispatch::available()
                ||!AutomaticDispatchScope::allowsCallId($call->id)
                ||AutomaticDispatchScope::modeForCallId($call->id)!=='pilot'
                ||count(AutomaticDispatchScope::driverUuids($call->id))!==2)
                self::fail('This no-patient drill requires the exact commissioned call and two crews; confirm its test point as the operator.',409);
            $this->checkSupervisedPilotDetails($v);
            if(trim((string)$call->caller)!=='')self::fail('The no-patient pilot must not have a caller number.',409);
            $v['coordinates_confirmed']=false;
            $v['pilot_pickup_confirmed_by_operator_uuid']=$c['user']->uuid;
            $v['mapa_operational_test']=true;
        }elseif(($v['supervised_pilot']??false)===true||($v['pilot_pickup_confirmed_by_operator']??false)===true)
            self::fail('A real call cannot use pilot test-point confirmation.',422);
        elseif($call->provider==='manual'&&$r->json('coordinates_confirmed')!==true)
            self::fail('Read back the pickup location and explicitly confirm the map pin with the caller.',422);
        unset($v['confirmed']);$v['latitude']=(float)$v['latitude'];$v['longitude']=(float)$v['longitude'];
        $p=$this->receivingPlaces($c['company'],(bool)$call->demo)->where('public_id',$v['dropoff_id'])->lockForUpdate()->first();
        if(!$p||Policy::demo($p->meta??null)!==(bool)$call->demo)self::fail('Select a receiving place in the same live or DEMO environment.',422);
        DB::table('mapa_voice_calls')->where('id',$call->id)->update(['review'=>json_encode($v,JSON_THROW_ON_ERROR),'state'=>'ready','reviewed_by'=>$c['user']->uuid,'reviewed_at'=>now(),'revision'=>$call->revision+1,'updated_at'=>now()]);
        $call=$this->call($call->id,$c['company']);$this->audit($call,$c['user']->uuid,'reviewed',
            ['priority'=>$v['priority'],'vehicle_requirement'=>$v['vehicle_requirement'],
                'supervised_pilot'=>$this->supervisedPilot($call)]);
        if($call->provider!=='asrhealth'&&class_exists(AutomaticDispatch::class))
            (new AutomaticDispatch)->startReviewed($call,$c);
        $directory=$this->directory($call);
        return ['ok'=>true,'call'=>$this->view($call),...$directory];
    }
    protected function blockingOrder(string $company,string $driver,string $vehicle): ?object {
        $query=$this->native('orders',$company)->whereNotIn('status',Policy::TERMINAL_ORDERS)->where(function($q)use($driver,$vehicle){$q->where('driver_assigned_uuid',$driver)->orWhere('vehicle_assigned_uuid',$vehicle);});
        // Mutations require a current read, not an older REPEATABLE READ snapshot.
        if(DB::transactionLevel()>0)$query->lockForUpdate();
        return $query->first(['public_id','status']);
    }
    protected function busy(string $company,string $driver,string $vehicle): bool {
        return $this->blockingOrder($company,$driver,$vehicle)!==null;
    }
    protected function sourceTripBlock(string $company,object $driver,object $vehicle): ?array {
        return (new SourceTripEligibility)->inspect($company,$driver,$vehicle);
    }
    protected function inspectEligibility(object $d,object $v,object $call,array $review): array {
        $company=$call->company_uuid;$demo=(bool)$call->demo;
        $blocked=static fn($code,$message,$extra=[])=>['candidate'=>null,'code'=>$code,'message'=>$message,...$extra];
        if(!Policy::allowedResource($demo,$d->meta??null,$v->meta??null))return $blocked('environment','Ambulance and call must use the same DEMO or live environment.');
        try{\App\MapaMobile\DispatchAuthority::assertNoResidualSourceWork($company);}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){return $blocked('source_reconciliation_required',$e->getMessage());}
        if(!\App\MapaMobile\DispatchAuthority::mapaControls()){
        foreach([$d,$v] as $resource){
            $sourceMeta=Policy::meta($resource->meta??null);
            if(isset($sourceMeta['asr_import'])||isset($sourceMeta['asr_source_id'])||isset($sourceMeta['mapa_source_id']))
                return $blocked('source_owned','ASR manages this ambulance and driver. MAPA shows their source trip and caller tracking only.');
        }
        if(Schema::hasTable('mapa_source_connections')&&DB::table('mapa_source_connections')->where('company_uuid',$company)
            ->whereIn('native_uuid',[$d->uuid,$v->uuid])->whereIn('dataset',['drivers','ambulances'])->exists())
            return $blocked('source_owned','ASR manages this ambulance and driver. Dispatch remains on ASR Health.');
        }
        // Native Vehicle normalizes active to available; retained raw legacy rows may still be active.
        if(!$d->online||!$v->online||!in_array($d->status,['available','active'],true)||!in_array($v->status,['available','active'],true))return $blocked('off_duty','Crew and ambulance must be on duty and active.');
        if((($v->driver_uuid??null)&&$v->driver_uuid!==$d->uuid)||$this->native('drivers',$company)->where('vehicle_uuid',$v->uuid)->where('uuid','<>',$d->uuid)->exists())return $blocked('assignment','Correct the crew-to-ambulance link in Resources.');
        $binding=DB::table('mapa_mobile_bindings')->where(['company_uuid'=>$company,'driver_uuid'=>$d->uuid,'user_uuid'=>$d->user_uuid,'role'=>'crew','enabled'=>true])->first();
        $user=User::withoutGlobalScopes()->where('company_uuid',$company)->whereNull('deleted_at')->where('uuid',$d->user_uuid)->where('status','active')->first();
        if(!$binding||!$user||!$this->native('company_users',$company)->where('user_uuid',$d->user_uuid)->where('status','active')->exists())return $blocked('crew_access','An active crew account and mobile binding are required.');
        if(!(new CrewEligibility)->allows($binding,$user))return $blocked('crew_access','Crew account needs an administrator to check mobile access.');
        if($order=$this->blockingOrder($company,$d->uuid,$v->uuid))return $blocked('busy','Already assigned to an unfinished call. Finish or cancel that call in Ambulance Calls before assigning another.',['order_id'=>$order->public_id,'order_status'=>$order->status]);
        if($sourceTrip=$this->sourceTripBlock($company,$d,$v))return $blocked($sourceTrip['code'],$sourceTrip['message']);
        if(Schema::hasTable('mapa_dispatch_offers')){
            $pending=DB::table('mapa_dispatch_offers')->where('company_uuid',$company)->whereIn('state',['offered','overdue'])->where('call_id','<>',$call->id)->where(fn($q)=>$q->where('driver_uuid',$d->uuid)->orWhere('vehicle_uuid',$v->uuid));
            if(DB::transactionLevel()>0)$pending->lockForUpdate();
            if($pending->first(['id']))return $blocked('offered','An automatic dispatch offer is awaiting this crew’s response.');
        }
        $meta=Policy::meta($v->meta??null);$level=$meta['mapa_ambulance_level']??null;
        if($review['vehicle_requirement']!=='any'&&$level!==$review['vehicle_requirement'])return $blocked('capability','The ambulance does not have the required '.strtoupper($review['vehicle_requirement']).' capability recorded.');
        $state=DB::table('mapa_mobile_driver_states')->where(['company_uuid'=>$company,'driver_uuid'=>$d->uuid])->first();
        $now=(int)round(microtime(true)*1000);
        if(!Policy::coordinates($d->gps_latitude,$d->gps_longitude))return $blocked('gps_missing','No valid crew location. Open the crew app and send a GPS update.');
        if(!Policy::fresh($state?->recorded_at,$now,(int)config('mapa_voice.max_gps_age_seconds',180)))return $blocked('gps_stale','Crew GPS is missing or older than 3 minutes. Open the crew app, go on duty and send a fresh location.');
        return ['candidate'=>['id'=>$d->public_id,'vehicle_id'=>$v->public_id,'name'=>$user->name,'vehicle_name'=>$v->name,
            'distance_km'=>round(Policy::distance($review['latitude'],$review['longitude'],(float)$d->gps_latitude,(float)$d->gps_longitude),2),
            'distance_basis'=>'straight_line_not_road_eta','simulated'=>$demo,'fix_recorded_at'=>(int)$state->recorded_at]];
    }
    protected function eligible(object $d,object $v,object $call,array $review): ?array {
        return $this->inspectEligibility($d,$v,$call,$review)['candidate'];
    }
    protected function directory(object $call,string $query=''): array {
        return (new AmbulanceDirectory)->forCall($call,fn($d,$v,$call,$review)=>$this->inspectEligibility($d,$v,$call,$review),$query);
    }
    protected function unavailable(object $call): array {return $this->directory($call)['unavailable'];}
    protected function candidates(object $call): array {return $this->directory($call)['candidates'];}
    protected function sourceDispatchSaga(): \App\MapaMobile\SourceDispatchSaga {return new \App\MapaMobile\SourceDispatchSaga;}
    /** Persist ownership intent and perform HTTP before the native dispatch transaction. */
    protected function prepareCoordinatedDispatch(Request $r,?string $id,array $body):void {
        $c=$this->staff($r);if(!Policy::uuid($id))self::fail('Native call identity is required.',422);
        // Existing immutable native receipt is verified again in mutate; no new remote write on replay.
        if(DB::table('mapa_voice_receipts')->where(['company_uuid'=>$c['company'],'user_uuid'=>$c['user']->uuid,'action_id'=>$body['action_id']])->exists())return;
        $call=$this->call($id,$c['company']);
        if($this->supervisedPilot($call))self::fail('A no-patient pilot may only use the scoped automatic offer workflow.',409);
        if($call->provider==='asrhealth')self::fail('ASR-owned incidents cannot create a duplicate native dispatch.',409);
        if($call->state!=='ready'||!$call->review||($body['confirmed']??false)!==true||($body['expected_revision']??null)!==(int)$call->revision||!$this->workflowEnabled($call))self::fail('Review the current call and confirm dispatch.',409);
        $v=$r->validate(['driver_id'=>'required|string|max:100']);$review=Policy::meta($call->review);
        $driver=$this->native('drivers',$c['company'])->select('drivers.*',DB::raw('ST_Y(location) AS gps_latitude'),DB::raw('ST_X(location) AS gps_longitude'))->where('public_id',$v['driver_id'])->first();
        $vehicle=$driver?$this->native('vehicles',$c['company'])->where('uuid',$driver->vehicle_uuid)->first():null;
        if(!$driver||!$vehicle||!$this->eligible($driver,$vehicle,$call,$review))self::fail('Selected crew or ambulance is unavailable.',409);
        $saga=$this->sourceDispatchSaga();$saga->prepare($c,$call,$driver,$vehicle,$body);$saga->reserve($c['company'],$call->id);
    }
    protected function dispatch(Request $r,object $call,array $c): array {
        if($call->state!=='ready'||!$call->review||$r->json('confirmed')!==true)self::fail('Review the call and explicitly confirm dispatch.',422);
        $v=$r->validate(['driver_id'=>'required|string|max:100']);$review=Policy::meta($call->review);
        $driver=$this->native('drivers',$c['company'])->select('drivers.*',DB::raw('ST_Y(location) AS gps_latitude'),DB::raw('ST_X(location) AS gps_longitude'))->where('public_id',$v['driver_id'])->lockForUpdate()->first();
        $vehicle=$driver?$this->native('vehicles',$c['company'])->where('uuid',$driver->vehicle_uuid)->lockForUpdate()->first():null;
        if(!$driver||!$vehicle)self::fail('Selected crew or ambulance is unavailable.',409);
        DB::table('mapa_mobile_bindings')->where(['company_uuid'=>$c['company'],'driver_uuid'=>$driver->uuid])->lockForUpdate()->get();
        DB::table('mapa_mobile_driver_states')->where('driver_uuid',$driver->uuid)->lockForUpdate()->first();
        if(!$this->eligible($driver,$vehicle,$call,$review))self::fail('Ambulance eligibility changed. Refresh and review.',409);
        $place=$this->receivingPlaces($c['company'],(bool)$call->demo)->where('public_id',$review['dropoff_id'])->lockForUpdate()->first();
        if(!$place||Policy::demo($place->meta??null)!==(bool)$call->demo)self::fail('Receiving place changed.',409);
        $sourceJourney=\App\MapaMobile\SourceDispatchSaga::enabled()?$this->sourceDispatchSaga()->assertReserved($c,$call,$driver,$vehicle):null;
        $order=$this->createOrder($call,$c,$review,$driver,$vehicle,$place);
        if($sourceJourney)$this->sourceDispatchSaga()->attach($sourceJourney,$order);
        DB::table('mapa_voice_calls')->where('id',$call->id)->whereNull('order_uuid')->update(['order_uuid'=>$order,'state'=>'dispatched','revision'=>$call->revision+1,'updated_at'=>now()]);
        $call=$this->call($call->id,$c['company']);$this->audit($call,$c['user']->uuid,'dispatched',
            ['order_uuid'=>$order,'driver_uuid'=>$driver->uuid,'vehicle_uuid'=>$vehicle->uuid,
                'supervised_pilot'=>$this->supervisedPilot($call),'notifications_sent'=>false]);
        return ['ok'=>true,'call'=>$this->view($call),'order_id'=>$this->view($call)['order_id']];
    }
    protected function createOrder(object $call,array $c,array $review,object $driver,object $vehicle,object $dropoff): string {
        $config=$this->native('order_configs',$c['company'])->where('key','transport')->lockForUpdate()->first();
        $flow=Policy::meta($config?->flow);
        if(!$config||!isset($flow['dispatched'])||($flow['dispatched']['complete']??true))self::fail('A reviewed native transport workflow is required.',503);
        $demo=(bool)$call->demo;$pilot=$this->supervisedPilot($call);
        $prefix=$demo?'DEMO ':($pilot?'MAPA PILOT — NO PATIENT · ':'');
        $now=now();$order=(string)Str::uuid();$pickup=(string)Str::uuid();$payload=(string)Str::uuid();$tracking=(string)Str::uuid();$status=(string)Str::uuid();
        $meta=['mapa_voice_call_id'=>$call->id,'mapa_voice_source'=>'dispatcher_confirmed',
            'mapa_demo'=>$demo,'simulated'=>$demo,'mapa_operational_test'=>$pilot,'supervised_pilot'=>$pilot,
            'asr_intake'=>array_intersect_key($review,array_flip(['patient_name','patient_age','patient_gender','incident_type','intake_priority','origin_phc_name','origin_secretariat_name','destination_facility_name','dispatcher_notes','origin_phc_id','origin_secretariat_id','origin_village_id','destination_phc_id'])),
            'mapa_dispatch_review'=>['reviewed_by'=>$call->reviewed_by,'reviewed_at'=>$call->reviewed_at,'dispatched_by'=>$c['user']->uuid,
                'callback_number'=>$review['callback_number']??null,'priority'=>$review['priority'],'vehicle_requirement'=>$review['vehicle_requirement'],
                'reported_problem'=>$review['reported_problem'],'landmark'=>$review['landmark']??'',
                'village'=>$review['village']??'','mandal'=>$review['mandal']??'','access_directions'=>$review['access_directions']??'',
                'patient_count'=>$review['patient_count']??null,'coordinates_confirmed'=>$review['coordinates_confirmed']??false,
                'pilot_pickup_confirmed_by_operator'=>$review['pilot_pickup_confirmed_by_operator']??false,
                'consciousness'=>$pilot?null:($review['consciousness']??'unknown'),
                'breathing'=>$pilot?null:($review['breathing']??'unknown'),
                'scene_safety'=>$pilot?null:($review['scene_safety']??'unknown')],
            'notice'=>$demo?'DEMO fictional training dispatch. No real person or emergency.':($pilot
                ?'SUPERVISED MAPA PILOT — NO PATIENT. Real crew drill at an operator-confirmed test point; no caller was consulted.'
                :'Dispatcher-confirmed voice intake. No automated clinical triage.')];
        $base=['company_uuid'=>$c['company'],'_key'=>'mapa-voice-v1','meta'=>json_encode($meta,JSON_THROW_ON_ERROR),'created_at'=>$now,'updated_at'=>$now];
        $baseFor=static function(string $table)use($base){$b=$base;if(!Schema::hasColumn($table,'meta'))unset($b['meta']);return $b;};
        $public=static fn($kind,$uuid)=>$kind.'_mapa_voice_'.str_replace('-','',$uuid);
        $point=DB::raw("ST_GeomFromText('POINT(".(float)$review['longitude'].' '.(float)$review['latitude'].")', 0)");
        DB::table('places')->insert([...$baseFor('places'),'uuid'=>$pickup,'public_id'=>$public('place',$pickup),'name'=>$prefix.'Call pickup','street1'=>$review['pickup_address'],
            'country'=>'IN','type'=>'place','location'=>$point,'latitude'=>$review['latitude'],'longitude'=>$review['longitude']]);
        DB::table('payloads')->insert([...$baseFor('payloads'),'uuid'=>$payload,'public_id'=>$public('payload',$payload),'pickup_uuid'=>$pickup,'dropoff_uuid'=>$dropoff->uuid,'type'=>'transport']);
        // Native circular FK requires number first, status next, pointer last.
        DB::table('tracking_numbers')->insert([...$baseFor('tracking_numbers'),'uuid'=>$tracking,'public_id'=>$public('tracking',$tracking),'tracking_number'=>($demo?'DEMO-':'').'MAPA-'.strtoupper(substr(str_replace('-','',$tracking),0,18)),
            'owner_uuid'=>$order,'owner_type'=>(new Order)->getMorphClass(),'region'=>'IN','status_uuid'=>null]);
        DB::table('tracking_statuses')->insert([...$baseFor('tracking_statuses'),'uuid'=>$status,'public_id'=>$public('status',$status),'tracking_number_uuid'=>$tracking,'status'=>$prefix.'Ambulance dispatched',
            'details'=>$demo?'Fictional training dispatch confirmed by MAPA dispatcher.':($pilot
                ?'SUPERVISED MAPA PILOT — NO PATIENT; crew accepted an operator-confirmed test point.'
                :'Voice intake reviewed and dispatch confirmed by MAPA dispatcher.'),
            'code'=>'dispatched','complete'=>0,'country'=>'IN','location'=>$point,'latitude'=>$review['latitude'],'longitude'=>$review['longitude']]);
        DB::table('tracking_numbers')->where('uuid',$tracking)->update(['status_uuid'=>$status]);
        DB::table('orders')->insert([...$baseFor('orders'),'uuid'=>$order,'public_id'=>$public('order',$order),'internal_id'=>($demo?'DEMO-':'').'VOICE-'.strtoupper(substr(str_replace('-','',$call->id),0,16)),
            'created_by_uuid'=>$c['user']->uuid,'order_config_uuid'=>$config->uuid,'payload_uuid'=>$payload,'tracking_number_uuid'=>$tracking,
            'driver_assigned_uuid'=>$driver->uuid,'vehicle_assigned_uuid'=>$vehicle->uuid,'type'=>'transport','status'=>'dispatched','dispatched'=>1,
            'dispatched_at'=>$now,'started'=>0,'pod_required'=>0,'notes'=>$prefix.$review['reported_problem']."\nLandmark: ".($review['landmark']??'')]);
        // Deliberately no native saved/dispatch events: current upstream handlers
        // can send notifications inside retrying transactions. The dedicated
        // MAPA alert hook below queues only after commit.
        $native=Order::withoutGlobalScopes()->where('company_uuid',$c['company'])->where('uuid',$order)->first();
        if(!$native||$native->payload?->pickup?->uuid!==$pickup||$native->payload?->dropoff?->uuid!==$dropoff->uuid
            ||$native->trackingNumber?->uuid!==$tracking||$native->orderConfig?->uuid!==$config->uuid
            ||!$native->trackingStatuses()->where('uuid',$status)->exists())self::fail('Native dispatch references did not validate.',503);
        try{$this->notifyCrewAssigned($native);}
        catch(\Throwable $e){
            try{\Illuminate\Support\Facades\Log::warning('MAPA crew alert could not be scheduled; control must verify crew acknowledgement.');}
            catch(\Throwable $loggingError){/* An unavailable optional logger must not undo a valid dispatch either. */}
        }
        return $order;
    }
    protected function notifyCrewAssigned(Order $native): void {
        // Query-builder insertion above bypasses Eloquent saved observers.
        // This observer excludes training orders and defers delivery until the
        // outer dispatch transaction commits, including its idempotency receipt.
        (new \App\MapaMobile\PushAssignmentObserver)->assigned($native);
    }
}

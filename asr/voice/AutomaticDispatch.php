<?php
namespace App\MapaVoice;
use App\MapaMobile\MobileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Native MAPA offers only. Never reserves or mutates ASR records. */
class AutomaticDispatch extends VoiceService
{
    protected function pilotCall(mixed $id): bool {return AutomaticDispatchScope::allowsCallId($id);}
    protected function pilotDriver(mixed $uuid,mixed $callId=null): bool {return AutomaticDispatchScope::allowsDriverUuid($uuid,$callId);}
    protected function pilotDriverUuids(mixed $callId=null): array {return AutomaticDispatchScope::driverUuids($callId);}
    protected function pilotOfferHistoryInScope(string $callId): bool {
        if (AutomaticDispatchScope::modeForCallId($callId) === 'all_live') return true;
        return !DB::table('mapa_dispatch_offers')->where('call_id',$callId)
            ->whereNotIn('driver_uuid',$this->pilotDriverUuids($callId))->exists();
    }
    public static function available(): bool {
        return config('mapa_voice.enabled',false)&&config('mapa_voice.automatic_dispatch_enabled',false)
            && AutomaticDispatchScope::configured()!==null
            && Schema::hasTable('mapa_dispatch_runs') && Schema::hasTable('mapa_dispatch_offers');
    }
    private function gate(): void {
        if(!static::available())self::fail('Automatic dispatch is not commissioned.',503);
        if(\App\MapaMobile\SourceDispatchSaga::enabled())self::fail('Automatic dispatch cannot use source write coordination.',409);
    }
    private function workerFresh(string $company): bool {
        if((AutomaticDispatchScope::configured()['mode']??null)!=='all_live')return true;
        $row=DB::table('mapa_dispatch_settings')->where('company_uuid',$company)->first(['last_tick_at']);
        if(!$row?->last_tick_at)return false;
        $last=\Carbon\Carbon::parse($row->last_tick_at,'UTC');
        return $last->greaterThan(now()->subSeconds(35))&&$last->lessThanOrEqualTo(now()->addSeconds(5));
    }
    /** The reviewed pickup and destination are the authorization to begin native MAPA offers. */
    public function startReviewed(object $call,array $staff): void {
        if(!static::available()||!$this->pilotCall($call->id)
            ||$call->provider==='asrhealth'||$call->state!=='ready'||!$call->review||!$this->workflowEnabled($call))return;
        // Review holds the tenant lock. A stopped or exhausted run needs an explicit
        // operator retry; editing the call must never silently revive old offers.
        if(DB::table('mapa_dispatch_runs')->where('call_id',$call->id)->exists())return;
        $reason=\App\MapaMobile\SourceDispatchSaga::enabled()?'source_coordination_enabled':
            (!$this->workerFresh($staff['company'])?'worker_stale':null);
        DB::table('mapa_dispatch_runs')->insert(['call_id'=>$call->id,'company_uuid'=>$staff['company'],
            'operator_uuid'=>$staff['user']->uuid,'binding_id'=>$staff['binding']->id,
            'binding_revision'=>$staff['binding']->revision,'call_revision'=>$call->revision,
            'state'=>$reason?'needs_operator':'searching','reason'=>$reason,'created_at'=>now(),'updated_at'=>now()]);
        $this->audit($call,$staff['user']->uuid,$reason?'automatic_escalated':'automatic_started_after_review',
            ['mode'=>'pickup_radius','max_offers'=>AutomaticDispatchPolicy::MAX_OFFERS,...($reason?['reason'=>$reason,'trigger'=>'review']:[])]);
        // The scheduler advances this durable run after commit. Road routing must
        // not hold the call-review transaction or make a successful review look
        // failed because a routing service is temporarily unavailable.
    }
    public function control(Request $r,string $id,string $operation): array {
        if($operation==='start'){
            $this->gate();
            if(!$this->workerFresh((string)config('mapa.company_uuid')))
                self::fail('The automatic dispatch worker is stale. Contact control before retrying.',503);
        }
        elseif(!Schema::hasTable('mapa_dispatch_runs'))self::fail('Automatic dispatch is unavailable.',503);
        $r->validate(['action_id'=>'required|uuid','expected_revision'=>'required|integer|min:1','confirmed'=>'required|accepted']);
        if($r->json('confirmed')!==true)self::fail('Explicit dispatch confirmation is required.',422);
        // Authenticate before opening a transaction, then acquire the tenant lock before
        // any consistent reads. MySQL REPEATABLE READ must not retain a pre-lock snapshot.
        $tenant=$this->staff($r)['company'];
        return DB::transaction(function()use($r,$id,$operation,$tenant){
            $this->lockTenant($tenant);$c=$this->staff($r,true);$call=$this->call($id,$c['company'],true);
            $body=$r->json()->all();$key=['company_uuid'=>$c['company'],'user_uuid'=>$c['user']->uuid,'action_id'=>$body['action_id']];
            $hash=Policy::fingerprint('automatic:'.$operation.':'.$id.':'.$c['binding']->revision,$body);
            if($receipt=DB::table('mapa_voice_receipts')->where($key)->first()){
                if(!hash_equals($receipt->request_hash,$hash))self::fail('Action was already used.',409);
                return [...Policy::meta($receipt->result),'replayed'=>true];
            }
            if($call->provider==='asrhealth')self::fail('ASR owns this dispatch. MAPA provides tracking only.',409);
            if($operation==='start'&&!$this->pilotCall($call->id))self::fail('This call is outside automatic dispatch scope.',409);
            if((int)$call->revision!==$r->json('expected_revision'))self::fail('Review the current call first.',409);
            if($operation==='start'&&($call->state!=='ready'||!$call->review||!$this->workflowEnabled($call)))self::fail('Review the current call first.',409);
            $run=DB::table('mapa_dispatch_runs')->where('call_id',$id)->first();
            if($operation==='start'){
                if($run&&!in_array($run->state,['stopped','needs_operator'],true))self::fail('Automatic dispatch is active or already accepted; refresh its status.',409);
                if(DB::table('mapa_dispatch_offers')->where('call_id',$id)->count()>=AutomaticDispatchPolicy::MAX_OFFERS)
                    self::fail('Five crews have already been offered this call. Use manual dispatch or contact control.',409);
                $values=['company_uuid'=>$c['company'],'operator_uuid'=>$c['user']->uuid,
                    'binding_id'=>$c['binding']->id,'binding_revision'=>$c['binding']->revision,'call_revision'=>$call->revision,
                    'state'=>'searching','reason'=>null,'updated_at'=>now()];
                if($run){
                    // Explicitly confirmed retry after resource exhaustion/stop. Keep immutable
                    // offer history so a declined or timed-out ambulance is never offered twice.
                    DB::table('mapa_dispatch_runs')->where('call_id',$id)->update($values);
                    $this->audit($call,$c['user']->uuid,'automatic_resumed',['previous_state'=>$run->state,'previous_reason'=>$run->reason]);
                }else DB::table('mapa_dispatch_runs')->insert(['call_id'=>$id,...$values,'created_at'=>now()]);
                $this->advance($call);
            }elseif($operation==='stop'){
                if(!$run)self::fail('No automatic dispatch exists.',404);
                $this->finish($id,'stopped','operator_stopped');
            }else self::fail('Unsupported action.',404);
            $this->audit($call,$c['user']->uuid,'automatic_'.$operation);
            $result=['ok'=>true,'call'=>$this->view($call),'automatic_dispatch'=>$this->status($id,$c['company'])];
            DB::table('mapa_voice_receipts')->insert([...$key,'request_hash'=>$hash,'result'=>json_encode($result,JSON_THROW_ON_ERROR),'created_at'=>now()]);
            return $result;
        },3);
    }
    public function status(string $id,string $company): ?array {
        if(!Schema::hasTable('mapa_dispatch_runs'))return null;
        $run=DB::table('mapa_dispatch_runs')->where(['call_id'=>$id,'company_uuid'=>$company])->first();
        if(!$run)return null;
        $call=DB::table('mapa_voice_calls')->where(['id'=>$id,'company_uuid'=>$company])->first();
        $actionable=$run->state==='offering'
            &&AutomaticDispatchPolicy::workerCurrent(\Carbon\Carbon::parse($run->updated_at,'UTC')->getTimestamp(),now()->getTimestamp())
            &&$call&&$this->pilotCall($id)&&$this->pilotOfferHistoryInScope($id)
            &&$call->provider!=='asrhealth'&&$call->state==='ready'
            &&(int)$call->revision===(int)$run->call_revision&&$this->workflowEnabled($call)&&static::available();
        $declines=[];$offered=[];
        if(Schema::hasTable('mapa_voice_audit'))foreach(DB::table('mapa_voice_audit')->where(['call_id'=>$id,'company_uuid'=>$company])->whereIn('action',['automatic_decline','automatic_offer'])->get(['action','details']) as $entry){
            $details=Policy::meta($entry->details);if(!isset($details['offer_id']))continue;
            if($entry->action==='automatic_decline')$declines[$details['offer_id']]=$details;
            else $offered[$details['offer_id']]=$details;
        }
        $offers=DB::table('mapa_dispatch_offers')->where(['call_id'=>$id,'company_uuid'=>$company])
            ->orderBy('created_at')->orderBy('id')->get()->map(function($o)use($company,$actionable,$declines,$offered){
                $d=$this->native('drivers',$company)->where('uuid',$o->driver_uuid)->first();
                $v=$this->native('vehicles',$company)->where('uuid',$o->vehicle_uuid)->first();
                $u=$d?$this->native('users',$company)->where('uuid',$d->user_uuid)->first():null;
                $created=\Carbon\Carbon::parse($o->created_at,'UTC');$expires=\Carbon\Carbon::parse($o->expires_at,'UTC');
                $responded=$o->responded_at?\Carbon\Carbon::parse($o->responded_at,'UTC'):null;
                $state=$o->state==='offered'&&$expires->getTimestamp()<=now()->getTimestamp()?'overdue':$o->state;
                return ['id'=>$o->id,'state'=>$state,'can_respond'=>$actionable
                        &&AutomaticDispatchPolicy::accepts($o->state,$expires->getTimestamp(),now()->getTimestamp())
                        &&$this->pilotDriver($o->driver_uuid,$o->call_id)
                        &&in_array($state,['offered','overdue'],true),'driver_phone'=>$u->phone??null,'driver_name'=>$u?->name??'Crew unavailable','vehicle_registration'=>$v->plate_number??$v->public_id??'Ambulance unavailable',
                    'road_seconds'=>(int)$o->road_seconds,'distance_m'=>$offered[$o->id]['distance_m']??null,'radius_band_m'=>$offered[$o->id]['radius_band_m']??null,
                    'expires_at'=>\Carbon\Carbon::parse($o->expires_at,'UTC')->toIso8601String(),
                    'created_at'=>$created->toIso8601String(),'responded_at'=>$responded?->toIso8601String(),
                    'decline_reason'=>$declines[$o->id]['decline_reason']??null,'decline_detail'=>$declines[$o->id]['decline_detail']??null,
                    'timeout_seconds'=>max(0,$expires->getTimestamp()-$created->getTimestamp()),
                    ...AutomaticDispatchPolicy::response($state,$created->getTimestamp(),$expires->getTimestamp(),$responded?->getTimestamp(),now()->getTimestamp())];
            })->all();
        $alerts=[];foreach($offers as $offer){
            if($offer['no_response'])$alerts[]=['offer_id'=>$offer['id'],'kind'=>'no_response','message'=>$offer['state']==='overdue'?'Crew has not responded. Another eligible ambulance is considered; the first valid acceptance wins.':'This historical offer expired and cannot assign the trip.'];
            elseif($offer['state']==='declined')$alerts[]=['offer_id'=>$offer['id'],'kind'=>'declined','message'=>'Crew declined. The next eligible ambulance is considered automatically.'];
        }
        $lastWorker=\Carbon\Carbon::parse($run->updated_at,'UTC');
        $workerStale=in_array($run->state,['searching','offering'],true)
            &&!AutomaticDispatchPolicy::workerCurrent($lastWorker->getTimestamp(),now()->getTimestamp());
        if($workerStale)$alerts[]=['kind'=>'worker_stale','message'=>'Automatic dispatch worker has not updated this call for over 90 seconds. Contact control and check the scheduler.'];
        return ['state'=>$run->state,'reason'=>$run->reason,'offers'=>$offers,'alerts'=>$alerts,'offer_count'=>count($offers),
            'max_offers'=>AutomaticDispatchPolicy::MAX_OFFERS,'search_mode'=>'pickup_radius_then_road_time',
            'worker_last_seen_at'=>$lastWorker->toIso8601String(),'worker_stale'=>$workerStale,
            'outstanding_count'=>count(array_filter($offers,fn($o)=>in_array($o['state'],['offered','overdue'],true))),
            'overdue_count'=>count(array_filter($offers,fn($o)=>$o['state']==='overdue')),'cascade_active'=>$run->state==='offering','first_acceptance_wins'=>true,'replacement_policy'=>'next_unattempted_eligible_ambulance',
            'replacement_suggestions'=>[],'replacement_routing_status'=>in_array($run->state,['searching','offering','needs_operator'],true)?'refresh_selected_call':null];
    }
    private function finish(string $id,string $state,string $reason): void {
        DB::table('mapa_dispatch_offers')->where('call_id',$id)->whereIn('state',['offered','overdue'])->update(['state'=>'cancelled','responded_at'=>now(),'updated_at'=>now()]);
        DB::table('mapa_dispatch_runs')->where('call_id',$id)->update(['state'=>$state,'reason'=>$reason,'updated_at'=>now()]);
    }
    private function advance(object $call): void {
        if(!$this->pilotCall($call->id)||!$this->pilotOfferHistoryInScope($call->id)){
            $this->finish($call->id,'needs_operator','pilot_scope_changed');return;
        }
        // A late decline must not create parallel fresh offers when another unit is responding.
        if(DB::table('mapa_dispatch_offers')->where('call_id',$call->id)->where('state','offered')->where('expires_at','>',now())->lockForUpdate()->first(['id']))return;
        if(DB::table('mapa_dispatch_offers')->where('call_id',$call->id)->count()>=AutomaticDispatchPolicy::MAX_OFFERS){
            $this->finish($call->id,'needs_operator','top_five_exhausted');
            $this->audit($call,null,'automatic_escalated',['reason'=>'top_five_exhausted']);
            return;
        }
        $recommendations=$this->recommendations($call,true);$ranked=$recommendations['candidates'];
        if(!$ranked){
            $reason=$recommendations['routing_status']==='no_eligible_crew'?'no_eligible_crew':'road_routing_unavailable';
            $this->finish($call->id,'needs_operator',$reason);
            $this->audit($call,null,'automatic_escalated',['reason'=>$reason]);
            return;
        }
        $best=$ranked[0];$d=$this->native('drivers',$call->company_uuid)->where('public_id',$best['id'])->first();
        if(!$d||!$this->pilotDriver($d->uuid,$call->id)){
            $this->finish($call->id,'needs_operator','pilot_scope_changed');return;
        }
        $id=(string)Str::uuid();$seconds=max(30,min(300,(int)config('mapa_voice.automatic_offer_seconds',120)));
        DB::table('mapa_dispatch_offers')->insert(['id'=>$id,'company_uuid'=>$call->company_uuid,'call_id'=>$call->id,
            'driver_uuid'=>$d->uuid,'vehicle_uuid'=>$d->vehicle_uuid,'state'=>'offered','revision'=>1,
            'road_seconds'=>$best['road_seconds'],'expires_at'=>now()->addSeconds($seconds),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('mapa_dispatch_runs')->where('call_id',$call->id)->update(['state'=>'offering','reason'=>null,'updated_at'=>now()]);
        $this->audit($call,null,'automatic_offer',['offer_id'=>$id,'driver_uuid'=>$d->uuid,'road_seconds'=>$best['road_seconds'],
            'distance_m'=>(int)round($best['distance_km']*1000),'radius_band_m'=>$best['radius_band_m']]);
        // Polling remains authoritative. Push is an optional, privacy-safe wake-up
        // hint, queued only after the offer transaction commits.
        try {
            if((new \App\MapaMobile\PushService)->available()){
                $job=new DeliverOfferPush($id,$call->company_uuid,hash('sha256','offer:'.$id),time());
                DB::afterCommit(function()use($job){try{\Illuminate\Support\Facades\Bus::dispatch($job);}
                    catch(\Throwable $e){\Illuminate\Support\Facades\Log::warning('MAPA offer push could not be queued; crew polling remains active.');}});
            }
        }catch(\Throwable $e){try{\Illuminate\Support\Facades\Log::warning('MAPA offer push is unavailable; crew polling remains active.');}catch(\Throwable $ignored){}}
    }
    public function recommendations(object $call,bool $remainingOnly=false): array {
        if(!$this->pilotCall($call->id))return ['candidates'=>[],'ranked'=>[],
            'unavailable'=>[],'routing_status'=>'pilot_scope','ranking_basis'=>'pickup_radius_then_private_road_travel_time'];
        $history=$remainingOnly?DB::table('mapa_dispatch_offers')->where('call_id',$call->id)->get(['driver_uuid','vehicle_uuid']):collect();
        $attempted=$history->pluck('driver_uuid')->all();$attemptedVehicles=$history->pluck('vehicle_uuid')->all();
        $allLive=AutomaticDispatchScope::modeForCallId($call->id)==='all_live';
        $pilotIds=$allLive?[]:$this->native('drivers',$call->company_uuid)->whereIn('uuid',$this->pilotDriverUuids($call->id))->pluck('public_id')->all();
        $directory=['candidates'=>array_values(array_filter($this->candidates($call),
            static fn($candidate)=>$allLive||in_array($candidate['id']??null,$pilotIds,true))),'unavailable'=>[]];
        return $this->rankRecommendations($call,$directory,$attempted,$attemptedVehicles,true);
    }
    /** Called only after the voice service authenticates staff and scopes the native directory. */
    public function recommendationsForCall(object $call,array $directory): array {
        return $this->rankRecommendations($call,$directory,[]);
    }
    private function rankRecommendations(object $call,array $directory,array $attempted,array $attemptedVehicles=[],bool $radiusFirst=false): array {
        if($call->provider==='asrhealth')return [...$directory,'candidates'=>[],'ranked'=>[],'routing_status'=>'tracking_only','ranking_basis'=>'private_road_travel_time'];
        $historical=[];
        $result=(new RecommendationService)->rank($directory,Policy::meta($call->review),function($candidate)use($call,$attempted,$attemptedVehicles,$radiusFirst,&$historical){
            $query=$this->native('drivers',$call->company_uuid)->where('public_id',$candidate['id']);
            if(Schema::hasColumn('drivers','location'))$query->select('drivers.*')->selectRaw('ST_Y(location) AS gps_latitude, ST_X(location) AS gps_longitude');
            $d=$query->first();$v=$d?$this->native('vehicles',$call->company_uuid)->where('uuid',$d->vehicle_uuid)->first():null;
            if(!$d||!$v||(($radiusFirst||AutomaticDispatchScope::modeForCallId($call->id)==='pilot')
                &&!$this->pilotDriver($d->uuid,$call->id)))return null;
            if(in_array($d->uuid,$attempted,true)||in_array($v->uuid,$attemptedVehicles,true))$historical[$candidate['id']]=true;
            $phoneLat=property_exists($d,'gps_latitude')?$d->gps_latitude:$d->latitude;
            $phoneLon=property_exists($d,'gps_longitude')?$d->gps_longitude:$d->longitude;
            $phone=['latitude'=>$phoneLat,'longitude'=>$phoneLon,'recorded_at'=>$candidate['fix_recorded_at']??null,'source'=>'crew_phone'];
            $position=['latitude'=>$phoneLat,'longitude'=>$phoneLon,'recorded_at'=>$candidate['fix_recorded_at']??null,
                'source'=>'driver_phone_gps','crew_phone_location'=>$phone,'ambulance_gps_location'=>null,
                'source_owned'=>$this->sharedWithSource($d,$v),
                'pending_offer'=>in_array($d->uuid,$attempted,true)||in_array($v->uuid,$attemptedVehicles,true)||$this->pendingOffer($call->company_uuid,$d->uuid,$v->uuid)];
            if($radiusFirst&&class_exists(\App\MapaMobile\ConnectedTrackingService::class)){
                $locations=(new \App\MapaMobile\ConnectedTrackingService)->vehicleLocationSources($call->company_uuid,$v,$d->uuid);
                $hardware=$locations['ambulance_gps']??null;
                if(is_array($hardware)&&($hardware['stale']??true)===false
                    &&Policy::coordinates($hardware['latitude']??null,$hardware['longitude']??null)){
                    $position['ambulance_gps_location']=$hardware;
                    if(Policy::coordinates($phoneLat,$phoneLon)){
                        $separation=Policy::distance((float)$phoneLat,(float)$phoneLon,(float)$hardware['latitude'],(float)$hardware['longitude']);
                        $position['location_conflict']=$separation>0.5;
                    }
                    $position['latitude']=$hardware['latitude'];$position['longitude']=$hardware['longitude'];
                    $position['recorded_at']=$hardware['recorded_at'];$position['source']='hardware_gps';
                }
            }
            return $position;
        },function($from,$to)use($call){
            // Private coordinates are hashed into a tenant-scoped key, never written to logs.
            // A changed fix, pickup or review produces a new key; eligibility is still checked every time.
            if(!app()->bound('cache'))return $this->roadSeconds($from,$to);
            $key='mapa:road-recommendation:'.hash('sha256',json_encode([$call->company_uuid,$call->id,$call->revision,$from,$to],JSON_THROW_ON_ERROR));
            $cached=app('cache')->store()->remember($key,15,fn()=>['seconds'=>$this->roadSeconds($from,$to)]);
            return $cached['seconds']??null;
        },$radiusFirst);
        foreach($result['unavailable'] as &$row)if(isset($historical[$row['id']??''])){
            $row['code']=$row['reason_code']='already_attempted';
            $row['message']=$row['reason']='This driver or ambulance already received an offer for this call. Automatic replacement considers another ambulance.';
        }unset($row);
        return $result;
    }
    protected function roadSeconds(array $from,array $to): ?int {
        $route=\App\MapaMobile\PrivateRoadRoute::between($from,$to);
        return $route===null?null:(int)ceil($route['duration_s']);
    }
    private function pendingOffer(string $company,string $driver,string $vehicle): bool {
        if(!Schema::hasTable('mapa_dispatch_offers'))return false;
        $query=DB::table('mapa_dispatch_offers')->where('company_uuid',$company)->whereIn('state',['offered','overdue'])
            ->where(fn($q)=>$q->where('driver_uuid',$driver)->orWhere('vehicle_uuid',$vehicle));
        if(DB::transactionLevel()>0)$query->lockForUpdate();
        return $query->first(['id'])!==null;
    }
    public function offers(Request $r): array {
        $this->gate();$c=(new MobileService)->context($r);
        if($c['binding']->role!=='crew'||!$c['driver']||!$c['vehicle'])self::fail('Verified crew is required.');
        return ['offers'=>$this->offersForContext($c),'server_time'=>now()->getTimestamp()*1000];
    }
    public function offersForContext(array $c): array {
        if(!static::available()||$c['binding']->role!=='crew'||!$c['driver']||!$c['vehicle']
            ||!$this->pilotDriver($c['driver']->uuid))return [];
        $offers=DB::table('mapa_dispatch_offers')->where(['company_uuid'=>$c['company'],'driver_uuid'=>$c['driver']->uuid,'vehicle_uuid'=>$c['vehicle']->uuid])->whereIn('state',['offered','overdue'])->get();
        return $offers->filter(function($o)use($c){if(!$this->pilotCall($o->call_id)||!$this->pilotDriver($o->driver_uuid,$o->call_id)||!$this->pilotOfferHistoryInScope($o->call_id))return false;
            $call=$this->call($o->call_id,$c['company']);$run=DB::table('mapa_dispatch_runs')->where('call_id',$call->id)->first();return $run&&$run->state==='offering'
                &&AutomaticDispatchPolicy::workerCurrent(\Carbon\Carbon::parse($run->updated_at,'UTC')->getTimestamp(),now()->getTimestamp())
                &&AutomaticDispatchPolicy::accepts($o->state,\Carbon\Carbon::parse($o->expires_at,'UTC')->getTimestamp(),now()->getTimestamp())
                &&$call->provider!=='asrhealth'&&$call->state==='ready'&&(int)$call->revision===(int)$run->call_revision;})->map(function($o)use($c){$call=$this->call($o->call_id,$c['company']);$review=Policy::meta($call->review);
            $expires=\Carbon\Carbon::parse($o->expires_at,'UTC')->getTimestamp();$state=$expires<=now()->getTimestamp()?'overdue':$o->state;
            return ['id'=>$o->id,'revision'=>(int)$o->revision,'state'=>$state,'can_respond'=>true,'first_acceptance_wins'=>true,
                'expires_at'=>$expires*1000,'road_seconds'=>(int)$o->road_seconds,
                ...AutomaticDispatchPolicy::response($state,\Carbon\Carbon::parse($o->created_at,'UTC')->getTimestamp(),$expires,null,now()->getTimestamp()),
                'pickup_address'=>$review['pickup_address']??'','reported_problem'=>$review['reported_problem']??''];})->values()->all();
    }
    public function respondOffer(Request $r,string $id): array {
        $this->gate();$r->validate(['action_id'=>'required|uuid','expected_revision'=>'required|integer|min:1','response'=>'required|in:accept,decline',
            'decline_reason'=>'required_if:response,decline|in:unavailable,vehicle_issue,already_busy,location_issue,other',
            'decline_detail'=>'required_if:decline_reason,other|nullable|string|max:500']);
        if(!AutomaticDispatchPolicy::validResponseDetails($r->json()->all()))self::fail('Choose a decline reason. For Other, add an explanation.',422);
        $tenant=(new MobileService)->context($r)['company'];
        return DB::transaction(function()use($r,$id,$tenant){
            // The shared tenant lock serializes automatic and operator dispatch mutations.
            $this->lockTenant($tenant);$c=(new MobileService)->context($r,true);
            if($c['binding']->role!=='crew'||!$c['driver']||!$c['vehicle'])self::fail('Verified crew is required.');
            $body=$r->json()->all();$key=['company_uuid'=>$c['company'],'user_uuid'=>$c['user']->uuid,'action_id'=>$body['action_id']];
            $hash=Policy::fingerprint('offer:'.$id.':'.$c['binding']->revision.':'.$c['vehicle']->uuid,$body);
            if($saved=DB::table('mapa_voice_receipts')->where($key)->first()){
                if(!hash_equals($saved->request_hash,$hash))self::fail('Action was already used.',409);return [...Policy::meta($saved->result),'replayed'=>true];
            }
            $offer=DB::table('mapa_dispatch_offers')->where(['id'=>$id,'company_uuid'=>$c['company'],'driver_uuid'=>$c['driver']->uuid,'vehicle_uuid'=>$c['vehicle']->uuid])->lockForUpdate()->first();
            if(!$offer)self::fail('Offer not found.',404);
            $call=$this->call($offer->call_id,$c['company'],true);$run=DB::table('mapa_dispatch_runs')->where('call_id',$call->id)->first();
            if(!$run||$run->state!=='offering'||!$this->pilotCall($call->id)
                ||!$this->pilotOfferHistoryInScope($call->id)
                ||!$this->pilotDriver($offer->driver_uuid,$call->id)
                ||$call->provider==='asrhealth'||$call->state!=='ready'||!$this->workflowEnabled($call)||(int)$call->revision!==(int)$run->call_revision
                ||!AutomaticDispatchPolicy::workerCurrent(\Carbon\Carbon::parse($run->updated_at,'UTC')->getTimestamp(),now()->getTimestamp())
                ||(int)$offer->revision!==$r->json('expected_revision')||!AutomaticDispatchPolicy::accepts($offer->state,strtotime($offer->expires_at.' UTC'),time()))self::fail('Offer handled or changed. Refresh.',409);
            $operator=$this->operator($run);
            if(!$operator)self::fail('Dispatch authorization changed. Contact control.',409);
            if($body['response']==='accept'){
                if($this->sharedWithSource($c['driver'],$c['vehicle']))self::fail('This ambulance is managed by ASR. Contact control.',409);
                $request=Request::create('/','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],json_encode(['confirmed'=>true,'driver_id'=>$c['driver']->public_id],JSON_THROW_ON_ERROR));
                $result=$this->dispatch($request,$call,$operator);
                $this->finish($call->id,'accepted','crew_accepted');
            }else{$result=['ok'=>true];}
            DB::table('mapa_dispatch_offers')->where('id',$id)->update(['state'=>$body['response']==='accept'?'accepted':'declined','responded_at'=>now(),'updated_at'=>now()]);
            if($body['response']==='decline')$this->advance($call);
            $this->audit($call,$c['user']->uuid,'automatic_'.$body['response'],['offer_id'=>$id,
                'is_late_response'=>strtotime($offer->expires_at.' UTC')<time(),
                ...($body['response']==='decline'?['decline_reason'=>$body['decline_reason']??null,'decline_detail'=>$body['decline_detail']??null]:[])]);
            DB::table('mapa_voice_receipts')->insert([...$key,'request_hash'=>$hash,'result'=>json_encode($result,JSON_THROW_ON_ERROR),'created_at'=>now()]);return $result;
        },3);
    }
    private function operator(object $run): ?array {
        $b=DB::table('mapa_mobile_bindings')->where(['id'=>$run->binding_id,'company_uuid'=>$run->company_uuid,'user_uuid'=>$run->operator_uuid,'enabled'=>true,'revision'=>$run->binding_revision])->first();
        $u=\Fleetbase\Models\User::withoutGlobalScopes()->where('uuid',$run->operator_uuid)->where('company_uuid',$run->company_uuid)->whereNull('deleted_at')->where('status','active')->first();
        if(!$b||!$u||!in_array($b->role,['operator','admin','superadmin'],true)||!$this->native('company_users',$run->company_uuid)->where('user_uuid',$u->uuid)->where('status','active')->exists())return null;
        return ['company'=>$run->company_uuid,'user'=>$u,'binding'=>$b];
    }
    private function sharedWithSource(object $driver,object $vehicle): bool {
        if(AutomaticDispatchPolicy::sourceOwned($driver->meta)||AutomaticDispatchPolicy::sourceOwned($vehicle->meta))return true;
        // Explicit linked projections may not carry import metadata on their native records.
        return Schema::hasTable('mapa_source_connections')&&DB::table('mapa_source_connections')->where('company_uuid',$driver->company_uuid)
            ->whereIn('native_uuid',[$driver->uuid,$vehicle->uuid])->whereIn('dataset',['drivers','ambulances'])->exists();
    }
    /** A healthy scheduler records a heartbeat even while automatic offers are off. */
    private function recordWorkerHeartbeat(): void {
        $company=(string)config('mapa.company_uuid');
        if(!config('mapa_voice.enabled',false)||!config('mapa_voice.automatic_dispatch_enabled',false)
            ||!Policy::uuid($company)||\App\MapaMobile\SourceDispatchSaga::enabled()
            ||!Schema::hasTable('mapa_dispatch_settings')||!Schema::hasTable('mapa_dispatch_runs')
            ||!Schema::hasTable('mapa_dispatch_offers'))return;
        DB::transaction(function()use($company){
            $this->lockTenant($company);
            DB::table('mapa_dispatch_settings')->insertOrIgnore([
                'company_uuid'=>$company,'enabled'=>false,'mode'=>'all_live','revision'=>0,
                'created_at'=>now(),'updated_at'=>now()]);
            DB::table('mapa_dispatch_settings')->where('company_uuid',$company)
                ->update(['last_tick_at'=>now()]);
        },3);
    }
    public function tick(): int {
        if(!static::available()){$this->recordWorkerHeartbeat();return 0;}
        $this->gate();$count=0;
        $company=(string)config('mapa.company_uuid');
        foreach(DB::table('mapa_dispatch_runs')->where('company_uuid',$company)->whereIn('state',['offering','searching'])->orderBy('updated_at')->orderBy('call_id')->limit(100)->get() as $run){
            DB::transaction(function()use($run,&$count){
                $this->lockTenant($run->company_uuid);$fresh=DB::table('mapa_dispatch_runs')->where('call_id',$run->call_id)->first();
                if(!$fresh||!in_array($fresh->state,['offering','searching'],true))return;
                $call=$this->call($run->call_id,$run->company_uuid,true);
                if(!$this->pilotCall($call->id)||!$this->pilotOfferHistoryInScope($call->id)){
                    $this->finish($call->id,'needs_operator','pilot_scope_changed');return;
                }
                if(!$this->operator($fresh)||$call->provider==='asrhealth'||$call->state!=='ready'||!$this->workflowEnabled($call)||(int)$call->revision!==(int)$fresh->call_revision){$this->finish($call->id,'stopped','call_or_authorization_changed');return;}
                $expired=DB::table('mapa_dispatch_offers')->where('call_id',$call->id)->where('state','offered')->where('expires_at','<=',now())->lockForUpdate()->get(['id','driver_uuid','vehicle_uuid']);
                if($expired->isNotEmpty()){
                    DB::table('mapa_dispatch_offers')->whereIn('id',$expired->pluck('id')->all())->update(['state'=>'overdue','updated_at'=>now()]);
                    foreach($expired as $offer)$this->audit($call,null,'automatic_no_response',['offer_id'=>$offer->id,'driver_uuid'=>$offer->driver_uuid,'vehicle_uuid'=>$offer->vehicle_uuid]);
                }
                if(!DB::table('mapa_dispatch_offers')->where('call_id',$call->id)->where('state','offered')->exists()){$this->advance($call);$count++;}
                DB::table('mapa_dispatch_runs')->where('call_id',$call->id)->update(['updated_at'=>now()]);
            },3);
        }
        $this->recordWorkerHeartbeat();
        return $count;
    }
}

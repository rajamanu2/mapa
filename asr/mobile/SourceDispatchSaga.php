<?php
namespace App\MapaMobile;
require_once __DIR__.'/SourceDispatchClient.php';
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MapaSource\SourceDispatchCommands as D;
use MapaSource\SourceSync;

/** Durable ownership bridge. Remote ambiguity never deletes an intent or releases a resource. */
class SourceDispatchSaga {
    public static function enabled():bool{return !DispatchAuthority::mapaControls()&&(bool)config('mapa_mobile.source_dispatch_coordination_enabled',false);}
    public static function assertMappingChange(string $company,string $dataset,string $sourceId,?string $oldNativeUuid,?string $newNativeUuid):void {
        if(!self::enabled()||!in_array($dataset,['ambulances','drivers'],true))return;self::ready();
        $native=$dataset==='ambulances'?'vehicle_uuid':'driver_uuid';$source=$dataset==='ambulances'?'source_ambulance_id':'source_driver_id';
        if(DB::table('mapa_source_journeys')->where('company_uuid',$company)->whereNull('terminal_at')->where(function($q)use($source,$sourceId,$native,$oldNativeUuid,$newNativeUuid){$q->where($source,$sourceId);foreach([$oldNativeUuid,$newNativeUuid] as $id)if($id!==null)$q->orWhere($native,$id);})->lockForUpdate()->first())MobileService::fail('An active or uncertain journey holds this identity; reconcile ownership before changing its link.',409);
    }
    private static function ready():void {
        if(!self::enabled()||!Schema::hasTable('mapa_source_journeys')||!Schema::hasTable('mapa_source_dispatch_outbox'))MobileService::fail('Source dispatch coordination is unavailable.',503);
    }
    protected function client():SourceDispatchClient{return new SourceDispatchClient(storage_path('app/mapa-source-sync/dispatch.json'));}
    protected function send(array $command):array{return $this->client()->send($command);}
    private static function canonical(array $value):array{ksort($value);foreach($value as &$v)if(is_array($v))$v=self::canonical($v);return $value;}
    private static function hash(array $value):string{return hash('sha256',json_encode(self::canonical($value),JSON_THROW_ON_ERROR));}
    private static function actor(string $company,string $actor,string $binding,int $revision,array $roles):void {
        $b=DB::table('mapa_mobile_bindings')->where(['id'=>$binding,'company_uuid'=>$company,'user_uuid'=>$actor,'enabled'=>true,'revision'=>$revision])->first();
        if(!$b||!in_array($b->role,$roles,true)||!DB::table('users')->where(['uuid'=>$actor,'company_uuid'=>$company,'status'=>'active'])->whereNull('deleted_at')->exists()
            ||!DB::table('company_users')->where(['company_uuid'=>$company,'user_uuid'=>$actor,'status'=>'active'])->whereNull('deleted_at')->exists())MobileService::fail('Dispatch authority changed; ownership needs review.',403);
    }
    private static function links(string $company,string $driver,string $vehicle):array {
        $state=SourceInventoryController::verifiedState($company);
        if(time()-(int)$state['received_at']>120)MobileService::fail('Fetch fresh ASR records before coordinated dispatch.',409);
        $out=[];foreach(['drivers'=>$driver,'ambulances'=>$vehicle] as $dataset=>$uuid){
            $link=DB::table('mapa_source_connections')->where(['company_uuid'=>$company,'dataset'=>$dataset,'native_uuid'=>$uuid,'active'=>true])->first();
            if(!$link||!preg_match('/^[1-9][0-9]{0,9}$/D',$link->source_id))MobileService::fail('Reviewed ASR driver and ambulance connections are required.',409);
            $row=null;foreach($state['snapshot']['datasets'][$dataset] as $r)if((string)$r['id']===$link->source_id){$row=$r;break;}
            if(!$row||!hash_equals($link->source_fingerprint,SourceConnections::fingerprint($row)))MobileService::fail('ASR identity changed; review its connection before dispatch.',409);
            $out[$dataset]=$link;
        }return $out;
    }
    private static function mappings(object $j):void {
        $links=self::links($j->company_uuid,$j->driver_uuid,$j->vehicle_uuid);
        if((int)$links['drivers']->revision!==(int)$j->driver_link_revision||(int)$links['ambulances']->revision!==(int)$j->vehicle_link_revision||$links['drivers']->source_id!==$j->source_driver_id||$links['ambulances']->source_id!==$j->source_ambulance_id)MobileService::fail('Source connection changed; retain existing ownership for review.',409);
        $driver=DB::table('drivers')->where(['uuid'=>$j->driver_uuid,'company_uuid'=>$j->company_uuid])->whereNull('deleted_at')->first();
        if(!$driver||$driver->vehicle_uuid!==$j->vehicle_uuid||!DB::table('vehicles')->where(['uuid'=>$j->vehicle_uuid,'company_uuid'=>$j->company_uuid])->whereNull('deleted_at')->exists())MobileService::fail('Native fleet assignment changed; retain source ownership for review.',409);
    }
    /** Must run before the VoiceService mutation transaction and before any HTTP. */
    public function prepare(array $c,object $call,object $driver,object $vehicle,array $body):object {
        self::ready();if(DB::transactionLevel()!==0)MobileService::fail('Reservation intent must commit before dispatch.',503);
        if($c['company']!==SourceSync::COMPANY||(bool)$call->demo||$call->company_uuid!==$c['company']||!Str::isUuid($call->id))MobileService::fail('Coordinated dispatch requires a real scoped native call.',422);
        $hash=self::hash(['call'=>$call->id,'actor'=>$c['user']->uuid,'binding'=>$c['binding']->id,'revision'=>(int)$c['binding']->revision,'driver'=>$driver->uuid,'vehicle'=>$vehicle->uuid,'body'=>$body]);
        return DB::transaction(function()use($c,$call,$driver,$vehicle,$body,$hash){
            DB::table('companies')->where('uuid',$c['company'])->lockForUpdate()->first();
            self::actor($c['company'],$c['user']->uuid,$c['binding']->id,(int)$c['binding']->revision,['admin','superadmin']);
            $old=DB::table('mapa_source_journeys')->where(['journey_id'=>$call->id,'company_uuid'=>$c['company']])->lockForUpdate()->first();
            if($old){if(!hash_equals($old->intent_hash,$hash)||$old->terminal_at!==null)MobileService::fail('Retry the same dispatch details or review existing ownership.',409);self::mappings($old);return $old;}
            if(DB::table('mapa_source_journeys')->where('company_uuid',$c['company'])->whereNull('terminal_at')->where(fn($q)=>$q->where('driver_uuid',$driver->uuid)->orWhere('vehicle_uuid',$vehicle->uuid))->exists())MobileService::fail('A pending or active coordinated journey already holds this crew or ambulance.',409);
            $links=self::links($c['company'],$driver->uuid,$vehicle->uuid);
            $command=['contract'=>D::CONTRACT,'company_id'=>$c['company'],'request_id'=>(string)Str::uuid(),'actor_id'=>$c['user']->uuid,'action'=>'reserve','journey_id'=>$call->id,'ambulance_id'=>$links['ambulances']->source_id,'driver_id'=>$links['drivers']->source_id,'expected_version'=>0,'next_status'=>null];D::validate($command);
            DB::table('mapa_source_journeys')->insert(['journey_id'=>$call->id,'company_uuid'=>$c['company'],'driver_uuid'=>$driver->uuid,'vehicle_uuid'=>$vehicle->uuid,'source_driver_id'=>$command['driver_id'],'source_ambulance_id'=>$command['ambulance_id'],
                'driver_link_revision'=>$links['drivers']->revision,'vehicle_link_revision'=>$links['ambulances']->revision,'actor_uuid'=>$c['user']->uuid,'binding_id'=>$c['binding']->id,'binding_revision'=>$c['binding']->revision,'intent_hash'=>$hash,'reserve_command'=>json_encode($command,JSON_THROW_ON_ERROR),'state'=>'pending','created_at'=>now(),'updated_at'=>now()]);
            return DB::table('mapa_source_journeys')->where('journey_id',$call->id)->first();
        });
    }
    private static function mutex(string $journey,callable $fn):mixed {
        if(DB::transactionLevel()!==0)MobileService::fail('Remote coordination cannot run inside a native transaction.',503);
        $key='mapa-dispatch:'.substr(hash('sha256',$journey),0,40);$lock=(array)DB::selectOne('SELECT GET_LOCK(?,0) AS acquired',[$key]);
        if((int)($lock['acquired']??0)!==1)MobileService::fail('This journey is being coordinated. Retry the same request.',409);
        try{return $fn();}finally{DB::selectOne('SELECT RELEASE_LOCK(?) AS released',[$key]);}
    }
    public function reserve(string $company,string $journey):void {
        self::ready();self::mutex($journey,function()use($company,$journey){
            $j=DB::table('mapa_source_journeys')->where(['company_uuid'=>$company,'journey_id'=>$journey])->first();if(!$j)MobileService::fail('Committed dispatch intent is required.',409);
            self::actor($company,$j->actor_uuid,$j->binding_id,(int)$j->binding_revision,['admin','superadmin']);self::mappings($j);
            if($j->state==='confirmed'&&$j->reservation!==null)return;
            if(in_array($j->state,['rejected','terminal'],true))MobileService::fail('Source ownership needs explicit review.',409);
            try{$reply=$this->send(json_decode($j->reserve_command,true,32,JSON_THROW_ON_ERROR));}
            catch(SourceDispatchRefused $e){DB::table('mapa_source_journeys')->where('journey_id',$journey)->update(['state'=>'rejected','updated_at'=>now()]);MobileService::fail('ASR dispatch was refused; retain this intent for review.',409);}
            catch(\Throwable $e){DB::table('mapa_source_journeys')->where('journey_id',$journey)->update(['state'=>'unknown','updated_at'=>now()]);MobileService::fail('ASR ownership is unconfirmed. Retry this same dispatch; do not assign another journey.',503);}
            DB::table('mapa_source_journeys')->where('journey_id',$journey)->update(['state'=>'confirmed','reservation'=>json_encode($reply['reservation'],JSON_THROW_ON_ERROR),'updated_at'=>now()]);
            self::actor($company,$j->actor_uuid,$j->binding_id,(int)$j->binding_revision,['admin','superadmin']);self::mappings($j);
        });
    }
    /** Under the same native transaction and locks as order creation. */
    public function assertReserved(array $c,object $call,object $driver,object $vehicle):object {
        self::ready();if(DB::transactionLevel()<1)MobileService::fail('Native dispatch transaction required.',503);
        $j=DB::table('mapa_source_journeys')->where(['company_uuid'=>$c['company'],'journey_id'=>$call->id])->lockForUpdate()->first();$r=$j?json_decode($j->reservation??'null',true):null;
        if(!$j||$j->state!=='confirmed'||$j->terminal_at!==null||$j->order_uuid!==null||$j->actor_uuid!==$c['user']->uuid||$j->binding_id!==$c['binding']->id||(int)$j->binding_revision!==(int)$c['binding']->revision||$j->driver_uuid!==$driver->uuid||$j->vehicle_uuid!==$vehicle->uuid||($r['status']??null)!=='reserved'||($r['version']??null)!==1)MobileService::fail('Confirmed source reservation does not match this dispatch.',409);
        self::actor($c['company'],$j->actor_uuid,$j->binding_id,(int)$j->binding_revision,['admin','superadmin']);self::mappings($j);return $j;
    }
    public function attach(object $journey,string $order):void {
        if(DB::transactionLevel()<1||!DB::table('orders')->where(['uuid'=>$order,'company_uuid'=>$journey->company_uuid,'driver_assigned_uuid'=>$journey->driver_uuid,'vehicle_assigned_uuid'=>$journey->vehicle_uuid])->whereNull('deleted_at')->exists()
            ||DB::table('mapa_source_journeys')->where(['journey_id'=>$journey->journey_id,'company_uuid'=>$journey->company_uuid,'state'=>'confirmed'])->whereNull('order_uuid')->update(['order_uuid'=>$order,'updated_at'=>now()])!==1)MobileService::fail('Native order could not join source ownership.',409);
    }
    /** Queued in the native activity transaction, after GPS/handover checks. */
    public function enqueue($order,array $c,array $body,string $code,array $audit):void {
        if(!self::enabled())return;self::ready();if(DB::transactionLevel()<1)MobileService::fail('Atomic native activity transaction required.',503);
        $j=DB::table('mapa_source_journeys')->where(['company_uuid'=>$c['company'],'order_uuid'=>$order->uuid])->lockForUpdate()->first();
        if(!$j||$j->driver_uuid!==$c['driver']->uuid||$j->vehicle_uuid!==$c['vehicle']->uuid||$j->terminal_at!==null)MobileService::fail('Crew trip has no matching source ownership.',409);
        self::mappings($j);self::actor($c['company'],$c['user']->uuid,$c['binding']->id,(int)$c['binding']->revision,['crew']);
        if(($audit['crew_confirmed']??false)!==true||($code==='completed'&&($audit['handover_confirmed']??false)!==true))MobileService::fail('Native crew and handover evidence is required.',409);
        $steps=match($code){'started'=>[1=>'en_route'],'enroute'=>[2=>'arrived_scene',3=>'transporting'],'completed'=>[4=>'completed'],default=>[]};
        foreach($steps as $sequence=>$next){
            $command=['contract'=>D::CONTRACT,'company_id'=>$c['company'],'request_id'=>(string)Str::uuid(),'actor_id'=>$c['user']->uuid,'action'=>'transition','journey_id'=>$j->journey_id,'ambulance_id'=>$j->source_ambulance_id,'driver_id'=>$j->source_driver_id,'expected_version'=>$sequence,'next_status'=>$next];D::validate($command);
            DB::table('mapa_source_dispatch_outbox')->insert(['id'=>$command['request_id'],'company_uuid'=>$c['company'],'journey_id'=>$j->journey_id,'sequence'=>$sequence,'actor_uuid'=>$c['user']->uuid,'binding_id'=>$c['binding']->id,'binding_revision'=>$c['binding']->revision,'native_action_id'=>$body['action_id'],'native_status'=>$code,'command'=>json_encode($command,JSON_THROW_ON_ERROR),'state'=>'pending','created_at'=>now(),'updated_at'=>now()]);
        }
    }
    /** Run from an independent explicit worker; no actor credentials are stored in the queue. */
    public function drain(int $limit=20):array {
        self::ready();if($limit<1||$limit>100)throw new \InvalidArgumentException('Bounded worker limit required.');$result=['confirmed'=>0,'held'=>0];
        $rows=DB::table('mapa_source_dispatch_outbox')->whereIn('state',['pending','unknown','held'])->orderBy('updated_at')->orderBy('sequence')->limit($limit)->get();
        foreach($rows as $item){try{$ok=self::mutex($item->journey_id,fn()=>$this->deliver($item->id));$result[$ok?'confirmed':'held']++;}catch(\Throwable $e){$result['held']++;}}
        return $result;
    }
    private function deliver(string $id):bool {
        $e=DB::table('mapa_source_dispatch_outbox')->where('id',$id)->first();if(!$e||$e->state==='confirmed')return true;
        $j=DB::table('mapa_source_journeys')->where(['journey_id'=>$e->journey_id,'company_uuid'=>$e->company_uuid])->first();
        try{
            if(!$j||!$j->order_uuid||$j->terminal_at!==null)MobileService::fail('Native journey is unavailable.',409);
            self::actor($e->company_uuid,$e->actor_uuid,$e->binding_id,(int)$e->binding_revision,['crew']);self::mappings($j);
            $binding=DB::table('mapa_mobile_bindings')->where('id',$e->binding_id)->first();if($binding->driver_uuid!==$j->driver_uuid)MobileService::fail('Crew binding changed.',409);
            if(DB::table('mapa_source_dispatch_outbox')->where('journey_id',$j->journey_id)->where('sequence','<',$e->sequence)->where('state','!=','confirmed')->exists())MobileService::fail('Earlier source transition awaits acknowledgement.',409);
            $r=json_decode($j->reservation??'null',true);if(($r['version']??null)!==(int)$e->sequence)MobileService::fail('Source ownership version awaits reconciliation.',409);
            $order=DB::table('orders')->where(['uuid'=>$j->order_uuid,'company_uuid'=>$j->company_uuid,'driver_assigned_uuid'=>$j->driver_uuid,'vehicle_assigned_uuid'=>$j->vehicle_uuid])->whereNull('deleted_at')->first();
            $meta=$order?json_decode($order->meta??'{}',true):[];$audit=$meta['mapa_mobile_trip'][$e->native_status]??null;
            $crew=DB::table('users')->where('uuid',$e->actor_uuid)->first();
            if(!$order||!is_array($audit)||($audit['action_id']??null)!==$e->native_action_id||($audit['user_id']??null)!==$crew->public_id||($audit['crew_confirmed']??false)!==true||($e->native_status==='completed'&&($order->status!=='completed'||($audit['handover_confirmed']??false)!==true)))MobileService::fail('Persisted native activity evidence is unavailable.',409);
        }catch(\Throwable $err){DB::table('mapa_source_dispatch_outbox')->where('id',$id)->update(['state'=>'held','updated_at'=>now()]);return false;}
        DB::table('mapa_source_dispatch_outbox')->where('id',$id)->increment('attempts');
        try{$reply=$this->send(json_decode($e->command,true,32,JSON_THROW_ON_ERROR));}
        catch(SourceDispatchRefused $err){DB::table('mapa_source_dispatch_outbox')->where('id',$id)->update(['state'=>'rejected','updated_at'=>now()]);return false;}
        catch(\Throwable $err){DB::table('mapa_source_dispatch_outbox')->where('id',$id)->update(['state'=>'unknown','updated_at'=>now()]);return false;}
        DB::transaction(function()use($id,$j,$reply){
            DB::table('mapa_source_dispatch_outbox')->where('id',$id)->update(['state'=>'confirmed','receipt'=>json_encode($reply,JSON_THROW_ON_ERROR),'updated_at'=>now()]);
            $v=$reply['reservation'];DB::table('mapa_source_journeys')->where('journey_id',$j->journey_id)->update(['reservation'=>json_encode($v,JSON_THROW_ON_ERROR),'state'=>$v['terminal_at']!==null?'terminal':'confirmed','terminal_at'=>$v['terminal_at']!==null?now():null,'updated_at'=>now()]);
        });return true;
    }
}

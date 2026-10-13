<?php
// Exercise actual VoiceService mutate/prepare/dispatch/attach transaction ordering.
// Staff authentication, eligibility and native object construction are fixtures;
// their complete native implementations retain independent integration suites.
require_once '/fleetbase/api/app/MapaVoice/Policy.php';require_once '/fleetbase/api/app/MapaVoice/VoiceService.php';
DB::statement('ALTER TABLE drivers ADD public_id VARCHAR(80), ADD location POINT NULL');DB::table('drivers')->update(['public_id'=>'DEMO-driver']);
DB::statement('CREATE TABLE mapa_mobile_driver_states(driver_uuid CHAR(36) PRIMARY KEY) ENGINE=InnoDB');
DB::statement('CREATE TABLE places(uuid CHAR(36) PRIMARY KEY,company_uuid CHAR(36),public_id VARCHAR(80),meta JSON,deleted_at TIMESTAMP NULL) ENGINE=InnoDB');
DB::statement('CREATE TABLE mapa_voice_calls(id CHAR(36) PRIMARY KEY,company_uuid CHAR(36),demo TINYINT,state VARCHAR(24),review JSON,revision INT,order_uuid CHAR(36) NULL,updated_at TIMESTAMP NULL) ENGINE=InnoDB');
DB::statement('CREATE TABLE mapa_voice_receipts(company_uuid CHAR(36),user_uuid CHAR(36),action_id CHAR(36),request_hash CHAR(64),result JSON,created_at TIMESTAMP NULL,PRIMARY KEY(company_uuid,user_uuid,action_id)) ENGINE=InnoDB');
DB::table('places')->insert(['uuid'=>(string)Illuminate\Support\Str::uuid(),'company_uuid'=>$company,'public_id'=>'DEMO-hospital','meta'=>'{"mapa_demo":false}']);
$hookCall=(string)Illuminate\Support\Str::uuid();DB::table('mapa_voice_calls')->insert(['id'=>$hookCall,'company_uuid'=>$company,'demo'=>false,'state'=>'ready','review'=>'{"dropoff_id":"DEMO-hospital"}','revision'=>1]);
class HookVoiceFixture extends App\MapaVoice\VoiceService {
    public array $fixtureContext;public SagaFake $fixtureSaga;public bool $crash=true;
    protected function staff(Illuminate\Http\Request $r,bool $lock=false):array{return $this->fixtureContext;}
    protected function workflowEnabled(object $call):bool{return true;}
    protected function eligible(object $d,object $v,object $call,array $review):?array{return ['eligible'=>true];}
    protected function receivingPlaces(string $company,bool $demo){return $this->native('places',$company);}
    protected function sourceDispatchSaga():App\MapaMobile\SourceDispatchSaga{return $this->fixtureSaga;}
    protected function createOrder(object $call,array $c,array $review,object $driver,object $vehicle,object $dropoff):string {
        $id=(string)Illuminate\Support\Str::uuid();DB::table('orders')->insert(['uuid'=>$id,'company_uuid'=>$c['company'],'driver_assigned_uuid'=>$driver->uuid,'vehicle_assigned_uuid'=>$vehicle->uuid,'status'=>'dispatched','meta'=>'{}']);
        if($this->crash)throw new RuntimeException('DEMO native dispatch rollback');return $id;
    }
    protected function audit(object $call,?string $user,string $action,array $details=[]):void{}
    protected function view(object $call):array{return ['id'=>$call->id,'state'=>$call->state,'order_id'=>$call->order_uuid];}
}
config(['mapa_mobile.source_dispatch_coordination_enabled'=>true]);
$hook=new HookVoiceFixture;$hook->fixtureContext=$context;$hook->fixtureSaga=new SagaFake;
$hookBody=['action_id'=>(string)Illuminate\Support\Str::uuid(),'expected_revision'=>1,'confirmed'=>true,'driver_id'=>'DEMO-driver'];
$hookRequest=fn()=>Illuminate\Http\Request::create('/mapa/voice/dispatch','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],json_encode($hookBody));
$hookBeforeOrders=DB::table('orders')->count();
try{$hook->mutate($hookRequest(),'dispatch',$hookCall);throw new RuntimeException('Crash fixture did not execute');}catch(RuntimeException $e){ck($e->getMessage()==='DEMO native dispatch rollback','actual VoiceService native transaction can fail after source reserve');}
$hookIntent=DB::table('mapa_source_journeys')->where('journey_id',$hookCall)->first();
ck($hookIntent&&$hookIntent->state==='confirmed'&&$hookIntent->order_uuid===null&&DB::table('orders')->count()===$hookBeforeOrders,'VoiceService rollback retains prior committed source intent and removes partial native order');
ck(DB::table('mapa_voice_calls')->where('id',$hookCall)->value('state')==='ready'&&DB::table('mapa_voice_receipts')->count()===0,'failed native dispatch leaves call and receipt uncommitted');
$hook->crash=false;$hookResult=$hook->mutate($hookRequest(),'dispatch',$hookCall);$hookIntent=DB::table('mapa_source_journeys')->where('journey_id',$hookCall)->first();
ck($hookResult['call']['state']==='dispatched'&&$hookIntent->order_uuid===$hookResult['order_id'],'actual VoiceService commits native order and source intent link together');
ck(count($hook->fixtureSaga->sent)===1&&DB::table('orders')->count()===$hookBeforeOrders+1,'same dispatch recovery reuses source reserve and produces one native order');
ck($hook->mutate($hookRequest(),'dispatch',$hookCall)['replayed']===true&&count($hook->fixtureSaga->sent)===1,'actual VoiceService immutable receipt replay performs no extra reserve');
config(['mapa_mobile.source_dispatch_coordination_enabled'=>false]);

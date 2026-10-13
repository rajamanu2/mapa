<?php
/** Real service/Eloquent/SQLite transaction tests; only authentication and native model definitions are fixtures. */
namespace {require $argv[1];}
namespace Fleetbase\FleetOps\Models {
    class Payload extends \Illuminate\Database\Eloquent\Model {protected $table='payloads';protected $guarded=[];}
    class OrderConfig extends \Illuminate\Database\Eloquent\Model {protected $table='order_configs';protected $guarded=[];}
    class TrackingStatus extends \Illuminate\Database\Eloquent\Model {protected $table='tracking_statuses';protected $guarded=[];}
    class Order extends \Illuminate\Database\Eloquent\Model {
        protected $table='orders';protected $guarded=[];protected $casts=['meta'=>'array'];
        public function payload(){return $this->belongsTo(Payload::class,'payload_uuid','uuid');}
        public function orderConfig(){return $this->belongsTo(OrderConfig::class,'order_config_uuid','uuid');}
        public function trackingStatuses(){return $this->hasMany(TrackingStatus::class,'tracking_number_uuid','tracking_number_uuid');}
    }
}
namespace {
use Illuminate\Support\Facades\DB;use Illuminate\Support\Facades\Schema;use Illuminate\Http\Request;
use Fleetbase\FleetOps\Models\Order;use App\MapaMobile\TripArrivalService;use App\MapaMobile\Policy;
$app=new Illuminate\Container\Container;Illuminate\Container\Container::setInstance($app);
$app->instance('config',new Illuminate\Config\Repository(['mapa_mobile'=>['trips_enabled'=>true]]));Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$db=new Illuminate\Database\Capsule\Manager($app);$db->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);$db->setAsGlobal();$db->bootEloquent();
$app->instance('db',$db->getDatabaseManager());$app->bind('db.schema',fn()=>$db->getConnection()->getSchemaBuilder());
foreach(['Policy','PrivateMedia','MobileService','TripArrivalService'] as $file)require __DIR__.'/../'.$file.'.php';
require __DIR__.'/../../voice/CommandDeskPolicy.php';
foreach(['orders','payloads','order_configs','tracking_statuses','mapa_mobile_order_states','mapa_mobile_receipts'] as $table)Schema::create($table,function($t)use($table){
    $t->increments('id');foreach(['uuid','public_id','company_uuid','user_uuid','order_uuid','driver_uuid','driver_assigned_uuid','vehicle_assigned_uuid','payload_uuid','order_config_uuid','tracking_number_uuid','status','meta','action_id','request_hash','result','assignment_hash'] as $col)$t->text($col)->nullable();
    $t->boolean('dispatched')->default(true);$t->bigInteger('acknowledged_at')->nullable();$t->timestamp('deleted_at')->nullable();$t->timestamps();
    if($table==='mapa_mobile_receipts')$t->unique(['company_uuid','user_uuid','action_id']);
});
class ArrivalFixture extends TripArrivalService {
    public array $fixture;public int $clock=1791300000000;
    public function context(Request $r,bool $lock=false): array {if($r->bearerToken()!=='DEMO-auth')self::fail('DEMO unauthorized',401);return $this->fixture;}
    protected function arrivalNow():int{return $this->clock;}
    public function rev():string{return $this->revision(Order::first());}
    public function acknowledgeFixture():void{$o=Order::first();DB::table('mapa_mobile_order_states')->update(['assignment_hash'=>$this->signature($o)]);}
}
$count=0;function check($ok,$message){global $count;if(!$ok)throw new \RuntimeException($message);$count++;}
function denied(callable $fn,int $status){try{$fn();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){check($e->getStatusCode()===$status,'Expected'.$status.' got'.$e->getStatusCode().':'.$e->getMessage());return;}throw new \RuntimeException('Expected denial'.$status);}
function request(array $body,string $token='DEMO-auth'):Request{return Request::create('/orders/order_DEMO/arrivals','POST',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer '.$token],json_encode($body));}
$service=new ArrivalFixture;$company='00000000-0000-4000-8000-000000000001';$now=$service->clock;$ack=$now-60000;
$service->fixture=['company'=>$company,'user'=>(object)['uuid'=>'DEMO-user'],'binding'=>(object)['role'=>'crew'],
    'driver'=>(object)['uuid'=>'DEMO-driver','public_id'=>'driver_DEMO','vehicle_uuid'=>'DEMO-vehicle','online'=>true,'current_job_uuid'=>'DEMO-order'],
    'vehicle'=>(object)['uuid'=>'DEMO-vehicle','public_id'=>'vehicle_DEMO'],'place'=>null,'token_hash'=>str_repeat('a',64)];
DB::table('orders')->insert(['uuid'=>'DEMO-order','public_id'=>'order_DEMO','company_uuid'=>$company,'driver_assigned_uuid'=>'DEMO-driver','vehicle_assigned_uuid'=>'DEMO-vehicle','status'=>'started','meta'=>'{"unrelated":"kept"}']);
DB::table('mapa_mobile_order_states')->insert(['order_uuid'=>'DEMO-order','company_uuid'=>$company,'driver_uuid'=>'DEMO-driver','acknowledged_at'=>$ack]);
$service->acknowledgeFixture();
$gps=['source'=>'device_gps','mock_location'=>false,'demo'=>false,'simulated'=>false,'driver_id'=>'driver_DEMO','vehicle_id'=>'vehicle_DEMO','latitude'=>18.0,'longitude'=>82.0,'accuracy_m'=>5,'recorded_at'=>$now-1000,'received_at'=>$now];
// Test schema mirrors the order state lookup field rather than creating operational tables.
$putGps=function($fix,$session=null)use($company,$service){DB::table('mapa_mobile_receipts')->updateOrInsert(['action_id'=>'DEMO-gps'],['company_uuid'=>$company,'user_uuid'=>'DEMO-user','result'=>json_encode(['_session_hash'=>$session??$service->fixture['token_hash'],'gps_evidence'=>$fix])]);};
$putGps($gps);
$body=['action_id'=>'00000000-0000-4000-8000-000000000011','expected_revision'=>$service->rev(),'arrival_code'=>'pickup_arrived','confirmed'=>true,'reported_at'=>$now];
check($service->nextArrivals(Order::first(),$service->fixture)[0]['code']==='pickup_arrived','native started advertises pickup only');
denied(fn()=>$service->recordArrival(request($body,'wrong'),'order_DEMO'),401);
denied(fn()=>$service->recordArrival(request([...$body,'confirmed'=>false]),'order_DEMO'),422);
denied(fn()=>$service->recordArrival(request([...$body,'arrival_code'=>'invented']),'order_DEMO'),422);
denied(fn()=>$service->recordArrival(request([...$body,'action_id'=>'not-a-uuid']),'order_DEMO'),422);
denied(fn()=>$service->recordArrival(request([...$body,'reported_at'=>$now-120001]),'order_DEMO'),422);
denied(fn()=>$service->recordArrival(request([...$body,'arrival_code'=>'hospital_arrived']),'order_DEMO'),409);
denied(fn()=>$service->recordArrival(request([...$body,'expected_revision'=>str_repeat('0',64)]),'order_DEMO'),409);
$service->fixture['binding']->role='hospital';denied(fn()=>$service->recordArrival(request($body),'order_DEMO'),403);$service->fixture['binding']->role='crew';
$service->fixture['company']='00000000-0000-4000-8000-000000000099';denied(fn()=>$service->recordArrival(request($body),'order_DEMO'),404);$service->fixture['company']=$company;
$service->fixture['driver']->uuid='DEMO-other';denied(fn()=>$service->recordArrival(request($body),'order_DEMO'),404);$service->fixture['driver']->uuid='DEMO-driver';
foreach([['mock_location'=>true],['demo'=>true],['simulated'=>true],['accuracy_m'=>101],['recorded_at'=>$now-180000],['received_at'=>$now+1000]] as $bad){$putGps([...$gps,...$bad]);denied(fn()=>$service->recordArrival(request($body),'order_DEMO'),422);}
$putGps($gps,str_repeat('b',64));denied(fn()=>$service->recordArrival(request($body),'order_DEMO'),422);$putGps($gps);
$result=$service->recordArrival(request($body),'order_DEMO');$meta=Order::first()->meta;
check($result['ok']&&!$result['replayed']&&$meta['unrelated']==='kept','arrival persisted preserves unrelated metadata');
check(Order::first()->status==='started','pickup arrival never changesnative status');
check($meta['mapa_mobile_arrivals']['pickup_arrived']['driver_uuid']==='DEMO-driver'&&$meta['mapa_mobile_arrivals']['pickup_arrived']['acknowledged_at']===$ack,'arrival binds assignment and acknowledgement identity');
check($service->nextArrivals(Order::first(),$service->fixture)===[],'duplicate arrival no longer advertised');
check($service->recordArrival(request($body),'order_DEMO')['replayed']===true,'identical retry returns receipt despite changed revision');
check(DB::table('mapa_mobile_receipts')->count()===2,'retry doesnot duplicate receipt');
denied(fn()=>$service->recordArrival(request([...$body,'reported_at'=>$now-1]),'order_DEMO'),409);
denied(fn()=>$service->recordArrival(request([...$body,'action_id'=>'00000000-0000-4000-8000-000000000012','expected_revision'=>$service->rev()]),'order_DEMO'),409);
DB::table('orders')->update(['status'=>'enroute']);$service->acknowledgeFixture();
check($service->nextArrivals(Order::first(),$service->fixture)[0]['code']==='hospital_arrived','native enroute advertises hospital only');
$hospital=[...$body,'action_id'=>'00000000-0000-4000-8000-000000000013','arrival_code'=>'hospital_arrived','expected_revision'=>$service->rev()];
// Force a receipt failure: metadata and refreshed acknowledgement must roll back together.
$oldMeta=DB::table('orders')->value('meta');$oldHash=DB::table('mapa_mobile_order_states')->value('assignment_hash');
DB::statement("CREATE TRIGGER DEMO_receipt_failure BEFORE INSERT ON mapa_mobile_receipts WHEN NEW.action_id='00000000-0000-4000-8000-000000000013' BEGIN SELECT RAISE(ABORT,'DEMO failure'); END");
try{$service->recordArrival(request($hospital),'order_DEMO');throw new \RuntimeException('Expected SQL failure');}catch(\Illuminate\Database\QueryException $e){check(true,'injected failure reached receipt');}
check(DB::table('orders')->value('meta')===$oldMeta&&DB::table('mapa_mobile_order_states')->value('assignment_hash')===$oldHash,'metadata and acknowledgement atomic rollback');DB::statement('DROP TRIGGER DEMO_receipt_failure');
$result=$service->recordArrival(request($hospital),'order_DEMO');$event=$result['arrival'];
check(Order::first()->status==='enroute'&&$event['code']==='hospital_arrived','hospital arrival records without premature completion');
$at=App\MapaVoice\CommandDeskPolicy::reportedArrival($event,$company,'DEMO-order','DEMO-driver','DEMO-vehicle',$ack,$now+600000);
check($at===$now&&App\MapaVoice\CommandDeskPolicy::closure('enroute',$at,$now+600000)['state']==='review_delay','real writer evidence drives closure reminder');
check(App\MapaVoice\CommandDeskPolicy::reportedArrival($event,$company,'DEMO-order','DEMO-other','DEMO-vehicle',$ack,$now+600000)===null,'otherdriver cannot inherit arrival');
check(App\MapaVoice\CommandDeskPolicy::reportedArrival($event,$company,'DEMO-order','DEMO-driver','DEMO-vehicle',$ack+1,$now+600000)===null,'new acknowledgement cannot inherit oldarrival');
DB::table('orders')->update(['status'=>'completed']);check($service->recordArrival(request($hospital),'order_DEMO')['replayed']===true,'lost success response recovers after completion');
DB::table('mapa_mobile_order_states')->update(['acknowledged_at'=>$ack+1]);denied(fn()=>$service->recordArrival(request($hospital),'order_DEMO'),409);
echo 'TRIP ARRIVALS PASS '.$count.' checks'.PHP_EOL;
}

<?php
/** Isolated SQLite engine tests. Native authentication/order creation have separate integration suites. */
namespace {require $argv[1];require __DIR__.'/../../mobile/DispatchAuthority.php';}
namespace Fleetbase\Models {
    class User extends \Illuminate\Database\Eloquent\Model {protected $table='users';public $timestamps=false;protected $guarded=[];}
}
namespace App\MapaMobile {
    class SourceDispatchSaga {public static function enabled(): bool {return false;}}
    class MobileService {
        public function context($request,$lock=false): array {
            $token=$request->bearerToken();$b=\Illuminate\Support\Facades\DB::table('mapa_mobile_bindings')->where('id',$token)->where('enabled',1)->first();
            if(!$b)throw new \Symfony\Component\HttpKernel\Exception\HttpException(401,'Fixture authentication required');
            $u=\Fleetbase\Models\User::where('uuid',$b->user_uuid)->first();
            $d=\Illuminate\Support\Facades\DB::table('drivers')->where('uuid',$b->driver_uuid)->first();
            $v=$d?\Illuminate\Support\Facades\DB::table('vehicles')->where('uuid',$d->vehicle_uuid)->first():null;
            return ['company'=>$b->company_uuid,'user'=>$u,'binding'=>$b,'driver'=>$d,'vehicle'=>$v];
        }
    }
    class ConsoleEnrollmentService {
        public function context($request,$lock=false): array {return (new MobileService)->context($request,$lock);}
    }
}
namespace {
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\MapaVoice\AutomaticDispatch;
$app=new Illuminate\Container\Container;Illuminate\Container\Container::setInstance($app);
$app->instance('config',new Illuminate\Config\Repository(['mapa_voice'=>['enabled'=>true,'automatic_dispatch_enabled'=>true,'automatic_offer_seconds'=>120]]));
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$db=new Illuminate\Database\Capsule\Manager($app);$db->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);$db->setAsGlobal();$db->bootEloquent();
$app->instance('db',$db->getDatabaseManager());$app->bind('db.schema',fn()=>$db->getConnection()->getSchemaBuilder());
$app->instance(Illuminate\Http\Client\Factory::class,new Illuminate\Http\Client\Factory);
Request::macro('validate',fn($rules)=>$this->json()->all()); // Input-shape validation is tested by the HTTP/native suites.
require __DIR__.'/../Policy.php';require __DIR__.'/../VoiceService.php';require __DIR__.'/../AutomaticDispatchPolicy.php';require __DIR__.'/../AutomaticDispatchScope.php';require __DIR__.'/../RecommendationService.php';require __DIR__.'/../AutomaticDispatch.php';require __DIR__.'/../DispatchSettingsService.php';require __DIR__.'/../DeliverOfferPush.php';require __DIR__.'/../../mobile/PrivateRoadRoute.php';
$migration=require __DIR__.'/../migrations/2026_10_07_160000_create_mapa_dispatch_offers.php';$migration->up();
foreach(['companies','users','company_users','drivers','vehicles','mapa_mobile_bindings','mapa_voice_calls','mapa_voice_receipts','mapa_voice_audit','orders'] as $table)Schema::create($table,function($t)use($table){
    if($table==='mapa_voice_calls')$t->uuid('id')->primary();else $t->increments('id');foreach(['uuid','public_id','company_uuid','user_uuid','driver_uuid','vehicle_uuid','role','name','status','provider','state','draft','review','meta','operator_uuid','order_uuid','request_hash','action_id','result','call_id','action','details'] as $col)$t->text($col)->nullable();
    $t->integer('revision')->default(1);$t->boolean('enabled')->default(1);$t->boolean('demo')->default(0);$t->float('latitude')->default(18);$t->float('longitude')->default(82);$t->timestamp('deleted_at')->nullable();$t->timestamps();
});
class Engine extends AutomaticDispatch {
    // Source snapshot safety is exercised by source-trip-eligibility.php; engine fixtures have no source receiver.
    protected function sourceTripBlock(string $company,object $driver,object $vehicle): ?array {return null;}
    // Exercise the historical engine cases independently of the single-call pilot.
    // The real pilot boundary has separate, unoverridden tests below.
    public static function available(): bool {return config('mapa_voice.enabled')&&config('mapa_voice.automatic_dispatch_enabled')
        &&Schema::hasTable('mapa_dispatch_runs')&&Schema::hasTable('mapa_dispatch_offers');}
    protected function pilotCall(mixed $id): bool {return true;}
    protected function pilotDriver(mixed $uuid,mixed $callId=null): bool {return true;}
    protected function pilotDriverUuids(mixed $callId=null): array {return DB::table('drivers')->pluck('uuid')->all();}
    protected function pilotOfferHistoryInScope(string $callId): bool {return true;}
    public static bool $routingFails=false;
    public static int $routeRequests=0;
    public static int $fixTime=1000;
    public static array $auditActions=[];
    protected function roadSeconds(array $from,array $to): ?int {
        self::$routeRequests++;
        $router=new App\MapaMobile\PrivateRoadRoute(fn()=>json_encode(self::$routingFails?['code'=>'NoRoute']:['code'=>'Ok','routes'=>[['duration'=>abs($from['longitude']-82.02)<0.00001?30:120,'distance'=>1000,'geometry'=>['type'=>'LineString','coordinates'=>[[$from['longitude'],$from['latitude']],[$to['longitude'],$to['latitude']]]]]]]),fn()=>['10.0.0.2']);
        $route=$router->request($from,$to,['enabled'=>true,'origin'=>App\MapaMobile\PrivateRoadRoute::ORIGIN]);return $route===null?null:(int)ceil($route['duration_s']);
    }
    protected function staff(Request $r,bool $lock=false): array {$c=(new App\MapaMobile\MobileService)->context($r,$lock);if(!in_array($c['binding']->role,['admin','superadmin'],true))self::fail('Staff only',403);return $c;}
    protected function view(object $call): array {return ['id'=>$call->id,'state'=>$call->state,'revision'=>(int)$call->revision];}
    protected function workflowEnabled(object $call): bool {return config('mapa_voice.automatic_dispatch_enabled');}
    protected function candidates(object $call): array {return DB::table('drivers')->where('company_uuid',$call->company_uuid)->where('status','available')->orderBy('name')->get()->map(fn($d)=>['id'=>$d->public_id,'fix_recorded_at'=>self::$fixTime])->all();}
    protected function audit(object $call,?string $actor,string $action,array $details=[]): void {
        self::$auditActions[]=['action'=>$action,'call_id'=>$call->id,...$details];
        DB::table('mapa_voice_audit')->insert(['company_uuid'=>$call->company_uuid,'call_id'=>$call->id,'action'=>$action,'details'=>json_encode($details)]);
    }
    protected function dispatch(Request $r,object $call,array $c): array {
        $d=DB::table('drivers')->where('public_id',$r->json('driver_id'))->first();
        if($d->status!=='available')self::fail('Eligibility changed',409);
        DB::table('orders')->insert(['uuid'=>(string)Str::uuid(),'company_uuid'=>$call->company_uuid,'driver_uuid'=>$d->uuid,'vehicle_uuid'=>$d->vehicle_uuid,'state'=>'dispatched']);
        DB::table('mapa_voice_calls')->where('id',$call->id)->update(['state'=>'dispatched']);
        return ['ok'=>true,'call'=>['id'=>$call->id,'state'=>'dispatched']];
    }
}
$checks=[];function check($condition,$name){global $checks;if(!$condition)throw new RuntimeException('FAILED: '.$name);$checks[]=$name;}
function denied(callable $f,$status,$name){try{$f();}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){check($e->getStatusCode()===$status,$name);return;}throw new RuntimeException('Not denied: '.$name);}
function req($token,$body=[]){return Request::create('/','POST',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer '.$token],json_encode($body));}
function callFixture($company,$provider='manual'){$id=(string)Str::uuid();DB::table('mapa_voice_calls')->insert(['id'=>$id,'company_uuid'=>$company,'provider'=>$provider,'state'=>'ready','review'=>json_encode(['latitude'=>18,'longitude'=>82]),'revision'=>1]);return $id;}
function startBody(){return ['action_id'=>(string)Str::uuid(),'expected_revision'=>1,'confirmed'=>true];}
$company=(string)Str::uuid();DB::table('companies')->insert(['uuid'=>$company]);
config(['mapa.company_uuid'=>$company]);
$operator=(string)Str::uuid();DB::table('users')->insert(['uuid'=>$operator,'company_uuid'=>$company,'status'=>'active']);DB::table('company_users')->insert(['company_uuid'=>$company,'user_uuid'=>$operator,'status'=>'active']);
$admin=DB::table('mapa_mobile_bindings')->insertGetId(['company_uuid'=>$company,'user_uuid'=>$operator,'role'=>'admin']);
$crews=[];foreach([1,2,3] as $n){$u=(string)Str::uuid();$d=(string)Str::uuid();$v=(string)Str::uuid();DB::table('users')->insert(['uuid'=>$u,'company_uuid'=>$company,'status'=>'active']);DB::table('vehicles')->insert(['uuid'=>$v,'company_uuid'=>$company,'meta'=>'{}']);DB::table('drivers')->insert(['uuid'=>$d,'public_id'=>'DEMO-driver-'.$n,'company_uuid'=>$company,'user_uuid'=>$u,'vehicle_uuid'=>$v,'name'=>'DEMO '.$n,'status'=>'available','longitude'=>82+$n/100,'meta'=>'{}']);$b=DB::table('mapa_mobile_bindings')->insertGetId(['company_uuid'=>$company,'user_uuid'=>$u,'driver_uuid'=>$d,'role'=>'crew']);$crews[$d]=$b;}
Http::preventStrayRequests();Http::fake(fn($r)=>Http::response(['code'=>'Ok','routes'=>[['duration'=>str_contains($r->url(),'82.02,')?30:120]]],200));
$engine=new Engine;
$foreignCompany=(string)Str::uuid();DB::table('companies')->insert(['uuid'=>$foreignCompany]);
$foreignCall=callFixture($foreignCompany);
DB::table('mapa_dispatch_runs')->insert(['call_id'=>$foreignCall,'company_uuid'=>$foreignCompany,
    'operator_uuid'=>(string)Str::uuid(),'binding_id'=>(string)Str::uuid(),'binding_revision'=>1,
    'call_revision'=>1,'state'=>'searching','created_at'=>now(),'updated_at'=>now()]);
$automatic=callFixture($company);$reviewed=DB::table('mapa_voice_calls')->where('id',$automatic)->first();
$staff=(new App\MapaMobile\MobileService)->context(req($admin));
$engine->startReviewed($reviewed,$staff);$engine->startReviewed($reviewed,$staff);
check(DB::table('mapa_dispatch_runs')->where('call_id',$automatic)->count()===1&&DB::table('mapa_dispatch_runs')->where('call_id',$automatic)->value('state')==='searching'&&DB::table('mapa_dispatch_offers')->where('call_id',$automatic)->count()===0,'Confirmed review saves one durable search without routing inside the request');
DB::table('mapa_dispatch_runs')->where('call_id',$automatic)->update(['updated_at'=>now()->subMinutes(2)]);
check($engine->status($automatic,$company)['worker_stale']===true,'Operator sees a stalled automatic worker');
$engine->tick();check(DB::table('mapa_dispatch_offers')->where('call_id',$automatic)->count()===1&&$engine->status($automatic,$company)['worker_stale']===false,'Background worker starts offer without a separate desk click');
check(DB::table('mapa_dispatch_runs')->where('call_id',$foreignCall)->value('state')==='searching',
    'Worker never mutates another company run');
$engine->control(req($admin,startBody()),$automatic,'stop');
$id=callFixture($company);$body=startBody();$result=$engine->control(req($admin,$body),$id,'start');
if(!DB::table('mapa_dispatch_offers')->where('call_id',$id)->exists())throw new RuntimeException(json_encode($result));
$offer=DB::table('mapa_dispatch_offers')->where('call_id',$id)->first();$best=DB::table('drivers')->where('public_id','DEMO-driver-1')->first();
check($offer->driver_uuid===$best->uuid,'Nearest radius band is offered before a faster distant road route');check(DB::table('orders')->count()===0,'Offer does not create trip');
$beforeStatus=Engine::$routeRequests;$monitor=$engine->status($id,$company);
check(Engine::$routeRequests===$beforeStatus,'Read-only queue status makes zero route requests');
check($monitor['offers'][0]['remaining_seconds']>0&&!$monitor['offers'][0]['no_response'],'Offer countdown reports active response window');
check($monitor['offers'][0]['timeout_seconds']===120&&$monitor['offer_count']===1&&$monitor['replacement_policy']==='next_unattempted_eligible_ambulance'&&$monitor['max_offers']===5,'Operator sees exact timeout, five-offer limit and replacement policy');
check($monitor['offers'][0]['distance_m']>0&&$monitor['offers'][0]['radius_band_m']===1500,'Operator sees saved pickup distance band');
check($monitor['replacement_suggestions']===[]&&$monitor['replacement_routing_status']==='refresh_selected_call','Queue status never performs replacement routing');
check(count($engine->recommendations(DB::table('mapa_voice_calls')->where('id',$id)->first(),true)['ranked'])===2,'Selected replacement recommendations exclude currently offered crew');
check($engine->control(req($admin,$body),$id,'start')['replayed']===true,'Start retry replays stored receipt');
denied(fn()=>$engine->control(req($admin,startBody()),$id,'start'),409,'Second start blocked');
denied(fn()=>$engine->respondOffer(req($crews[DB::table('drivers')->where('public_id','DEMO-driver-2')->value('uuid')],['action_id'=>(string)Str::uuid(),'expected_revision'=>1,'response'=>'accept']),$offer->id),404,'Other crew cannot accept');
$missingReason=['action_id'=>(string)Str::uuid(),'expected_revision'=>1,'response'=>'decline'];
denied(fn()=>$engine->respondOffer(req($crews[$offer->driver_uuid],$missingReason),$offer->id),422,'Decline without reason is rejected before changing the offer');
$otherWithoutDetail=[...$missingReason,'action_id'=>(string)Str::uuid(),'decline_reason'=>'other'];
denied(fn()=>$engine->respondOffer(req($crews[$offer->driver_uuid],$otherWithoutDetail),$offer->id),422,'Other decline requires an explanation');
$decline=['action_id'=>(string)Str::uuid(),'expected_revision'=>1,'response'=>'decline','decline_reason'=>'unavailable'];$engine->respondOffer(req($crews[$offer->driver_uuid],$decline),$offer->id);
check(DB::table('mapa_dispatch_offers')->where('id',$offer->id)->value('state')==='declined','Decline stored');
check($engine->status($id,$company)['alerts'][0]['kind']==='declined','Decline is visible to operator');
check(collect($engine->status($id,$company)['offers'])->firstWhere('id',$offer->id)['decline_reason']==='unavailable',
    'Operator sees the crew-provided decline reason');
$next=DB::table('mapa_dispatch_offers')->where('call_id',$id)->where('state','offered')->first();check($next&&$next->driver_uuid!==$offer->driver_uuid,'Decline advances to next crew');
DB::table('mapa_dispatch_offers')->where('id',$next->id)->update(['expires_at'=>now()->subSecond()]);
(new Engine)->tick();check(DB::table('mapa_dispatch_offers')->where('id',$next->id)->value('state')==='overdue','Restarted worker cascades durable offer without cancelling response');
$monitor=$engine->status($id,$company);$expired=array_values(array_filter($monitor['offers'],fn($row)=>$row['id']===$next->id))[0];check($expired['no_response']&&$expired['response_seconds']===null,'Expired offer reports no response rather than response time');
check(count(array_filter(Engine::$auditActions,fn($row)=>$row['action']==='automatic_no_response'&&$row['offer_id']===$next->id))===1,'No response expiry has explicit audit evidence');
$last=DB::table('mapa_dispatch_offers')->where('call_id',$id)->where('state','offered')->first();check($last&&$last->driver_uuid!==$next->driver_uuid,'Expiry advances');
$lateDto=$engine->offersForContext((new App\MapaMobile\MobileService)->context(req($crews[$next->driver_uuid])))[0];check($lateDto['state']==='overdue'&&$lateDto['can_respond']&&$lateDto['remaining_seconds']===0,'Crew receives actionable overdue offer');
$accept=['action_id'=>(string)Str::uuid(),'expected_revision'=>1,'response'=>'accept'];$engine->respondOffer(req($crews[$next->driver_uuid],$accept),$next->id);
check(DB::table('mapa_dispatch_offers')->where('id',$last->id)->value('state')==='cancelled','Late winner cancels replacement offer');
check(DB::table('orders')->count()===1,'Acceptance creates one trip');check($engine->respondOffer(req($crews[$next->driver_uuid],$accept),$next->id)['replayed']===true,'Acceptance retry idempotent');check(DB::table('orders')->count()===1,'Retry does not duplicate trip');
denied(fn()=>$engine->respondOffer(req($crews[$last->driver_uuid],['action_id'=>(string)Str::uuid(),'expected_revision'=>1,'response'=>'accept']),$last->id),409,'Second acceptance rejected');
$source=callFixture($company,'asrhealth');denied(fn()=>$engine->control(req($admin,startBody()),$source,'start'),409,'ASR call never automatically dispatched');
denied(fn()=>$engine->control(req($crews[$last->driver_uuid],startBody()),callFixture($company),'start'),403,'Crew cannot start automatic dispatch');
$stopped=callFixture($company);$engine->control(req($admin,startBody()),$stopped,'start');$engine->control(req($admin,startBody()),$stopped,'stop');check(DB::table('mapa_dispatch_offers')->where('call_id',$stopped)->where('state','offered')->count()===0,'Stop cancels outstanding offer');
$previousDriver=DB::table('mapa_dispatch_offers')->where('call_id',$stopped)->value('driver_uuid');$resume=startBody();$engine->control(req($admin,$resume),$stopped,'start');
$resumed=DB::table('mapa_dispatch_offers')->where('call_id',$stopped)->where('state','offered')->first();check($resumed&&$resumed->driver_uuid!==$previousDriver,'Explicit resume preserves attempted history and offers different crew');
check($engine->control(req($admin,$resume),$stopped,'start')['replayed']===true&&DB::table('mapa_dispatch_offers')->where('call_id',$stopped)->count()===2,'Resume retry replays without duplicate offer');
$engine->control(req($admin,startBody()),$stopped,'stop');
$cascade=callFixture($company);$engine->control(req($admin,startBody()),$cascade,'start');
$old=DB::table('mapa_dispatch_offers')->where('call_id',$cascade)->first();DB::table('mapa_dispatch_offers')->where('id',$old->id)->update(['expires_at'=>now()->subSecond()]);$engine->tick();
$fresh=DB::table('mapa_dispatch_offers')->where('call_id',$cascade)->where('state','offered')->first();
$monitor=$engine->status($cascade,$company);check($monitor['outstanding_count']===2&&$monitor['overdue_count']===1&&$monitor['first_acceptance_wins'],'Operator sees two outstanding offers and one overdue');
$another=callFixture($company);$engine->control(req($admin,startBody()),$another,'start');$other=DB::table('mapa_dispatch_offers')->where('call_id',$another)->first();
check($other&&$other->driver_uuid!==$old->driver_uuid&&$other->driver_uuid!==$fresh->driver_uuid,'Overdue reservations exclude crew and ambulance from other calls');$engine->control(req($admin,startBody()),$another,'stop');
$lateDecline=['action_id'=>(string)Str::uuid(),'expected_revision'=>1,'response'=>'decline','decline_reason'=>'vehicle_issue','decline_detail'=>'DEMO fixture'];
$engine->respondOffer(req($crews[$old->driver_uuid],$lateDecline),$old->id);
check(DB::table('mapa_dispatch_offers')->where('call_id',$cascade)->count()===2&&DB::table('mapa_dispatch_offers')->where('id',$fresh->id)->value('state')==='offered','Overdue decline does not duplicate still fresh replacement');
check(count(array_filter(Engine::$auditActions,fn($a)=>($a['offer_id']??null)===$old->id&&($a['decline_reason']??null)==='vehicle_issue'))===1,'Structured decline reason stored in audit');
foreach([1,2] as $n){DB::table('mapa_dispatch_offers')->where('call_id',$cascade)->where('state','offered')->update(['expires_at'=>now()->subSecond()]);$engine->tick();}
check(DB::table('mapa_dispatch_runs')->where('call_id',$cascade)->value('state')==='needs_operator'&&DB::table('mapa_dispatch_offers')->where('call_id',$cascade)->where('state','cancelled')->count()===2,'Exhausted cascade releases unanswered offers and alerts operator');
DB::table('drivers')->where('uuid',$fresh->driver_uuid)->update(['status'=>'offline']);
denied(fn()=>$engine->respondOffer(req($crews[$fresh->driver_uuid],['action_id'=>(string)Str::uuid(),'expected_revision'=>1,'response'=>'accept']),$fresh->id),409,'Closed exhausted search rejects late acceptance');
DB::table('drivers')->where('uuid',$fresh->driver_uuid)->update(['status'=>'available']);
$engine->control(req($admin,startBody()),$cascade,'stop');check(DB::table('mapa_dispatch_offers')->where('call_id',$cascade)->whereIn('state',['offered','overdue'])->count()===0,'Stop cancels every overdue and fresh offer');
$beforeTick=callFixture($company);$engine->control(req($admin,startBody()),$beforeTick,'start');$pending=DB::table('mapa_dispatch_offers')->where('call_id',$beforeTick)->first();DB::table('mapa_dispatch_offers')->where('id',$pending->id)->update(['expires_at'=>now()->subSecond()]);
$engine->respondOffer(req($crews[$pending->driver_uuid],['action_id'=>(string)Str::uuid(),'expected_revision'=>1,'response'=>'accept']),$pending->id);check(DB::table('mapa_dispatch_offers')->where('id',$pending->id)->value('state')==='accepted','Late acceptance before worker tick is valid');
$staleResponseCall=callFixture($company);$engine->control(req($admin,startBody()),$staleResponseCall,'start');
$staleOffer=DB::table('mapa_dispatch_offers')->where('call_id',$staleResponseCall)->first();
DB::table('mapa_dispatch_runs')->where('call_id',$staleResponseCall)->update(['updated_at'=>now()->subSeconds(91)]);
$staleStatus=$engine->status($staleResponseCall,$company);
check($staleStatus['worker_stale']&&$staleStatus['offers'][0]['can_respond']===false
    &&$engine->offersForContext((new App\MapaMobile\MobileService)->context(req($crews[$staleOffer->driver_uuid])))===[],
    'Stale scheduler removes crew action and marks the operator view stale');
$ordersBeforeStale=DB::table('orders')->count();
denied(fn()=>$engine->respondOffer(req($crews[$staleOffer->driver_uuid],['action_id'=>(string)Str::uuid(),'expected_revision'=>1,'response'=>'accept']),$staleOffer->id),409,
    'Stale scheduler cannot assign an ambulance from a pending offer');
DB::table('mapa_dispatch_runs')->where('call_id',$staleResponseCall)->update(['updated_at'=>now()]);
DB::table('mapa_dispatch_offers')->where('id',$staleOffer->id)->update(['expires_at'=>now()->subSeconds(91)]);
check($engine->status($staleResponseCall,$company)['offers'][0]['can_respond']===false,
    'Offer beyond the late-response limit is not shown as actionable');
denied(fn()=>$engine->respondOffer(req($crews[$staleOffer->driver_uuid],['action_id'=>(string)Str::uuid(),'expected_revision'=>1,'response'=>'accept']),$staleOffer->id),409,
    'Very old offer cannot assign an ambulance after worker recovery');
check(DB::table('orders')->count()===$ordersBeforeStale,'Rejected stale acceptances create no native trip');
$engine->control(req($admin,startBody()),$staleResponseCall,'stop');
$changed=callFixture($company);$engine->control(req($admin,startBody()),$changed,'start');DB::table('mapa_voice_calls')->where('id',$changed)->update(['revision'=>2]);$engine->tick();check(DB::table('mapa_dispatch_runs')->where('call_id',$changed)->value('state')==='stopped','Changed review invalidates offers');
DB::table('drivers')->update(['meta'=>json_encode(['asr_import'=>['source_id'=>1]])]);$shared=callFixture($company);$engine->control(req($admin,startBody()),$shared,'start');check(DB::table('mapa_dispatch_offers')->where('call_id',$shared)->count()===0,'Shared ASR drivers excluded');
config(['mapa_mobile.dispatch_authority'=>'mapa']);
Schema::create('mapa_source_journeys',function($t){$t->text('company_uuid');$t->timestamp('terminal_at')->nullable();});
Schema::create('mapa_source_dispatch_outbox',function($t){$t->text('company_uuid');$t->text('state');});
$pendingOwnership=callFixture($company);DB::table('mapa_source_dispatch_outbox')->insert(['company_uuid'=>$company,'state'=>'pending']);
denied(fn()=>$engine->control(req($admin,startBody()),$pendingOwnership,'start'),409,'Pending source outbox blocks new MAPA offers');
check(!DB::table('mapa_dispatch_offers')->where('call_id',$pendingOwnership)->exists(),'Unresolved cutover creates no offer');
DB::table('mapa_source_dispatch_outbox')->delete();
$controlled=callFixture($company);$engine->control(req($admin,startBody()),$controlled,'start');
check(DB::table('mapa_dispatch_offers')->where('call_id',$controlled)->where('state','offered')->exists(),'MAPA authority allows qualified imported crews to receive native offers');
check(DB::table('drivers')->where('meta','like','%asr_import%')->exists(),'Dispatch authority does not erase source provenance');
$engine->control(req($admin,startBody()),$controlled,'stop');
$sourceHistory=callFixture($company);DB::table('mapa_voice_calls')->where('id',$sourceHistory)->update(['provider'=>'asrhealth']);
denied(fn()=>$engine->control(req($admin,startBody()),$sourceHistory,'start'),409,'ASR historical call mirrors remain read-only after MAPA cutover');
check(!DB::table('mapa_dispatch_offers')->where('call_id',$sourceHistory)->exists(),'Cutover never automatically dispatches source call history');
config(['mapa_mobile.dispatch_authority'=>'asr']);
DB::table('drivers')->update(['meta'=>'{}']);Engine::$routingFails=true;$noRoute=callFixture($company);$engine->control(req($admin,startBody()),$noRoute,'start');check(DB::table('mapa_dispatch_runs')->where('call_id',$noRoute)->value('reason')==='road_routing_unavailable','No route escalates without guessed ETA');
check(App\MapaVoice\AutomaticDispatchPolicy::accepts('offered',100,100)
    &&App\MapaVoice\AutomaticDispatchPolicy::accepts('overdue',100,189)
    &&!App\MapaVoice\AutomaticDispatchPolicy::accepts('overdue',100,191)
    &&!App\MapaVoice\AutomaticDispatchPolicy::accepts('expired',100,200),
    'Cascade permits a bounded late response while old and terminal offers are closed');
check(App\MapaVoice\AutomaticDispatchPolicy::rank([['id'=>'b','road_seconds'=>10],['id'=>'a','road_seconds'=>10],['id'=>'bad','road_seconds'=>NAN]])[0]['id']==='a','Stable ranking excludes invalid duration');
Engine::$routingFails=false;
$engine->control(req($admin,startBody()),$noRoute,'start');check(DB::table('mapa_dispatch_offers')->where('call_id',$noRoute)->where('state','offered')->exists(),'Explicit resource retry resumes when trusted routing recovers');
$engine->control(req($admin,startBody()),$noRoute,'stop');
$one=callFixture($company);$engine->control(req($admin,startBody()),$one,'start');$firstOffer=DB::table('mapa_dispatch_offers')->where('call_id',$one)->where('state','offered')->first();
$two=callFixture($company);$engine->control(req($admin,startBody()),$two,'start');$secondOffer=DB::table('mapa_dispatch_offers')->where('call_id',$two)->where('state','offered')->first();
check($firstOffer&&$secondOffer&&$firstOffer->driver_uuid!==$secondOffer->driver_uuid,'Active crew offer excludes another call');
denied(fn()=>$engine->control(req($admin,startBody()),callFixture((string)Str::uuid()),'start'),404,'Other tenant call hidden');
DB::table('mapa_mobile_bindings')->where('id',$admin)->update(['enabled'=>false]);
denied(fn()=>$engine->respondOffer(req($crews[$firstOffer->driver_uuid],['action_id'=>(string)Str::uuid(),'expected_revision'=>1,'response'=>'accept']),$firstOffer->id),409,'Revoked operator invalidates acceptance');
$engine->tick();check(DB::table('mapa_dispatch_runs')->where('call_id',$one)->value('state')==='stopped','Worker cancels revoked operator offers');
DB::table('mapa_mobile_bindings')->where('id',$admin)->update(['enabled'=>true]);
$pause=callFixture($company);$engine->control(req($admin,startBody()),$pause,'start');config(['mapa_voice.automatic_dispatch_enabled'=>false]);$engine->control(req($admin,startBody()),$pause,'stop');
check(DB::table('mapa_dispatch_runs')->where('call_id',$pause)->value('state')==='stopped','Operator can stop after feature disabled');
config(['mapa_voice.automatic_dispatch_enabled'=>true]);
// Production eligibility uses the spatial driver fix, not scalar legacy columns.
Schema::table('drivers',fn($t)=>$t->text('location')->nullable());
$pdo=DB::connection()->getPdo();$pdo->sqliteCreateFunction('ST_Y',fn($point)=>$point?json_decode($point,true)[1]:null,1);
$pdo->sqliteCreateFunction('ST_X',fn($point)=>$point?json_decode($point,true)[0]:null,1);
foreach(DB::table('drivers')->get() as $d)DB::table('drivers')->where('uuid',$d->uuid)->update(['location'=>json_encode([$d->longitude,$d->latitude]),'latitude'=>0,'longitude'=>0]);
$spatial=callFixture($company);$engine->control(req($admin,startBody()),$spatial,'start');
check(DB::table('mapa_dispatch_offers')->where('call_id',$spatial)->value('driver_uuid')===$best->uuid,'Road recommendation uses authoritative spatial GPS instead of stale scalar columns');
$engine->control(req($admin,startBody()),$spatial,'stop');
config(['cache.default'=>'array','cache.stores.array'=>['driver'=>'array']]);$app->instance('cache',new Illuminate\Cache\CacheManager($app));
$cachedCall=DB::table('mapa_voice_calls')->where('id',$spatial)->first();$directory=['candidates'=>[['id'=>$best->public_id,'fix_recorded_at'=>1000]],'unavailable'=>[]];
$beforeCache=Engine::$routeRequests;$engine->recommendationsForCall($cachedCall,$directory);$engine->recommendationsForCall($cachedCall,$directory);
check(Engine::$routeRequests===$beforeCache+1,'Selected call caches identical trusted road request');
$directory['candidates'][0]['fix_recorded_at']=2000;$engine->recommendationsForCall($cachedCall,$directory);
check(Engine::$routeRequests===$beforeCache+2,'Changed GPS timestamp invalidates road cache');
$cachedCall->revision++;$engine->recommendationsForCall($cachedCall,$directory);
check(Engine::$routeRequests===$beforeCache+3,'Changed reviewed call revision invalidates road cache');
DB::table('drivers')->update(['location'=>null,'latitude'=>18,'longitude'=>82.02]);
$missingSpatial=callFixture($company);$engine->control(req($admin,startBody()),$missingSpatial,'start');
check(DB::table('mapa_dispatch_offers')->where('call_id',$missingSpatial)->count()===0,'Missing spatial fix never falls back to unverified legacy coordinates');
// A changed driver on an already attempted ambulance is not a replacement ambulance.
DB::table('drivers')->update(['location'=>json_encode([82.02,18])]);$changedCrewCall=callFixture($company);$engine->control(req($admin,startBody()),$changedCrewCall,'start');
$attempt=DB::table('mapa_dispatch_offers')->where('call_id',$changedCrewCall)->where('state','offered')->first();$vehicle=$attempt->vehicle_uuid;
$otherDriver=DB::table('drivers')->where('uuid','<>',$attempt->driver_uuid)->first();DB::table('drivers')->update(['status'=>'offline']);
DB::table('drivers')->where('uuid',$otherDriver->uuid)->update(['status'=>'available','vehicle_uuid'=>$vehicle]);
$engine->control(req($admin,startBody()),$changedCrewCall,'stop');$engine->control(req($admin,startBody()),$changedCrewCall,'start');
check(DB::table('mapa_dispatch_offers')->where('call_id',$changedCrewCall)->count()===1&&DB::table('mapa_dispatch_runs')->where('call_id',$changedCrewCall)->value('state')==='needs_operator','Resume never reoffers previously attempted ambulance after driver change');
$limitCall=callFixture($company);
DB::table('mapa_dispatch_runs')->insert(['call_id'=>$limitCall,'company_uuid'=>$company,'operator_uuid'=>$operator,'binding_id'=>$admin,'binding_revision'=>1,'call_revision'=>1,'state'=>'searching','created_at'=>now(),'updated_at'=>now()]);
foreach(range(1,\App\MapaVoice\AutomaticDispatchPolicy::MAX_OFFERS) as $n)DB::table('mapa_dispatch_offers')->insert(['id'=>(string)Str::uuid(),'company_uuid'=>$company,'call_id'=>$limitCall,'driver_uuid'=>(string)Str::uuid(),'vehicle_uuid'=>(string)Str::uuid(),'revision'=>1,'state'=>'overdue','road_seconds'=>60,'expires_at'=>now()->subMinute(),'created_at'=>now()->subMinutes(2),'updated_at'=>now()]);
$engine->tick();
check(DB::table('mapa_dispatch_runs')->where('call_id',$limitCall)->value('reason')==='top_five_exhausted'&&DB::table('mapa_dispatch_offers')->where('call_id',$limitCall)->where('state','cancelled')->count()===5,'Five unanswered offers stop, release reservations and require operator');
denied(fn()=>$engine->control(req($admin,startBody()),$limitCall,'start'),409,'Exhausted search cannot silently reoffer more than five crews');
// Production pilot boundary: the real engine (without the legacy test override)
// must not start another call, expose an excluded crew offer, or accept its reply.
$pilot=callFixture($company);$notPilot=callFixture($company);
$pilotCrew=array_slice(array_keys($crews),0,2);$excludedCrew=array_keys($crews)[2];
config(['mapa_voice.manual_enabled'=>true,'mapa_voice.automatic_dispatch_pilot_call_id'=>$pilot,
    'mapa_voice.automatic_dispatch_pilot_driver_uuids'=>implode(',',$pilotCrew)]);
$scoped=new AutomaticDispatch;
check(AutomaticDispatch::available(),'Pilot requires a valid exact call and driver allowlist');
$scoped->startReviewed(DB::table('mapa_voice_calls')->where('id',$notPilot)->first(),$staff);
check(!DB::table('mapa_dispatch_runs')->where('call_id',$notPilot)->exists(),'Other MAPA call cannot auto-start');
$scoped->startReviewed(DB::table('mapa_voice_calls')->where('id',$pilot)->first(),$staff);
check(DB::table('mapa_dispatch_runs')->where('call_id',$pilot)->value('state')==='searching','Exact pilot call can auto-start');
DB::table('mapa_dispatch_runs')->where('call_id',$pilot)->update(['state'=>'offering']);
$allowedDriver=DB::table('drivers')->where('uuid',$pilotCrew[0])->first();
$allowedOffer=(string)Str::uuid();
DB::table('mapa_dispatch_offers')->insert(['id'=>$allowedOffer,'company_uuid'=>$company,'call_id'=>$pilot,
    'driver_uuid'=>$allowedDriver->uuid,'vehicle_uuid'=>$allowedDriver->vehicle_uuid,'revision'=>1,
    'state'=>'offered','road_seconds'=>60,'expires_at'=>now()->addMinute(),'created_at'=>now(),'updated_at'=>now()]);
$allowedContext=(new App\MapaMobile\MobileService)->context(req($crews[$pilotCrew[0]]));
check(count($scoped->offersForContext($allowedContext))===1,'Only listed pilot crew can read its offer');
$pushJob=new App\MapaVoice\DeliverOfferPush($allowedOffer,$company,hash('sha256',$allowedOffer),time());
$currentPush=new ReflectionMethod($pushJob,'current');
check($currentPush->invoke($pushJob)?->id===$allowedOffer,'Queued push can wake exact pilot call and driver');
config(['mapa_voice.automatic_dispatch_pilot_call_id'=>$notPilot]);
check($currentPush->invoke($pushJob)===null,'Queued push cannot wake a call after the pilot ID changes');
config(['mapa_voice.automatic_dispatch_pilot_call_id'=>$pilot,
    'mapa_voice.automatic_dispatch_pilot_driver_uuids'=>implode(',',[$pilotCrew[1],$excludedCrew])]);
check($currentPush->invoke($pushJob)===null,'Queued push cannot wake a driver removed from the pilot');
config(['mapa_voice.automatic_dispatch_pilot_driver_uuids'=>implode(',',$pilotCrew)]);
$excludedDriver=DB::table('drivers')->where('uuid',$excludedCrew)->first();
$excludedOffer=(string)Str::uuid();
DB::table('mapa_dispatch_offers')->insert(['id'=>$excludedOffer,'company_uuid'=>$company,'call_id'=>$pilot,
    'driver_uuid'=>$excludedCrew,'vehicle_uuid'=>$excludedDriver->vehicle_uuid,'revision'=>1,
    'state'=>'offered','road_seconds'=>60,'expires_at'=>now()->addMinute(),'created_at'=>now(),'updated_at'=>now()]);
check($currentPush->invoke($pushJob)===null,'Queued push stops if pilot offer history includes an excluded driver');
$excludedContext=(new App\MapaMobile\MobileService)->context(req($crews[$excludedCrew]));
check($scoped->offersForContext($excludedContext)===[],'Excluded crew cannot read an accidentally persisted offer');
denied(fn()=>$scoped->respondOffer(req($crews[$excludedCrew],['action_id'=>(string)Str::uuid(),
    'expected_revision'=>1,'response'=>'accept']),$excludedOffer),409,'Excluded crew cannot accept an accidentally persisted offer');
check($scoped->offersForContext($allowedContext)===[],'Unexpected offer history hides even otherwise allowed offers');
$scoped->tick();
check(DB::table('mapa_dispatch_runs')->where('call_id',$pilot)->value('reason')==='pilot_scope_changed',
    'Worker stops a pilot run after an out-of-scope offer appears');
config(['mapa_voice.automatic_dispatch_pilot_driver_uuids'=>'']);
check(!AutomaticDispatch::available(),'Missing driver allowlist disables automatic dispatch');
$settingsMigration=require __DIR__.'/../migrations/2026_10_12_000001_create_mapa_dispatch_settings.php';$settingsMigration->up();
$scoped->tick();
$offSetting=DB::table('mapa_dispatch_settings')->where('company_uuid',$company)->first();
check($offSetting && !(bool)$offSetting->enabled && (int)$offSetting->revision===0 && $offSetting->last_tick_at!==null,
    'Healthy worker heartbeat is recorded while company automatic offers remain off');
check(!AutomaticDispatch::available() && DB::table('mapa_dispatch_runs')->where('call_id',$foreignCall)->value('state')==='searching',
    'Disabled heartbeat never starts offers or touches another company');
$settings=new App\MapaVoice\DispatchSettingsService;
$offStatus=$settings->showSettings(req($admin));
check(!$offStatus['enabled'] && $offStatus['worker_fresh'] && $offStatus['server_ready'],
    'Settings show a verified healthy worker before activation');
$onStatus=$settings->saveSettings(req($admin,['enabled'=>true,'expected_revision'=>0,'confirmed'=>true]));
check($onStatus['enabled'] && $onStatus['worker_fresh'] && (int)$onStatus['revision']===1,
    'Operator can enable only after a healthy scheduler tick');
DB::table('mapa_dispatch_settings')->where('company_uuid',$company)->update(['last_tick_at'=>null]);
check(!$settings->showSettings(req($admin))['server_ready'],
    'Settings do not claim readiness when an enabled worker stops ticking');
$staleCall=callFixture($company);
$scoped->startReviewed(DB::table('mapa_voice_calls')->where('id',$staleCall)->first(),$staff);
$staleRun=DB::table('mapa_dispatch_runs')->where('call_id',$staleCall)->first();
check($staleRun?->state==='needs_operator'&&$staleRun?->reason==='worker_stale'
    &&DB::table('mapa_dispatch_offers')->where('call_id',$staleCall)->count()===0,
    'Review during a worker outage escalates immediately without pretending to search or offering');
check(DB::table('mapa_voice_audit')->where(['call_id'=>$staleCall,'action'=>'automatic_escalated'])->count()===1,
    'Stale-worker review leaves an explicit operator audit');
denied(fn()=>$scoped->control(req($admin,startBody()),$staleCall,'start'),503,
    'Operator cannot restart automatic offers while the worker is stale');
$scoped->tick();
check(AutomaticDispatch::available(),'Enabled all-live company can process scoped runs');
$livePilot=callFixture($company);
DB::table('mapa_voice_calls')->where('id',$livePilot)->update(['draft'=>json_encode(['supervised_pilot'=>true])]);
$liveOrdinary=callFixture($company);
config(['mapa_voice.automatic_dispatch_pilot_call_id'=>$livePilot,
    'mapa_voice.automatic_dispatch_pilot_driver_uuids'=>implode(',',$pilotCrew)]);
check(App\MapaVoice\AutomaticDispatchScope::modeForCallId($livePilot)==='pilot'
    &&App\MapaVoice\AutomaticDispatchScope::allowsCallId($livePilot)
    &&App\MapaVoice\AutomaticDispatchScope::driverUuids($livePilot)===$pilotCrew,
    'Enabled all-live setting retains an exact two-crew exception for the marked drill');
check(App\MapaVoice\AutomaticDispatchScope::modeForCallId($liveOrdinary)==='all_live'
    &&App\MapaVoice\AutomaticDispatchScope::allowsCallId($liveOrdinary)
    &&App\MapaVoice\AutomaticDispatchScope::allowsDriverUuid($excludedCrew,$liveOrdinary),
    'Ordinary live calls retain all-live crew eligibility');
check(!App\MapaVoice\AutomaticDispatchScope::allowsDriverUuid($excludedCrew,$livePilot),
    'Third crew is excluded from the marked drill despite all-live setting');
$otherMarkedCall=callFixture($company);
DB::table('mapa_voice_calls')->where('id',$otherMarkedCall)->update(['draft'=>json_encode(['supervised_pilot'=>true])]);
check(!App\MapaVoice\AutomaticDispatchScope::allowsCallId($otherMarkedCall),
    'Another marked drill cannot enter automatic dispatch under all-live setting');
$scoped->startReviewed(DB::table('mapa_voice_calls')->where('id',$livePilot)->first(),$staff);
$scoped->startReviewed(DB::table('mapa_voice_calls')->where('id',$liveOrdinary)->first(),$staff);
check(DB::table('mapa_dispatch_runs')->where('call_id',$livePilot)->value('state')==='searching'
    &&DB::table('mapa_dispatch_runs')->where('call_id',$liveOrdinary)->value('state')==='searching',
    'Marked drill and ordinary call both start under enabled all-live setting');
DB::table('mapa_dispatch_runs')->where('call_id',$livePilot)->update(['state'=>'offering']);
$liveOffer=(string)Str::uuid();
DB::table('mapa_dispatch_offers')->insert(['id'=>$liveOffer,'company_uuid'=>$company,'call_id'=>$livePilot,
    'driver_uuid'=>$allowedDriver->uuid,'vehicle_uuid'=>$allowedDriver->vehicle_uuid,'revision'=>1,
    'state'=>'offered','road_seconds'=>60,'expires_at'=>now()->addMinute(),'created_at'=>now(),'updated_at'=>now()]);
$livePush=new App\MapaVoice\DeliverOfferPush($liveOffer,$company,hash('sha256',$liveOffer),time());
check(count($scoped->offersForContext($allowedContext))===1 && $currentPush->invoke($livePush)?->id===$liveOffer,
    'Listed crew can poll and receive the exact drill offer');
DB::table('mapa_mobile_bindings')->where('driver_uuid',$allowedDriver->uuid)->update(['enabled'=>false]);
check($scoped->offersForContext($allowedContext)===[] && $currentPush->invoke($livePush)===null,
    'Revoked listed crew cannot read or receive a queued drill offer');
DB::table('mapa_mobile_bindings')->where('driver_uuid',$allowedDriver->uuid)->update(['enabled'=>true]);
$excludedLiveOffer=(string)Str::uuid();
DB::table('mapa_dispatch_offers')->insert(['id'=>$excludedLiveOffer,'company_uuid'=>$company,'call_id'=>$livePilot,
    'driver_uuid'=>$excludedCrew,'vehicle_uuid'=>$excludedDriver->vehicle_uuid,'revision'=>1,
    'state'=>'offered','road_seconds'=>60,'expires_at'=>now()->addMinute(),'created_at'=>now(),'updated_at'=>now()]);
check($scoped->offersForContext($excludedContext)===[] && $currentPush->invoke($livePush)===null,
    'Third-crew history fails the live drill closed for polling and queued push');
config(['mapa_voice.automatic_dispatch_pilot_driver_uuids'=>$pilotCrew[0]]);
check(!App\MapaVoice\AutomaticDispatchScope::allowsCallId($livePilot)
    &&App\MapaVoice\AutomaticDispatchScope::allowsCallId($liveOrdinary)
    &&AutomaticDispatch::available(),
    'Incomplete drill scope disables only the drill while ordinary all-live stays enabled');
check(DB::table('mapa_dispatch_settings')->where('company_uuid',$company)->value('last_tick_at')!==null
    &&DB::table('mapa_dispatch_runs')->where('call_id',$foreignCall)->value('state')==='searching',
    'Enabled worker records successful scoped heartbeat without foreign mutation');
$settings->saveSettings(req($admin,['enabled'=>false,'expected_revision'=>1,'confirmed'=>true]));
DB::table('mapa_dispatch_settings')->where('company_uuid',$company)->update(['last_tick_at'=>now()->subMinute()]);
denied(fn()=>$settings->saveSettings(req($admin,['enabled'=>true,'expected_revision'=>2,'confirmed'=>true])),409,
    'Stale worker cannot enable all-live automatic offers');
config(['mapa_voice.automatic_dispatch_enabled'=>false]);
DB::table('mapa_dispatch_settings')->where('company_uuid',$company)->update(['last_tick_at'=>null]);
$scoped->tick();
check(DB::table('mapa_dispatch_settings')->where('company_uuid',$company)->value('last_tick_at')===null,
    'Disabled VPS master cannot claim a healthy automatic worker');
echo json_encode(['ok'=>true,'checks'=>count($checks),'cases'=>$checks,'database'=>'isolated_in_memory_sqlite','production_rows_written'=>0,'native_authentication_and_order_creation'=>'stubbed; dedicated native integration and MySQL concurrency still required'],JSON_PRETTY_PRINT)."\n";
}

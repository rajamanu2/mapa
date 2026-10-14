<?php
/** Actual native MySQL models and persisted rows in the newly created disposable QA database only. */
if(!isset($mapaDedicatedVoiceQaApp)||getenv('MAPA_VOICE_QA_ISOLATED')!=='1'||getenv('DB_DATABASE')!=='mapa_voice_qa'
    ||!preg_match('/^mapa-voice-qa-db-[a-f0-9]{8}$/D',(string)getenv('DB_HOST')))throw new RuntimeException('Fresh dedicated QA bootstrap required.');
if(!function_exists('pcntl_fork')||!function_exists('pcntl_waitpid'))throw new RuntimeException('Native concurrency suite requires pcntl; concurrency has NOT been verified.');
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
// Disposable-fixture diagnostics only. Never print request bodies, tokens or exception args.
set_exception_handler(function(Throwable $error){
    $depth=0;do{
        $message=preg_replace('/[a-f0-9]{64}/i','<qa-hash>',$error->getMessage());
        fwrite(STDERR,'QA_EXCEPTION_'.$depth.' '.get_class($error).': '.$message.' at '.$error->getFile().':'.$error->getLine().PHP_EOL);
        foreach($error->getTrace() as $frame)fwrite(STDERR,'  '.($frame['file']??'[internal]').':'.($frame['line']??0).' '.($frame['class']??'').($frame['type']??'').($frame['function']??'').PHP_EOL);
        $error=$error->getPrevious();$depth++;
    }while($error&&$depth<5);exit(1);
});
function qaProgress($phase){echo 'DEMO_QA_PHASE '.$phase.PHP_EOL;flush();}
qaProgress('module_load');
// Explicit module requirements preserve the installed application's native autoloader/models.
$mobile=dirname(__DIR__,2).'/MapaMobile';
foreach(['Policy','CallerPhone','TravelEvidence','TripWorkflow','MobileService'] as $file)require_once $mobile.'/'.$file.'.php';
foreach(['Policy','DraftExtractor','CrewEligibility','LocationSearch','AmbulanceDirectory','VoiceService','AutomaticDispatchPolicy','AutomaticDispatchScope','RecommendationService','AutomaticDispatch'] as $file)require_once __DIR__.'/../'.$file.'.php';
if(DB::getDriverName()!=='mysql'||DB::connection()->getDatabaseName()!=='mapa_voice_qa')throw new RuntimeException('QA MySQL connection required.');
if(Schema::hasTable('mapa_voice_calls')||DB::table('orders')->exists())throw new RuntimeException('Fresh unused native schema required.');
foreach(glob(__DIR__.'/../migrations/*.php') as $file)(require $file)->up();
qaProgress('automatic_schema_created');
foreach(['Http','Event','Queue','Bus','Mail','Notification'] as $facade){$class='Illuminate\\Support\\Facades\\'.$facade;$class::fake();}
Illuminate\Support\Facades\Redis::swap(new class {
    public function exists($key){if($key!=='countryData:IN')throw new RuntimeException('Unexpected QA Redis key');return true;}
    public function get($key){if($key!=='countryData:IN')throw new RuntimeException('Unexpected QA Redis key');return json_encode(['iso2'=>'IN','name'=>['common'=>'India']]);}
});
class NativeAutomaticQa extends App\MapaVoice\AutomaticDispatch {
    // Road adapter is separately tested; this suite verifies real native DB/auth/order atomicity.
    // Historical multi-call cases exercise the engine independently of the
    // single-call production pilot. The pilot intake case below uses the real
    // exact scope check in VoiceService::review().
    public static function available():bool{return config('mapa_voice.enabled')&&config('mapa_voice.automatic_dispatch_enabled')
        &&Schema::hasTable('mapa_dispatch_runs')&&Schema::hasTable('mapa_dispatch_offers');}
    protected function pilotCall(mixed $id):bool{return true;}
    protected function pilotDriver(mixed $uuid,mixed $callId=null):bool{return true;}
    protected function pilotDriverUuids(mixed $callId=null):array{return DB::table('drivers')->where('company_uuid',config('mapa.company_uuid'))->pluck('uuid')->all();}
    protected function roadSeconds(array $from,array $to):?int{return 60;}
    protected function notifyCrewAssigned(Fleetbase\FleetOps\Models\Order $order):void{}
}
class NativeAutomaticFailureQa extends NativeAutomaticQa {
    protected function createOrder(object $call,array $context,array $review,object $driver,object $vehicle,object $dropoff):string {
        parent::createOrder($call,$context,$review,$driver,$vehicle,$dropoff);
        throw new RuntimeException('DEMO injected failure after native reference validation');
    }
}
$qaChecks=[];
function qaCheck($condition,$name){global $qaChecks;if(!$condition)throw new RuntimeException('FAILED: '.$name);$qaChecks[]=$name;qaProgress('PASS '.$name);}
function qaRow($table,$values=[]){$id=(string)Illuminate\Support\Str::uuid();$base=['uuid'=>$id,'public_id'=>'DEMO_'.rtrim($table,'s').'_'.str_replace('-','',$id),'name'=>'DEMO isolated automatic QA','meta'=>json_encode(['mapa_demo'=>true,'simulated'=>true]),'created_at'=>now(),'updated_at'=>now()];$cols=array_column(DB::select("SHOW COLUMNS FROM `$table`"),'Field');DB::table($table)->insert(array_intersect_key([...$base,...$values],array_flip($cols)));return $id;}
function qaRequest($token,$body=[]){return Request::create('/qa','POST',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer '.$token],json_encode($body));}
function qaAction(){return ['action_id'=>(string)Illuminate\Support\Str::uuid(),'expected_revision'=>1,'confirmed'=>true];}
function qaDeny($callback,$code,$name){try{$callback();}catch(Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){qaCheck($e->getStatusCode()===$code,$name);return;}throw new RuntimeException('Expected denial: '.$name);}
function qaFresh(){DB::table('mapa_mobile_driver_states')->update(['recorded_at'=>(int)round(microtime(true)*1000)]);}
function qaDisconnectAll():void {
    // Schema's facade caches a builder/connection independently of DB's manager.
    // Drop both before fork so no inherited parent PDO survives a child shutdown.
    foreach(DB::getConnections() as $name=>$connection){
        if($connection->transactionLevel()!==0)throw new RuntimeException('Cannot fork with an open QA transaction');
        DB::purge($name);
    }
    Schema::clearResolvedInstance('db.schema');app()->forgetInstance('db.schema');gc_collect_cycles();
}
function qaParallel(array $jobs):array {
    qaProgress('parallel_start');$start=microtime(true)+0.35;$pids=[];$paths=[];$nonce=bin2hex(random_bytes(8));qaDisconnectAll();
    foreach($jobs as $index=>$job){$path='/tmp/mapa-automatic-qa-'.$nonce.'-'.$index.'.json';$pid=pcntl_fork();if($pid<0)throw new RuntimeException('Fork failed');
        if($pid===0){qaDisconnectAll();while(microtime(true)<$start)usleep(1000);try{$job();$result=['code'=>200];}
            catch(Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){$result=['code'=>$e->getStatusCode()];}
            catch(Throwable $e){$result=['code'=>500,'error_class'=>get_class($e),'error'=>$e->getMessage()];}
            file_put_contents($path,json_encode($result));qaDisconnectAll();exit(0);}
        $pids[]=$pid;$paths[]=$path;
    }
    $deadline=microtime(true)+30;foreach($pids as $pid){do{$done=pcntl_waitpid($pid,$status,WNOHANG);if($done===$pid)break;if(microtime(true)>$deadline){if(function_exists('posix_kill'))posix_kill($pid,15);throw new RuntimeException('QA race timed out');}usleep(10000);}while(true);}
    qaDisconnectAll();$out=[];foreach($paths as $path){if(!is_file($path))throw new RuntimeException('QA worker missing result');$out[]=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);unlink($path);}qaProgress('parallel_joined');return $out;
}
qaProgress('fixture_companies');
$company=qaRow('companies');$foreign=qaRow('companies');$pw='DEMO-QA-password-only';
config(['mapa.company_uuid'=>$company,'mapa_mobile.enabled'=>true,'mapa_mobile.push_enabled'=>false,'mapa_voice.enabled'=>true,'mapa_voice.demo_enabled'=>true,
    'mapa_voice.live_enabled'=>false,'mapa_voice.automatic_dispatch_enabled'=>true,'mapa_voice.automatic_offer_seconds'=>120,'mapa_voice.max_gps_age_seconds'=>180,
    'mapa_mobile.source_dispatch_coordination_enabled'=>false]);
$point=DB::raw("ST_GeomFromText('POINT(82.669 18.083)',0)");$tokens=[];$drivers=[];
foreach(['admin','crew1','crew2'] as $role){$user=qaRow('users',['company_uuid'=>$company,'email'=>$role.'@automatic-qa.invalid','password'=>Hash::make($pw),'status'=>'active','type'=>'user','email_verified_at'=>now()]);
    qaRow('company_users',['company_uuid'=>$company,'user_uuid'=>$user,'status'=>'active']);$driver=null;
    if($role!=='admin'){$vehicle=qaRow('vehicles',['company_uuid'=>$company,'online'=>1,'status'=>'active','location'=>$point,'latitude'=>18.083,'longitude'=>82.669]);
        $driver=qaRow('drivers',['company_uuid'=>$company,'user_uuid'=>$user,'vehicle_uuid'=>$vehicle,'online'=>1,'status'=>'available','location'=>$point,'latitude'=>18.083,'longitude'=>82.669]);
        DB::table('mapa_mobile_driver_states')->insert(['driver_uuid'=>$driver,'company_uuid'=>$company,'recorded_at'=>(int)round(microtime(true)*1000),'created_at'=>now(),'updated_at'=>now()]);$drivers[$role]=$driver;}
    DB::table('mapa_mobile_bindings')->insert(['id'=>(string)Illuminate\Support\Str::uuid(),'company_uuid'=>$company,'user_uuid'=>$user,'driver_uuid'=>$driver,'role'=>$role==='admin'?'admin':'crew','enabled'=>true,'revision'=>1,'created_at'=>now(),'updated_at'=>now()]);
    qaProgress('fixture_login_'.$role);$tokens[$role]=(new App\MapaMobile\MobileService)->login(qaRequest('', ['email'=>$role.'@automatic-qa.invalid','password'=>$pw]))['token'];
}
qaProgress('fixture_receiving_workflow');
$place=qaRow('places',['company_uuid'=>$company,'name'=>'DEMO QA receiving hospital','location'=>$point,'latitude'=>18.083,'longitude'=>82.669]);
$destination=DB::table('places')->where('uuid',$place)->value('public_id');qaRow('order_configs',['company_uuid'=>$company,'key'=>'transport','flow'=>json_encode(['dispatched'=>['complete'=>false]])]);
function qaCall($company,$destination,$provider='training'){$id=(string)Illuminate\Support\Str::uuid();DB::table('mapa_voice_calls')->insert(['id'=>$id,'company_uuid'=>$company,'provider'=>$provider,'provider_call_id'=>$id,'demo'=>true,'state'=>'ready','revision'=>1,'reviewed_at'=>now(),'review'=>json_encode(['latitude'=>18.09,'longitude'=>82.67,'pickup_address'=>'DEMO QA pickup','reported_problem'=>'DEMO QA fictional transport','vehicle_requirement'=>'any','priority'=>'routine','dropoff_id'=>$destination,'coordinates_confirmed'=>true]),'created_at'=>now(),'updated_at'=>now()]);return $id;}
function qaOffer($id){return DB::table('mapa_dispatch_offers')->where('call_id',$id)->where('state','offered')->first();}
function qaCrewToken($offer){global $tokens,$drivers;foreach($drivers as $role=>$driver)if($offer->driver_uuid===$driver)return $tokens[$role];throw new RuntimeException('QA crew missing');}
$engine=new NativeAutomaticQa;
qaDeny(fn()=>$engine->control(qaRequest('',qaAction()),qaCall($company,$destination),'start'),401,'Real native authentication rejects missing session');
qaDeny(fn()=>$engine->control(qaRequest($tokens['crew1'],qaAction()),qaCall($company,$destination),'start'),403,'Real crew cannot start dispatcher run');
qaDeny(fn()=>$engine->control(qaRequest($tokens['admin'],qaAction()),qaCall($foreign,$destination),'start'),404,'Foreign tenant call cannot dispatch');
qaDeny(fn()=>$engine->control(qaRequest($tokens['admin'],qaAction()),qaCall($company,$destination,'asrhealth'),'start'),409,'ASR source call refuses automatic dispatch');
qaFresh();$id=qaCall($company,$destination);$engine->control(qaRequest($tokens['admin'],qaAction()),$id,'start');$offer=qaOffer($id);qaCheck($offer!==null,'Real native eligibility creates offer without order');qaCheck(DB::table('orders')->count()===0,'Offer alone creates no native order');
$crew=qaCrewToken($offer);$accept=fn()=>['action_id'=>(string)Illuminate\Support\Str::uuid(),'expected_revision'=>1,'response'=>'accept'];
$firstAccept=$accept();$secondAccept=$accept();
$race=qaParallel([fn()=>(new NativeAutomaticQa)->respondOffer(qaRequest($crew,$firstAccept),$offer->id),fn()=>(new NativeAutomaticQa)->respondOffer(qaRequest($crew,$secondAccept),$offer->id)]);
$winner=$race[0]['code']===200?$firstAccept:$secondAccept;$codes=array_column($race,'code');sort($codes);qaCheck($codes===[200,409],'Concurrent acceptance permits exactly one winner');
qaCheck(DB::table('orders')->count()===1,'Concurrent native acceptance creates exactly one order');
qaCheck(($engine->respondOffer(qaRequest($crew,$winner),$offer->id)['replayed']??false)===true&&DB::table('orders')->count()===1,'Native winning acceptance retry replays without duplicate order');
$order=Fleetbase\FleetOps\Models\Order::withoutGlobalScopes()->where('uuid',DB::table('mapa_voice_calls')->where('id',$id)->value('order_uuid'))->first();
qaCheck($order&&$order->payload?->pickup&&$order->payload?->dropoff?->uuid===$place&&$order->trackingNumber&&$order->orderConfig,'Native order relations and tracking references validate');
DB::table('orders')->where('uuid',$order->uuid)->update(['status'=>'completed']);
qaFresh();$expired=qaCall($company,$destination);$engine->control(qaRequest($tokens['admin'],qaAction()),$expired,'start');$expiredOffer=qaOffer($expired);DB::table('mapa_dispatch_offers')->where('id',$expiredOffer->id)->update(['expires_at'=>now()->subSecond()]);$before=DB::table('orders')->count();$crew=qaCrewToken($expiredOffer);
$engine->tick();$replacement=qaOffer($expired);$replacementCrew=qaCrewToken($replacement);
qaCheck(DB::table('mapa_dispatch_offers')->where('id',$expiredOffer->id)->value('state')==='overdue'&&$replacement->id!==$expiredOffer->id,'Native deadline leaves overdue offer actionable and offers another ambulance');
$race=qaParallel([fn()=>(new NativeAutomaticQa)->respondOffer(qaRequest($crew,$accept()),$expiredOffer->id),fn()=>(new NativeAutomaticQa)->respondOffer(qaRequest($replacementCrew,$accept()),$replacement->id)]);
$codes=array_column($race,'code');sort($codes);qaCheck($codes===[200,409],'Overdue and replacement concurrent acceptance have exactly one winner');
qaCheck(DB::table('orders')->count()===$before+1&&DB::table('mapa_dispatch_offers')->where('call_id',$expired)->where('state','accepted')->count()===1&&DB::table('mapa_dispatch_offers')->where('call_id',$expired)->where('state','cancelled')->count()===1,'Cascade acceptance creates one native order and cancels other offer');
DB::table('orders')->where('uuid',DB::table('mapa_voice_calls')->where('id',$expired)->value('order_uuid'))->update(['status'=>'completed']);
qaFresh();$failed=qaCall($company,$destination);$engine->control(qaRequest($tokens['admin'],qaAction()),$failed,'start');$failedOffer=qaOffer($failed);$crew=qaCrewToken($failedOffer);
$counts=[];foreach(['orders','payloads','places','tracking_numbers','tracking_statuses','mapa_voice_receipts'] as $table)$counts[$table]=DB::table($table)->count();
try{(new NativeAutomaticFailureQa)->respondOffer(qaRequest($crew,$accept()),$failedOffer->id);throw new RuntimeException('Injected failure did not occur');}catch(RuntimeException $e){qaCheck(str_contains($e->getMessage(),'DEMO injected failure'),'Failure injected after actual native order reference validation');}
foreach($counts as $table=>$count)qaCheck(DB::table($table)->count()===$count,'Failure rolls back '.$table);
qaCheck(DB::table('mapa_dispatch_offers')->where('id',$failedOffer->id)->value('state')==='offered'&&DB::table('mapa_voice_calls')->where('id',$failed)->value('order_uuid')===null,'Failed acceptance keeps original offer and unassigned call');
$engine->control(qaRequest($tokens['admin'],qaAction()),$failed,'stop');
qaFresh();$declined=qaCall($company,$destination);$engine->control(qaRequest($tokens['admin'],qaAction()),$declined,'start');$declineOffer=qaOffer($declined);$crew=qaCrewToken($declineOffer);$decline=['action_id'=>(string)Illuminate\Support\Str::uuid(),'expected_revision'=>1,'response'=>'decline','decline_reason'=>'unavailable'];
$engine->respondOffer(qaRequest($crew,$decline),$declineOffer->id);$replacement=qaOffer($declined);
qaCheck($replacement&&$replacement->driver_uuid!==$declineOffer->driver_uuid,'Native decline offers next eligible crew');
qaCheck(($engine->respondOffer(qaRequest($crew,$decline),$declineOffer->id)['replayed']??false)===true&&DB::table('mapa_dispatch_offers')->where('call_id',$declined)->count()===2,'Native decline retry does not duplicate replacement offers');
$engine->control(qaRequest($tokens['admin'],qaAction()),$declined,'stop');
$metas=DB::table('drivers')->pluck('meta','uuid')->all();foreach($metas as $driver=>$meta)DB::table('drivers')->where('uuid',$driver)->update(['meta'=>json_encode([...json_decode($meta,true),'asr_import'=>['source_id'=>'DEMO']])]);
qaFresh();$shared=qaCall($company,$destination);$engine->control(qaRequest($tokens['admin'],qaAction()),$shared,'start');
qaCheck(DB::table('mapa_dispatch_offers')->where('call_id',$shared)->count()===0,'Real native ASR-shared drivers never receive automatic offers');
foreach($metas as $driver=>$meta)DB::table('drivers')->where('uuid',$driver)->update(['meta'=>$meta]);
qaFresh();$resumeAction=qaAction();$engine->control(qaRequest($tokens['admin'],$resumeAction),$shared,'start');
qaCheck(qaOffer($shared)!==null,'Explicit native retry resumes resource-exhausted run after new eligibility');
qaCheck(($engine->control(qaRequest($tokens['admin'],$resumeAction),$shared,'start')['replayed']??false)===true&&DB::table('mapa_dispatch_offers')->where('call_id',$shared)->count()===1,'Native resume retry replays one immutable offer');
$engine->control(qaRequest($tokens['admin'],qaAction()),$shared,'stop');
qaFresh();$a=qaCall($company,$destination);$b=qaCall($company,$destination);
$race=qaParallel([fn()=>(new NativeAutomaticQa)->control(qaRequest($tokens['admin'],qaAction()),$a,'start'),fn()=>(new NativeAutomaticQa)->control(qaRequest($tokens['admin'],qaAction()),$b,'start')]);
if(array_column($race,'code')!==[200,200])echo 'DEMO_QA_CONCURRENT_START_WORKERS '.json_encode($race,JSON_THROW_ON_ERROR).PHP_EOL;
qaCheck(array_column($race,'code')===[200,200],'Concurrent tenant starts serialize successfully');
if(!qaOffer($a)||!qaOffer($b)||qaOffer($a)->driver_uuid===qaOffer($b)->driver_uuid){
    echo 'DEMO_QA_CONCURRENT_START_DIAGNOSTIC '.json_encode(['workers'=>$race,'runs'=>DB::table('mapa_dispatch_runs')->whereIn('call_id',[$a,$b])->get(['call_id','state','reason'])->all(),
        'offers'=>DB::table('mapa_dispatch_offers')->whereIn('call_id',[$a,$b])->get(['call_id','driver_uuid','vehicle_uuid','state'])->all(),
        'all_pending'=>DB::table('mapa_dispatch_offers')->where('state','offered')->get(['call_id','driver_uuid','vehicle_uuid'])->all(),
        'drivers'=>DB::table('drivers')->get(['uuid','status','online','current_job_uuid'])->all(),'orders'=>DB::table('orders')->get(['uuid','status','driver_assigned_uuid','vehicle_assigned_uuid'])->all()],JSON_THROW_ON_ERROR).PHP_EOL;
}
qaCheck(qaOffer($a)&&qaOffer($b)&&qaOffer($a)->driver_uuid!==qaOffer($b)->driver_uuid,'Concurrent calls never reserve same crew or vehicle');
// A real-crew drill is recorded truthfully as an operational test, not as a
// caller-confirmed incident. This is still disposable isolated QA data.
config(['mapa_voice.manual_enabled'=>true,'mapa_voice.supervised_pilot_intake_enabled'=>false]);
$pilotIntake=['action_id'=>(string)Illuminate\Support\Str::uuid(),'demo'=>false,'supervised_pilot'=>true,
    'caller_name'=>'MAPA PILOT — NO PATIENT','callback_number'=>'',
    'reported_problem'=>'SUPERVISED MAPA PILOT — NO PATIENT. Crew offer and response drill.',
    'pickup_address'=>'DEMO QA Araku Railway Station test point','latitude'=>18.332922,'longitude'=>82.865258,
    'priority'=>'routine','vehicle_requirement'=>'any','pickup_confirmed_at_creation'=>false];
qaDeny(fn()=>$engine->mutate(qaRequest($tokens['admin'],$pilotIntake),'manual'),503,'Supervised pilot intake fails closed while disabled');
config(['mapa_voice.supervised_pilot_intake_enabled'=>true]);
qaDeny(fn()=>$engine->mutate(qaRequest($tokens['crew1'],$pilotIntake),'manual'),403,'Crew cannot create a supervised pilot');
foreach([['callback_number'=>'+919999999999'],['pickup_confirmed_at_creation'=>true],
    ['caller_name'=>'Real caller'],['reported_problem'=>'ordinary patient incident'],['demo'=>true]] as $unsafe){
    qaDeny(fn()=>$engine->mutate(qaRequest($tokens['admin'],[...$pilotIntake,...$unsafe,
        'action_id'=>(string)Illuminate\Support\Str::uuid()]),'manual'),422,'Unsafe pilot intake is rejected');
}
$pilot=$engine->mutate(qaRequest($tokens['admin'],$pilotIntake),'manual')['call'];
qaCheck($pilot['supervised_pilot']===true&&$pilot['demo']===false&&$pilot['caller']===''
    &&$pilot['draft']['coordinates_confirmed']===false&&$pilot['state']==='needs_review',
    'Pilot draft is visibly no-patient, has no caller and cannot assign a crew');
qaDeny(fn()=>$engine->extract(qaRequest($tokens['admin'],['action_id'=>(string)Illuminate\Support\Str::uuid(),
    'expected_revision'=>$pilot['revision']]),$pilot['id']),409,
    'Transcript extraction cannot erase the persisted no-patient pilot marker');
qaCheck((json_decode(DB::table('mapa_voice_calls')->where('id',$pilot['id'])->value('draft'),true)['supervised_pilot']??false)===true,
    'Rejected extraction leaves the pilot marker intact');
$pilotPlace=qaRow('places',['company_uuid'=>$company,'name'=>'DEMO QA live receiving hospital',
    'location'=>$point,'latitude'=>18.083,'longitude'=>82.669,'meta'=>json_encode(['mapa_demo'=>false,'isolated_test'=>true])]);
$pilotDestination=DB::table('places')->where('uuid',$pilotPlace)->value('public_id');
$pilotReview=['action_id'=>(string)Illuminate\Support\Str::uuid(),'expected_revision'=>$pilot['revision'],
    'confirmed'=>true,'supervised_pilot'=>true,'pilot_pickup_confirmed_by_operator'=>true,
    'coordinates_confirmed'=>false,'caller_name'=>'MAPA PILOT — NO PATIENT','callback_number'=>'',
    'reported_problem'=>'SUPERVISED MAPA PILOT — NO PATIENT. Crew offer and response drill.',
    'pickup_address'=>'DEMO QA Araku Railway Station test point','latitude'=>18.332922,'longitude'=>82.865258,
    'priority'=>'routine','vehicle_requirement'=>'any','dropoff_id'=>$pilotDestination];
qaDeny(fn()=>$engine->mutate(qaRequest($tokens['admin'],$pilotReview),'review',$pilot['id']),409,
    'Pilot review fails closed without exact call and two-crew scope');
config(['mapa_voice.automatic_dispatch_pilot_call_id'=>$pilot['id'],
    'mapa_voice.automatic_dispatch_pilot_driver_uuids'=>implode(',',array_values($drivers))]);
qaDeny(fn()=>$engine->mutate(qaRequest($tokens['admin'],[...$pilotReview,
    'action_id'=>(string)Illuminate\Support\Str::uuid(),'coordinates_confirmed'=>true]),'review',$pilot['id']),409,
    'Pilot cannot claim caller confirmation');
qaDeny(fn()=>$engine->mutate(qaRequest($tokens['admin'],[...$pilotReview,
    'action_id'=>(string)Illuminate\Support\Str::uuid(),'callback_number'=>'+919999999999']),'review',$pilot['id']),422,
    'Pilot review cannot add a caller number');
$reviewedPilot=$engine->mutate(qaRequest($tokens['admin'],$pilotReview),'review',$pilot['id'])['call'];
qaCheck($reviewedPilot['state']==='ready'&&$reviewedPilot['supervised_pilot']===true
    &&$reviewedPilot['review']['coordinates_confirmed']===false
    &&$reviewedPilot['review']['pilot_pickup_confirmed_by_operator']===true
    &&DB::table('mapa_dispatch_runs')->where('call_id',$pilot['id'])->value('state')==='searching',
    'Exactly scoped operator-confirmed test point starts the durable pilot run without claiming a caller');
$engine->control(qaRequest($tokens['admin'],[...qaAction(),'expected_revision'=>$reviewedPilot['revision']]),$pilot['id'],'stop');
qaDeny(fn()=>$engine->mutate(qaRequest($tokens['admin'],[...qaAction(),
    'expected_revision'=>$reviewedPilot['revision'],'driver_id'=>DB::table('drivers')->where('uuid',reset($drivers))->value('public_id')]),
    'dispatch',$pilot['id']),409,'Operator cannot bypass automatic offers with manual pilot dispatch');
qaCheck(Illuminate\Support\Facades\Http::recorded()->count()===0,'No external routing or source writes');
qaCheck(Illuminate\Support\Facades\Queue::pushedJobs()===[],'No queued real notifications');
echo json_encode(['ok'=>true,'checks'=>count($qaChecks),'cases'=>$qaChecks,'database'=>'fresh_disposable_native_mysql','authentication'=>'real_native_mobile','order_creation'=>'real_native_models_and_references','concurrency'=>'independent_forked_mysql_connections','road_adapter'=>'deterministic_fixture; private routing separately tested','production_rows_written'=>0,'notifications_sent'=>0],JSON_PRETTY_PRINT).PHP_EOL;


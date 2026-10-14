<?php
/** Isolated CLI integration: native TEMPORARY table shadows, no production rows.
 * Original native FK definitions are rechecked after every relevant write,
 * because MySQL TEMPORARY tables cannot themselves carry FK constraints. */
require '/fleetbase/api/vendor/autoload.php';
// The reviewed source tree uses ../mobile; the installed app uses ../MapaMobile.
$mobileDirectory=dirname(__DIR__,2).'/mobile';
if(!is_file($mobileDirectory.'/MobileService.php'))$mobileDirectory=dirname(__DIR__,2).'/MapaMobile';
if(!is_file($mobileDirectory.'/MobileService.php'))throw new RuntimeException('Adjacent reviewed MAPA mobile module was not found.');
foreach(['Policy','CallerPhone','TravelEvidence','TripWorkflow','MobileService'] as $f)require_once $mobileDirectory.'/'.$f.'.php';
// A dedicated run can freeze the service while another agent extends the live
// source. The snapshot contains code only and is selected only by the isolated QA flag.
$voiceStatusSnapshot=__DIR__.'/voice-status-current-qa.php';
foreach(['Policy','DraftExtractor','CrewEligibility','LocationSearch','AmbulanceDirectory','VoiceService','VoiceController'] as $f){
    if($f==='VoiceService' && getenv('MAPA_VOICE_QA_ISOLATED')==='1' && is_file($voiceStatusSnapshot)){
        if(!class_exists(\App\MapaVoice\VoiceService::class,false))require_once $voiceStatusSnapshot;
    }else require_once __DIR__.'/../'.$f.'.php';
}
$app=$mapaDedicatedVoiceQaApp??null;
if(!$app){$app=require '/fleetbase/api/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();}
set_exception_handler(function(\Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\MapaVoice\VoiceService;
use App\MapaVoice\Policy;
use App\MapaMobile\MobileService;
if(!app()->runningInConsole()||DB::getDriverName()!=='mysql')throw new RuntimeException('Isolated MySQL CLI connection required.');
Http::fake();Event::fake();Queue::fake();Bus::fake();Mail::fake();Notification::fake();
// The native country formatter hard-codes Redis. Keep its country cache local
// to this test; no existing Redis namespace or external geography lookup is used.
Illuminate\Support\Facades\Redis::swap(new class {
    public function exists(string $key): bool { if($key!=='countryData:IN')throw new RuntimeException('Unexpected isolated country cache key.'); return true; }
    public function get(string $key): string { if($key!=='countryData:IN')throw new RuntimeException('Unexpected isolated country cache key.'); return json_encode(['iso2'=>'IN','name'=>['common'=>'India']]); }
});
$tables=['users','companies','company_users','drivers','vehicles','places','orders','payloads','order_configs','tracking_numbers','tracking_statuses','positions','entities','waypoints','settings',
    'mapa_mobile_bindings','mapa_mobile_sessions','mapa_mobile_receipts','mapa_mobile_order_states','mapa_mobile_driver_states'];
$references=DB::select('SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL');
foreach($tables as $table){
    $ddl=(array)DB::selectOne("SHOW CREATE TABLE `$table`");$ddl=preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl['Create Table']);
    $ddl=implode("\n",array_filter(explode("\n",$ddl),fn($line)=>!preg_match('/^\s*CONSTRAINT /',$line)));$ddl=preg_replace('/,\s*\n\)/',"\n)",$ddl);DB::statement($ddl);
}
$migration=file_get_contents(__DIR__.'/../migrations/2026_09_18_000002_create_mapa_voice_tables.php');
$migration=preg_replace('/^<\?php/','',$migration);$migration=str_replace('function(Blueprint $t){','function(Blueprint $t){$t->temporary();',$migration);
(eval($migration))->up();
$fkChecks=0;
DB::listen(function($query)use($references,$tables,&$fkChecks){
    if(!preg_match('/^(insert into|update) `([^`]+)`/i',$query->sql,$m)||!in_array($m[2],$tables,true))return;
    foreach($references as $ref){
        if($ref->TABLE_NAME!==$m[2]||!in_array($ref->REFERENCED_TABLE_NAME,$tables,true))continue;
        $a=$ref->TABLE_NAME;$b=$ref->REFERENCED_TABLE_NAME;$col=$ref->COLUMN_NAME;$target=$ref->REFERENCED_COLUMN_NAME;
        $bad=DB::selectOne("SELECT count(*) n FROM `$a` a LEFT JOIN `$b` b ON a.`$col`=b.`$target` WHERE a.`$col` IS NOT NULL AND b.`$target` IS NULL");
        if($bad->n)throw new RuntimeException('Native FK violation '.$a.'.'.$col.' -> '.$b.'.'.$target);$fkChecks++;
    }
});
DB::beginTransaction();$checks=0;
function check($ok,string $msg):void{global $checks;if(!$ok)throw new RuntimeException($msg);$checks++;}
function denied(callable $fn,int $code):void{try{$fn();throw new RuntimeException('Expected denial '.$code);}catch(Illuminate\Validation\ValidationException $e){check($code===422,'Unexpected validation denial');}catch(Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){check($e->getStatusCode()===$code,'Unexpected denial '.$e->getStatusCode().': '.$e->getMessage());}}
function row(string $table,array $values=[]):string{
    $uuid=(string)Str::uuid();$base=['uuid'=>$uuid,'public_id'=>rtrim($table,'s').'_DEMO_'.str_replace('-','',$uuid),'name'=>'DEMO '.$table,'meta'=>json_encode(['mapa_demo'=>true,'simulated'=>true]),'created_at'=>now(),'updated_at'=>now()];
    $cols=array_column(DB::select("SHOW COLUMNS FROM `$table`"),'Field');DB::table($table)->insert(array_intersect_key([...$base,...$values],array_flip($cols)));return $uuid;
}
function voiceRequest(array $body=[],?string $token=null):Request{return Request::create('/test','POST',[],[],[],['CONTENT_TYPE'=>'application/json',...($token?['HTTP_AUTHORIZATION'=>'Bearer '.$token]:[])],json_encode($body));}
class IsolatedVoice extends VoiceService{
    // Native eligibility fixture has no external source receiver; policy has its own isolated suite.
    protected function sourceTripBlock(string $company,object $driver,object $vehicle): ?array {return null;}
    public array $assignmentAlerts=[];
    protected function company():string{return config('mapa.company_uuid');}
    protected function notifyCrewAssigned(\Fleetbase\FleetOps\Models\Order $native):void{
        $this->assignmentAlerts[]=['uuid'=>$native->uuid,'transaction_level'=>DB::transactionLevel(),'status'=>$native->status];
        // Exercise a failed optional alert dependency without queueing anything.
        // The native order, tracking references and receipt must still commit.
        throw new \RuntimeException('Isolated push scheduling failure');
    }
}
try{
    $company=row('companies');$foreign=row('companies');$secret=str_repeat('s',48);
    config(['mapa.company_uuid'=>$company,'mapa_mobile.enabled'=>true,'mapa_voice.enabled'=>true,'mapa_voice.demo_enabled'=>true,'mapa_voice.live_enabled'=>false,
        'mapa_voice.bridge_secret'=>$secret,'mapa_voice.exotel_account_sid'=>'DEMO-account','mapa_voice.max_gps_age_seconds'=>180,'mapa_voice.openai_key'=>'','mapa_voice.extraction_model'=>'']);
    $pw='DEMO-isolated-test-password';$tokens=[];$users=[];$bindings=[];
    foreach(['admin','operator','crew','hospital'] as $role){
        $users[$role]=row('users',['company_uuid'=>$company,'email'=>$role.'@voice-test.invalid','password'=>Hash::make($pw),'status'=>'active','type'=>'user','email_verified_at'=>now()]);
        row('company_users',['company_uuid'=>$company,'user_uuid'=>$users[$role],'status'=>'active']);
        $bindings[$role]=(string)Str::uuid();DB::table('mapa_mobile_bindings')->insert(['id'=>$bindings[$role],'company_uuid'=>$company,'user_uuid'=>$users[$role],'role'=>$role,'enabled'=>1,'revision'=>1,'created_at'=>now(),'updated_at'=>now()]);
    }
    $point=DB::raw("ST_GeomFromText('POINT(82.669 18.083)',0)");
    $fixtureMeta=json_encode(['mapa_demo'=>true,'simulated'=>true,'demo_dataset'=>'mapa-voice-demo-v1','mapa_ambulance_level'=>'bls']);
    $vehicle=row('vehicles',['company_uuid'=>$company,'public_id'=>'vehicle_mapa_voice_demo_01','name'=>'DEMO Voice Ambulance','status'=>'active','online'=>1,'location'=>$point,'latitude'=>18.083,'longitude'=>82.669,'meta'=>$fixtureMeta]);
    $driver=row('drivers',['company_uuid'=>$company,'public_id'=>'driver_mapa_voice_demo_01','user_uuid'=>$users['crew'],'vehicle_uuid'=>$vehicle,'online'=>1,'status'=>'available','location'=>$point,'latitude'=>18.083,'longitude'=>82.669,'meta'=>$fixtureMeta]);
    DB::table('mapa_mobile_bindings')->where('id',$bindings['crew'])->update(['driver_uuid'=>$driver]);
    $place=row('places',['company_uuid'=>$company,'name'=>'DEMO Hospital','location'=>$point,'latitude'=>18.083,'longitude'=>82.669]);
    DB::table('mapa_mobile_bindings')->where('id',$bindings['hospital'])->update(['place_uuid'=>$place]);
    row('order_configs',['company_uuid'=>$company,'key'=>'transport','flow'=>json_encode(['dispatched'=>['complete'=>false]])]);
    $mobile=new MobileService();foreach($users as $role=>$uuid)$tokens[$role]=$mobile->login(voiceRequest(['email'=>$role.'@voice-test.invalid','password'=>$pw]))['token'];
    $s=new IsolatedVoice();denied(fn()=>$s->index(voiceRequest()),401);denied(fn()=>$s->index(voiceRequest([],$tokens['crew'])),403);denied(fn()=>$s->index(voiceRequest([],$tokens['hospital'])),403);
    check($s->index(voiceRequest([],$tokens['operator']))['contract']===Policy::CONTRACT,'operator can read MAPA call queue');
    check((new MobileService)->snapshot($mobile->context(voiceRequest([],$tokens['operator'])))['orders']===[],'operator mobile snapshot does not expose broad native orders');
    denied(fn()=>$s->mutate(voiceRequest(['action_id'=>(string)Str::uuid()],$tokens['operator']),'demo'),403);
    config(['mapa_voice.geocoding_enabled'=>true]);
    denied(fn()=>$s->locations(voiceRequest(['q'=>'Paderu'],$tokens['crew'])),403);
    denied(fn()=>$s->nearby(voiceRequest(['latitude'=>18.088168,'longitude'=>82.667449],$tokens['crew'])),403);
    $pin=$s->nearby(voiceRequest(['latitude'=>18.088168,'longitude'=>82.667449],$tokens['admin']));
    check($pin['latitude']===18.088168&&is_array($pin['nearest']),'Admin pin lookup returns context without moving the pin');
    $lookup=$s->locations(voiceRequest(['q'=>'Paderu'],$tokens['admin']));
    check($lookup['contract']===Policy::CONTRACT&&count($lookup['results'])>0,'authenticated place search includes the operator contract');
    $demoBody=['action_id'=>(string)Str::uuid()];$demo=$s->mutate(voiceRequest($demoBody,$tokens['admin']),'demo');$call=$demo['call'];
    check($call['demo']&&$call['state']==='needs_review','demo draft only');check($s->mutate(voiceRequest($demoBody,$tokens['admin']),'demo')['replayed'],'demo idempotency');check(DB::table('mapa_voice_calls')->count()===1,'one demo call');
    $detail=$s->show(voiceRequest([],$tokens['admin']),$call['id']);check($detail['candidates']===[],'unreviewed call has no candidates');
    $placeId=DB::table('places')->where('uuid',$place)->value('public_id');
    $review=['action_id'=>(string)Str::uuid(),'expected_revision'=>$call['revision'],'confirmed'=>true,'caller_name'=>'DEMO Caller','callback_number'=>'',
        'reported_problem'=>'DEMO transport test','pickup_address'=>'DEMO Paderu training pickup','landmark'=>'DEMO point','latitude'=>18.09,'longitude'=>82.67,
        'priority'=>'routine','vehicle_requirement'=>'bls','dropoff_id'=>$placeId,'consciousness'=>'awake','breathing'=>'unknown','scene_safety'=>'unsafe'];
    denied(fn()=>$s->mutate(voiceRequest([...$review,'confirmed'=>false],$tokens['admin']),'review',$call['id']),422);
    $ready=$s->mutate(voiceRequest($review,$tokens['admin']),'review',$call['id']);check(count($ready['candidates'])===1,'eligible DEMO ambulance');check($ready['candidates'][0]['simulated'],'simulated fix label');
    check($ready['candidates'][0]['distance_basis']==='straight_line_not_road_eta','distance not ETA');check($s->mutate(voiceRequest($review,$tokens['admin']),'review',$call['id'])['replayed'],'review replay');
    denied(fn()=>$s->mutate(voiceRequest([...$review,'action_id'=>(string)Str::uuid()],$tokens['admin']),'review',$call['id']),409);
    // The native model's canonical persisted status must remain eligible. This
    // uses the real model and service against isolated temporary native tables.
    $nativeVehicle=new \Fleetbase\FleetOps\Models\Vehicle;
    $nativeVehicle->setAttribute('status','active');
    check($nativeVehicle->getAttributes()['status']==='available','native Vehicle canonicalizes legacy active to available');
    DB::table('vehicles')->where('uuid',$vehicle)->update(['status'=>$nativeVehicle->getAttributes()['status']]);
    check(count($s->show(voiceRequest([],$tokens['admin']),$call['id'])['candidates'])===1,'canonical native available ambulance remains eligible with actual crew duty and fresh GPS');
    foreach(['maintenance','unavailable','out_of_service','inactive','dispatched','',null] as $unavailableStatus){
        DB::table('vehicles')->where('uuid',$vehicle)->update(['status'=>$unavailableStatus]);
        $availability=$s->show(voiceRequest([],$tokens['admin']),$call['id']);
        check($availability['candidates']===[]&&$availability['unavailable'][0]['code']==='off_duty','nonavailable or missing native vehicle status remains excluded');
    }
    DB::table('vehicles')->where('uuid',$vehicle)->update(['status'=>'available','online'=>0]);
    $availability=$s->show(voiceRequest([],$tokens['admin']),$call['id']);
    check($availability['candidates']===[]&&$availability['unavailable'][0]['code']==='off_duty','available status cannot bypass ambulance offline state');
    DB::table('vehicles')->where('uuid',$vehicle)->update(['online'=>1]);
    DB::table('drivers')->where('uuid',$driver)->update(['online'=>0]);
    $availability=$s->show(voiceRequest([],$tokens['admin']),$call['id']);
    check($availability['candidates']===[]&&$availability['unavailable'][0]['code']==='off_duty','available ambulance cannot bypass crew offline state');
    DB::table('drivers')->where('uuid',$driver)->update(['online'=>1,'status'=>'busy']);
    $availability=$s->show(voiceRequest([],$tokens['admin']),$call['id']);
    check($availability['candidates']===[]&&$availability['unavailable'][0]['code']==='off_duty','available ambulance cannot bypass unavailable crew status');
    DB::table('drivers')->where('uuid',$driver)->update(['status'=>'available']);
    DB::table('mapa_mobile_bindings')->where('id',$bindings['crew'])->update(['enabled'=>0]);
    $availability=$s->show(voiceRequest([],$tokens['admin']),$call['id']);
    check($availability['candidates']===[]&&$availability['unavailable'][0]['code']==='crew_access','available ambulance still requires an enabled crew binding');
    DB::table('mapa_mobile_bindings')->where('id',$bindings['crew'])->update(['enabled'=>1]);
    DB::table('company_users')->where('user_uuid',$users['crew'])->update(['status'=>'inactive']);
    $availability=$s->show(voiceRequest([],$tokens['admin']),$call['id']);
    check($availability['candidates']===[]&&$availability['unavailable'][0]['code']==='crew_access','available ambulance still requires active company membership');
    DB::table('company_users')->where('user_uuid',$users['crew'])->update(['status'=>'active']);
    DB::table('vehicles')->where('uuid',$vehicle)->update(['company_uuid'=>$foreign]);
    check($s->show(voiceRequest([],$tokens['admin']),$call['id'])['candidates']===[],'available status never admits an ambulance from another tenant');
    DB::table('vehicles')->where('uuid',$vehicle)->update(['company_uuid'=>$company]);
    DB::table('drivers')->where('uuid',$driver)->update(['company_uuid'=>$foreign]);
    check($s->show(voiceRequest([],$tokens['admin']),$call['id'])['candidates']===[],'available status never admits crew from another tenant');
    DB::table('drivers')->where('uuid',$driver)->update(['company_uuid'=>$company]);
    // Native GPS writes the spatial Point; legacy scalar columns can remain null.
    DB::table('drivers')->where('uuid',$driver)->update(['latitude'=>null,'longitude'=>null]);
    $spatialAvailability=$s->show(voiceRequest([],$tokens['admin']),$call['id']);
    check(count($spatialAvailability['candidates'])===1,'fresh native Point remains eligible with null legacy scalar columns');
    DB::table('drivers')->where('uuid',$driver)->update(['location'=>DB::raw("ST_GeomFromText('POINT(0 0)', 0)")]);
    $availability=$s->show(voiceRequest([],$tokens['admin']),$call['id']);
    check($availability['candidates']===[]&&$availability['unavailable'][0]['code']==='gps_missing','available ambulance still requires a valid crew coordinate');
    DB::table('drivers')->where('uuid',$driver)->update(['location'=>$point]);
    DB::table('mapa_mobile_driver_states')->where('driver_uuid',$driver)->update(['recorded_at'=>(int)round(microtime(true)*1000)+60000]);
    $availability=$s->show(voiceRequest([],$tokens['admin']),$call['id']);
    check($availability['candidates']===[]&&$availability['unavailable'][0]['code']==='gps_stale','available ambulance still rejects a future-dated GPS fix');
    DB::table('mapa_mobile_driver_states')->where('driver_uuid',$driver)->update(['recorded_at'=>(int)round(microtime(true)*1000)]);

    DB::table('mapa_mobile_driver_states')->where('driver_uuid',$driver)->update(['recorded_at'=>1]);check($s->show(voiceRequest([],$tokens['admin']),$call['id'])['candidates']===[],'stale GPS excluded');check($s->show(voiceRequest([],$tokens['admin']),$call['id'])['unavailable'][0]['code']==='gps_stale','Dispatcher sees why stale GPS is excluded');
    DB::table('mapa_mobile_driver_states')->where('driver_uuid',$driver)->update(['recorded_at'=>(int)round(microtime(true)*1000)]);
    DB::table('vehicles')->where('uuid',$vehicle)->update(['meta'=>json_encode(['mapa_demo'=>false,'mapa_ambulance_level'=>'bls'])]);check($s->show(voiceRequest([],$tokens['admin']),$call['id'])['candidates']===[],'mixed live resource rejected');
    DB::table('vehicles')->where('uuid',$vehicle)->update(['meta'=>$fixtureMeta]);
    DB::table('users')->where('uuid',$users['crew'])->update(['meta'=>json_encode(['mapa_demo'=>true,'password_reset_required'=>true])]);
    check($s->show(voiceRequest([],$tokens['admin']),$call['id'])['candidates']===[],'crew requiring security action is excluded');check($s->show(voiceRequest([],$tokens['admin']),$call['id'])['unavailable'][0]['code']==='crew_access','Crew access blocker is explained without exposing security details');
    DB::table('users')->where('uuid',$users['crew'])->update(['meta'=>json_encode(['mapa_demo'=>true])]);
    $dispatch=['action_id'=>(string)Str::uuid(),'expected_revision'=>$ready['call']['revision'],'confirmed'=>true,'driver_id'=>'driver_mapa_voice_demo_01'];
    $result=$s->mutate(voiceRequest($dispatch,$tokens['admin']),'dispatch',$call['id']);check($result['call']['state']==='dispatched','native dispatch succeeds');
    check(DB::table('vehicles')->where('uuid',$vehicle)->value('status')==='available','dispatch revalidation accepts canonical native available status without changing it');
    check(count($s->assignmentAlerts)===1&&$s->assignmentAlerts[0]['transaction_level']>0&&$s->assignmentAlerts[0]['status']==='dispatched','alert hook sees the validated native assignment inside the transaction; scheduling failure cannot undo dispatch');
    check($s->mutate(voiceRequest($dispatch,$tokens['admin']),'dispatch',$call['id'])['replayed'],'dispatch exact retry');check(DB::table('orders')->count()===1,'one native order');
    check(count($s->assignmentAlerts)===1,'exact dispatch retry does not enqueue a second assignment alert');
    check(DB::table('tracking_numbers')->first()->status_uuid===DB::table('tracking_statuses')->first()->uuid,'native circular FK references resolve');
    $snap=$mobile->snapshot($mobile->context(voiceRequest([],$tokens['crew'])));check(count($snap['orders'])===1&&$snap['orders'][0]['id']===$result['order_id'],'crew sees same native dispatch');check($snap['orders'][0]['can_acknowledge'],'crew may acknowledge');
    check($snap['orders'][0]['dispatch_details']['scene_safety']==='unsafe'&&$snap['orders'][0]['dispatch_details']['consciousness']==='awake','caller-reported hazards and observations reach assigned crew');
    $mobile->mutate(voiceRequest(['action_id'=>(string)Str::uuid(),'expected_revision'=>$snap['orders'][0]['revision']],$tokens['crew']),'ack',$result['order_id']);
    check($s->show(voiceRequest([],$tokens['admin']),$call['id'])['call']['acknowledged'],'voice panel sees actual crew acknowledgement');
    $second=$s->mutate(voiceRequest(['action_id'=>(string)Str::uuid()],$tokens['admin']),'demo')['call'];
    $secondReady=$s->mutate(voiceRequest([...$review,'action_id'=>(string)Str::uuid(),'expected_revision'=>$second['revision']],$tokens['admin']),'review',$second['id']);check($secondReady['candidates']===[],'busy ambulance excluded');check($secondReady['unavailable'][0]['code']==='busy'&&$secondReady['unavailable'][0]['order_id']===$result['order_id'],'Busy reason identifies the existing assignment without changing it');
    denied(fn()=>$s->mutate(voiceRequest([...$dispatch,'action_id'=>(string)Str::uuid(),'expected_revision'=>$secondReady['call']['revision']],$tokens['admin']),'dispatch',$second['id']),409);
    check(DB::table('orders')->count()===1,'busy race recheck prevents second assignment');
    $evt=['event_id'=>(string)Str::uuid(),'event_type'=>'stream.started','account_sid'=>'DEMO-account','call_sid'=>'DEMO-provider-call','stream_sid'=>'DEMO-stream','sequence'=>1,'occurred_at'=>gmdate('c'),'demo'=>true,'payload'=>['caller'=>'','to'=>'']];
    denied(fn()=>$s->bridge(voiceRequest($evt)),401);$ingest=$s->bridge(voiceRequest($evt,$secret));check($s->bridge(voiceRequest($evt,$secret))['replayed'],'bridge exact replay');
    denied(fn()=>$s->bridge(voiceRequest([...$evt,'sequence'=>2],$secret)),409);
    $text=[...$evt,'event_id'=>(string)Str::uuid(),'event_type'=>'transcript.final','sequence'=>2,'payload'=>['text'=>'DEMO provider transcript','draft'=>['pickup_address'=>'DEMO suggestion','latitude'=>19,'priority'=>'emergency']]];
    $s->bridge(voiceRequest($text,$secret));$bridgeCall=$s->show(voiceRequest([],$tokens['admin']),$ingest['call_id'])['call'];check(!isset($bridgeCall['draft']['latitude'])&&!isset($bridgeCall['draft']['priority']),'provider cannot propose authoritative clinical/coordinate fields');
    $bridgeReview=$s->mutate(voiceRequest([...$review,'action_id'=>(string)Str::uuid(),'expected_revision'=>$bridgeCall['revision']],$tokens['admin']),'review',$bridgeCall['id']);
    $s->bridge(voiceRequest([...$text,'event_id'=>(string)Str::uuid(),'sequence'=>3,'payload'=>['text'=>'DEMO later correction']],$secret));check($s->show(voiceRequest([],$tokens['admin']),$bridgeCall['id'])['call']['state']==='needs_review','later transcript invalidates review');
    $s->bridge(voiceRequest([...$evt,'event_id'=>(string)Str::uuid(),'event_type'=>'stream.failed','sequence'=>4,'payload'=>['reason'=>'operator_requested']],$secret));
    denied(fn()=>$s->bridge(voiceRequest([...$evt,'event_id'=>(string)Str::uuid(),'sequence'=>1],$secret)),409);
    $s->bridge(voiceRequest([...$evt,'event_id'=>(string)Str::uuid(),'sequence'=>0],$secret));check($s->show(voiceRequest([],$tokens['admin']),$bridgeCall['id'])['call']['provider_status']==='failed','reordered start cannot reopen terminal stream');
    denied(fn()=>$s->bridge(voiceRequest([...$evt,'event_id'=>(string)Str::uuid(),'call_sid'=>'live-test','demo'=>false],$secret)),503);
    $terminal=$s->mutate(voiceRequest(['action_id'=>(string)Str::uuid(),'expected_revision'=>$secondReady['call']['revision'],'reason'=>'DEMO ended'],$tokens['admin']),'dismiss',$second['id']);check($terminal['call']['state']==='dismissed','dismissed terminal');
    denied(fn()=>$s->mutate(voiceRequest([...$review,'action_id'=>(string)Str::uuid(),'expected_revision'=>$terminal['call']['revision']],$tokens['admin']),'review',$second['id']),409);
    DB::table('mapa_voice_calls')->where('id',$second['id'])->update(['company_uuid'=>$foreign]);denied(fn()=>$s->show(voiceRequest([],$tokens['admin']),$second['id']),404);
    // Manual call entry does not enable provider ingestion or auto-dispatch.
    $directory=['hospitals'=>[['id'=>'DEMO-A','name'=>'DEMO PHC A'],['id'=>'DEMO-B','name'=>'DEMO PHC B']],
        'secretariats'=>[['id'=>'DEMO-S','phc_id'=>'DEMO-A','name'=>'DEMO Secretariat']],
        'villages'=>[['id'=>'DEMO-V','secretariat_id'=>'DEMO-S','name'=>'DEMO Village']]];
    $geo=['origin_phc_id'=>'DEMO-A','origin_secretariat_id'=>'DEMO-S','origin_village_id'=>'DEMO-V','destination_phc_id'=>'DEMO-B','origin_phc_name'=>'unverified label'];
    check(\App\MapaVoice\SourceIntakeDirectory::against($geo,$directory)['origin_phc_name']==='DEMO PHC A','verified hierarchy overrides forged source labels');
    denied(fn()=>\App\MapaVoice\SourceIntakeDirectory::against([...$geo,'origin_phc_id'=>'DEMO-B'],$directory),422);
    denied(fn()=>\App\MapaVoice\SourceIntakeDirectory::against([...$geo,'origin_secretariat_id'=>null],$directory),422);
    denied(fn()=>\App\MapaVoice\SourceIntakeDirectory::against([...$geo,'destination_phc_id'=>'DEMO-FOREIGN'],$directory),422);
    $manual=['action_id'=>(string)Str::uuid(),'demo'=>true,'reported_problem'=>'DEMO operator-entered transport','pickup_address'=>'DEMO Paderu',
        'village'=>'Paderu','mandal'=>'Paderu','patient_count'=>1,'breathing'=>'unknown','scene_safety'=>'unknown',
        'patient_name'=>'DEMO patient','patient_age'=>0,'patient_gender'=>'Female','incident_type'=>'Referral Transfer','intake_priority'=>'urgent','dispatcher_notes'=>'DEMO handover',
        'access_directions'=>'DEMO training approach','latitude'=>18.09,'longitude'=>82.67,'coordinates_confirmed'=>true];
    config(['mapa_voice.manual_enabled'=>false]);denied(fn()=>$s->mutate(voiceRequest($manual,$tokens['admin']),'manual'),503);
    config(['mapa_voice.manual_enabled'=>true]);
    denied(fn()=>$s->mutate(voiceRequest($manual,$tokens['crew']),'manual'),403);
    $manualCall=$s->mutate(voiceRequest($manual,$tokens['admin']),'manual')['call'];
    check($manualCall['source']==='manual'&&$manualCall['state']==='needs_review'&&$manualCall['order_id']===null,'manual entry is draft only');
    check($manualCall['draft']['coordinates_confirmed']===false,'draft cannot self-confirm a pickup pin');
    check($manualCall['draft']['patient_age']===0&&$manualCall['draft']['incident_type']==='Referral Transfer'&&$manualCall['draft']['dispatcher_notes']==='DEMO handover','ASR intake fields persist in private manual draft');
    foreach(['patient_age'=>121,'patient_gender'=>'unlisted','incident_type'=>'unlisted'] as $key=>$bad)denied(fn()=>$s->mutate(voiceRequest([...$manual,'action_id'=>(string)Str::uuid(),$key=>$bad],$tokens['admin']),'manual'),422);
    check($s->mutate(voiceRequest($manual,$tokens['admin']),'manual')['replayed'],'manual create retry is idempotent');
    check($manualCall['draft']['priority']==='urgent'&&$manualCall['draft']['vehicle_requirement']==='any','intake priority fallback and unconstrained vehicle requirement persist');
    foreach(['routine'=>'any','urgent'=>'bls','emergency'=>'als'] as $priority=>$requirement){
        $intakeBody=[...$manual,'action_id'=>(string)Str::uuid(),'priority'=>$priority,'vehicle_requirement'=>$requirement,
            'pickup_confirmed_at_creation'=>true,'pickup_confirmed_at_creation_by'=>$users['crew']];
        $intakeCall=$s->mutate(voiceRequest($intakeBody,$tokens['admin']),'manual')['call'];
        $storedIntake=DB::table('mapa_voice_calls')->where('id',$intakeCall['id'])->first();$storedDraft=json_decode($storedIntake->draft,true);
        check($storedDraft['priority']===$priority&&$storedDraft['vehicle_requirement']===$requirement,'validated intake priority and vehicle requirement roundtrip through MySQL');
        check($storedDraft['pickup_confirmed_at_creation']===true&&$storedDraft['pickup_confirmed_at_creation_by']===$users['admin'],'pickup confirmation actor is authenticated operator, never a submitted actor');
        check($storedIntake->state==='needs_review'&&$storedIntake->order_uuid===null&&$storedDraft['coordinates_confirmed']===false&&$storedIntake->review===null,'creation confirmation preserves independent review gate and cannot create a native assignment');
        check($s->show(voiceRequest([],$tokens['admin']),$intakeCall['id'])['candidates']===[],'creation confirmation does not make dispatch candidates eligible');
        $nativeCount=DB::table('orders')->count();
        denied(fn()=>$s->mutate(voiceRequest(['action_id'=>(string)Str::uuid(),'expected_revision'=>$intakeCall['revision'],'confirmed'=>true,'driver_id'=>'driver_mapa_voice_demo_01'],$tokens['admin']),'dispatch',$intakeCall['id']),422);
        check(DB::table('orders')->count()===$nativeCount,'unreviewed confirmed intake cannot bypass dispatch into a native order');
    }
    $defaultBody=[...$manual,'action_id'=>(string)Str::uuid(),'intake_priority'=>null,'priority'=>null,'vehicle_requirement'=>null,'pickup_confirmed_at_creation'=>false,'pickup_confirmed_at_creation_by'=>$users['crew']];
    $defaultCall=$s->mutate(voiceRequest($defaultBody,$tokens['admin']),'manual')['call'];
    check($defaultCall['draft']['priority']==='routine'&&$defaultCall['draft']['vehicle_requirement']==='any','unset intake planning values use explicit safe defaults');
    check($defaultCall['draft']['pickup_confirmed_at_creation']===false&&!isset($defaultCall['draft']['pickup_confirmed_at_creation_by']),'unconfirmed intake never stores supplied confirmation actor');
    $invalidIntakeCount=DB::table('mapa_voice_calls')->count();
    foreach([['priority'=>'critical'],['vehicle_requirement'=>'icu'],['pickup_confirmed_at_creation'=>'yes'],
        ['pickup_confirmed_at_creation'=>true,'pickup_address'=>null],['pickup_confirmed_at_creation'=>true,'pickup_address'=>'   '],
        ['pickup_confirmed_at_creation'=>true,'latitude'=>null],['pickup_confirmed_at_creation'=>true,'longitude'=>null]] as $invalid)
        denied(fn()=>$s->mutate(voiceRequest([...$manual,...$invalid,'action_id'=>(string)Str::uuid()],$tokens['admin']),'manual'),422);
    check(DB::table('mapa_voice_calls')->count()===$invalidIntakeCount,'invalid planning values and incomplete pickup confirmation persist no calls');
    $maxDraft=$s->mutate(voiceRequest([...$manual,'action_id'=>(string)Str::uuid(),'reported_problem'=>str_repeat('x',2000)],$tokens['admin']),'manual')['call'];
    check(mb_strlen($maxDraft['draft']['reported_problem'])===2000,'DEMO label respects field size');
    denied(fn()=>$s->mutate(voiceRequest([...$manual,'patient_count'=>3],$tokens['admin']),'manual'),409);
    $manualReview=[...$review,'action_id'=>(string)Str::uuid(),'expected_revision'=>$manualCall['revision'],'village'=>'Paderu','patient_count'=>1,'access_directions'=>'DEMO training approach',
        'patient_name'=>'DEMO patient','patient_age'=>0,'patient_gender'=>'Female','incident_type'=>'Referral Transfer','intake_priority'=>'urgent','origin_phc_name'=>'DEMO PHC','origin_secretariat_name'=>'DEMO secretariat','destination_facility_name'=>'DEMO receiving facility','dispatcher_notes'=>'DEMO handover'];
    denied(fn()=>$s->mutate(voiceRequest($manualReview,$tokens['admin']),'review',$manualCall['id']),422);
    $manualReady=$s->mutate(voiceRequest([...$manualReview,'coordinates_confirmed'=>true],$tokens['admin']),'review',$manualCall['id']);
    check($manualReady['call']['supervised_pilot']===false
        &&$manualReady['call']['review']['coordinates_confirmed']===true,
        'Ordinary reviewed call keeps its original caller-confirmed pickup contract');
    check($manualReady['call']['review']['patient_count']==1&&$manualReady['call']['review']['access_directions']==='DEMO training approach','review retains operational handover details');
    check($manualReady['call']['review']['patient_age']===0&&$manualReady['call']['review']['incident_type']==='Referral Transfer'&&$manualReady['call']['review']['dispatcher_notes']==='DEMO handover','review retains ASR intake and neonatal age zero');
    $liveManual=$s->mutate(voiceRequest([...$manual,'action_id'=>(string)Str::uuid(),'demo'=>false],$tokens['admin']),'manual')['call'];
    check(!$liveManual['demo']&&$liveManual['source']==='manual','manual real intake independent of disabled Exotel');
    config(['mapa_voice.demo_enabled'=>false]);
    $liveQueue=$s->index(voiceRequest([],$tokens['admin']));
    check($liveQueue['capabilities']['demo']===false,'training capability is disabled');
    check(count($liveQueue['calls'])===1&&$liveQueue['calls'][0]['id']===$liveManual['id'],'operational queue excludes training records without deleting them');
    $rowsBefore=DB::table('mapa_voice_calls')->count();
    denied(fn()=>$s->mutate(voiceRequest(['action_id'=>(string)Str::uuid()],$tokens['admin']),'demo'),503);
    denied(fn()=>$s->mutate(voiceRequest([...$manual,'action_id'=>(string)Str::uuid()],$tokens['admin']),'manual'),503);
    check(DB::table('mapa_voice_calls')->count()===$rowsBefore,'disabled training requests create no records');
    denied(fn()=>$s->bridge(voiceRequest([...$evt,'event_id'=>(string)Str::uuid(),'call_sid'=>'live-still-disabled','demo'=>false],$secret)),503);
    config(['mapa_voice.manual_enabled'=>false]);
    denied(fn()=>$s->mutate(voiceRequest(['action_id'=>(string)Str::uuid(),'expected_revision'=>$liveManual['revision'],'reason'=>'DEMO isolated test'],$tokens['admin']),'dismiss',$liveManual['id']),503);
    config(['mapa_voice.manual_enabled'=>true]);
    // Exercise the real manual branch only in these connection-local shadows.
    // DEMO names stay explicit; resource metadata models live eligibility solely
    // for this test. Nothing is inserted into a production table or notified.
    $liveMeta=json_encode(['mapa_demo'=>false,'isolated_test'=>true,'mapa_ambulance_level'=>'bls']);
    DB::table('orders')->where('public_id',$result['order_id'])->update(['status'=>'completed']);
    DB::table('drivers')->where('uuid',$driver)->update(['meta'=>$liveMeta]);
    DB::table('vehicles')->where('uuid',$vehicle)->update(['meta'=>$liveMeta]);
    DB::table('mapa_mobile_driver_states')->where('driver_uuid',$driver)->update(['recorded_at'=>(int)round(microtime(true)*1000)]);
    $receiving=row('places',['company_uuid'=>$company,'name'=>'DEMO Temporary Receiving Hospital','meta'=>$liveMeta,'location'=>$point,'latitude'=>18.083,'longitude'=>82.669]);
    $receivingId=DB::table('places')->where('uuid',$receiving)->value('public_id');
    // A legitimate destination also used for another order's pickup must remain.
    DB::table('payloads')->where('pickup_uuid',DB::table('payloads')->first()->pickup_uuid)->update(['pickup_uuid'=>$receiving]);
    $liveBody=[...$manual,'action_id'=>(string)Str::uuid(),'demo'=>false,'callback_number'=>'+1 (202) 555-0142'];
    $liveCallback=$s->mutate(voiceRequest($liveBody,$tokens['admin']),'manual')['call'];
    check(!config('mapa_voice.live_enabled')&&!config('mapa_voice.demo_enabled'),'manual regression runs with Exotel and training disabled');
    check($liveCallback['caller']==='+12025550142'&&$liveCallback['draft']['callback_number']==='+12025550142','manual callback uses shared normalization before persistence');
    check($s->mutate(voiceRequest($liveBody,$tokens['admin']),'manual')['replayed'],'formatted manual callback retry keeps its original receipt');
    denied(fn()=>$s->mutate(voiceRequest([...$liveBody,'callback_number'=>'+12025550142'],$tokens['admin']),'manual'),409);
    $liveReview=[...$manualReview,'action_id'=>(string)Str::uuid(),'expected_revision'=>$liveCallback['revision'],'coordinates_confirmed'=>true,'dropoff_id'=>$receivingId,'callback_number'=>'+44 (20) 7946-0958'];
    $countBefore=DB::table('mapa_voice_calls')->count();
    foreach(['1234567','09876543210','+01234567890','+12025550142;123',"+12025550142\n"] as $invalid){
        denied(fn()=>$s->mutate(voiceRequest([...$liveBody,'action_id'=>(string)Str::uuid(),'callback_number'=>$invalid],$tokens['admin']),'manual'),422);
        denied(fn()=>$s->mutate(voiceRequest([...$liveReview,'action_id'=>(string)Str::uuid(),'callback_number'=>$invalid],$tokens['admin']),'review',$liveCallback['id']),422);
    }
    check(DB::table('mapa_voice_calls')->count()===$countBefore,'invalid callbacks create no manual records');
    check($s->show(voiceRequest([],$tokens['admin']),$liveCallback['id'])['call']['revision']===$liveCallback['revision'],'invalid callback review does not advance the call');
    $liveReady=$s->mutate(voiceRequest($liveReview,$tokens['admin']),'review',$liveCallback['id']);
    check($liveReady['call']['review']['callback_number']==='+442079460958'&&count($liveReady['candidates'])===1,'manual review normalizes the callback and finds an eligible crew without Exotel');
    check($s->mutate(voiceRequest($liveReview,$tokens['admin']),'review',$liveCallback['id'])['replayed'],'normalized review has an exact immutable retry');
    denied(fn()=>$s->mutate(voiceRequest([...$liveReview,'callback_number'=>'+442079460958'],$tokens['admin']),'review',$liveCallback['id']),409);
    $liveDispatch=['action_id'=>(string)Str::uuid(),'expected_revision'=>$liveReady['call']['revision'],'confirmed'=>true,'driver_id'=>'driver_mapa_voice_demo_01'];
    $liveResult=$s->mutate(voiceRequest($liveDispatch,$tokens['admin']),'dispatch',$liveCallback['id']);
    check($liveResult['call']['state']==='dispatched'&&!$liveResult['call']['demo'],'real manual branch dispatches independently of telephony and training');
    $ordersBeforeRetry=DB::table('orders')->count();
    check($s->mutate(voiceRequest($liveDispatch,$tokens['admin']),'dispatch',$liveCallback['id'])['replayed']&&DB::table('orders')->count()===$ordersBeforeRetry,'manual dispatch retry never creates a second native order');
    $liveSnap=$mobile->snapshot($mobile->context(voiceRequest([],$tokens['crew'])));
    check(count($liveSnap['orders'])===1&&$liveSnap['orders'][0]['id']===$liveResult['order_id']&&$liveSnap['orders'][0]['dispatch_details']['callback_number']==='+442079460958','manual create review dispatch delivers the exact reviewed callback to assigned crew');
    // A backlog of generated intake pickups must not consume destination slots.
    for($i=0;$i<205;$i++){
        $pickupUuid=(string)Str::uuid();
        row('places',['company_uuid'=>$company,'public_id'=>'place_mapa_voice_'.str_replace('-','',$pickupUuid),'name'=>'Call pickup','location'=>$point,'latitude'=>18.083,'longitude'=>82.669,
            '_key'=>'mapa-voice-v1','meta'=>json_encode(['mapa_demo'=>false,'isolated_test'=>true,'mapa_voice_call_id'=>$liveCallback['id'],'mapa_voice_source'=>'dispatcher_confirmed'])]);
    }
    $otherIntakePlace=row('places',['company_uuid'=>$company,'name'=>'DEMO Intake-origin Receiving Place','_key'=>'mapa-voice-v1','meta'=>$liveMeta,'location'=>$point,'latitude'=>18.083,'longitude'=>82.669]);
    $otherIntakeId=DB::table('places')->where('uuid',$otherIntakePlace)->value('public_id');
    $destinations=$s->show(voiceRequest([],$tokens['admin']),$liveCallback['id'])['places'];
    $destinationIds=array_column($destinations,'id');
    check(in_array($receivingId,$destinationIds,true),'generated pickup backlog cannot crowd out the receiving hospital also used for pickup');
    check(in_array($otherIntakeId,$destinationIds,true),'matching module key alone never removes a legitimate destination');
    check(count(array_filter($destinationIds,fn($id)=>preg_match('/^place_mapa_voice_[0-9a-f]{32}$/D',$id)))===0,'generated intake pickup places are excluded before the destination limit');
    require __DIR__.'/source-caller-cases.php';
    require __DIR__.'/destination-cases.php';
    require __DIR__.'/ambulance-directory-cases.php';
    DB::table('mapa_mobile_bindings')->where('id',$bindings['admin'])->update(['enabled'=>false]);denied(fn()=>$s->index(voiceRequest([],$tokens['admin'])),401);
    check(Policy::allowedResource(false,'{}','{}'),'real resources may qualify');check(!Policy::allowedResource(false,'{"simulated":true}','{}'),'unlabelled synthetic live resource denied');
    check(Policy::distance(0,0,0,0)===0.0,'zero distance');check(Policy::distance(0,0,0,1)>111&&Policy::distance(0,0,0,1)<112,'great circle distance');
    check(!Policy::coordinates(INF,1)&&!Policy::coordinates(91,1),'invalid coordinate rejection');check($fkChecks>20,'native FK checks executed');
    check(Queue::pushedJobs()===[],'no queued notifications');check(Http::recorded()->count()===0,'no external HTTP requests');
    echo json_encode(['ok'=>true,'checks'=>$checks,'native_fk_checks'=>$fkChecks,'production_rows_written'=>0,'notifications_sent'=>0],JSON_PRETTY_PRINT).PHP_EOL;
}finally{DB::rollBack();}

<?php
/** Run only in a disposable CLI connection. All named native tables below are
 * connection-local TEMPORARY shadows. Never migrate or register this module here. */
require '/fleetbase/api/vendor/autoload.php';
// Load the staged module before provider boot, so tests do not reuse the deployed revision.
foreach (['EnrollmentPolicy','PrivateMedia','TripCompletionEvidence','Policy','CallerPhone','CallerPhoneRequest','TravelEvidence','TripWorkflow','CrewDriverLocationChanged','CrewVehicleLocationChanged','MobileService','EnrollmentService','EnrollmentController','ConsoleEnrollmentService','ConsoleReviewSession','MobileController','BoundedRequest','MobileServiceProvider'] as $file) require_once __DIR__.'/../'.$file.'.php';
$app=$mapaDedicatedQaApp??null;
if(!$app){$app=require '/fleetbase/api/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();}
set_exception_handler(function (\Throwable $e) { fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n"); exit(1); });
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\MapaMobile\MobileService;

if (app()->runningInConsole() !== true || DB::getDriverName() !== 'mysql') throw new RuntimeException('Requires isolated MySQL CLI test connection.');
Http::fake(); Queue::fake(); Bus::fake(); Mail::fake(); Notification::fake();
// Keep only the native Position creating hooks which supply its mandatory UUID.
// All workflow/domain events and saved/created observers stay faked.
Event::fakeExcept(['eloquent.creating: Fleetbase\\FleetOps\\Models\\Position']);
// LIKE copies schema, never production rows. TEMPORARY tables disappear when
// this CLI connection exits and are invisible to the live API connection.
$tables=['users','companies','company_users','drivers','vehicles','places','orders','payloads','order_configs','tracking_numbers','tracking_statuses','positions','entities','waypoints','settings'];
if(isset($mapaDedicatedQaApp)) {
    foreach(['mapa_mobile_hospital_handovers','mapa_mobile_hospital_responses','mapa_mobile_enrollments','mapa_mobile_private_media','mapa_mobile_trip_reports','mapa_mobile_enrollment_audit'] as $table) $tables[]=$table;
}
foreach ($tables as $table) {
    $ddl = (array)DB::selectOne("SHOW CREATE TABLE `$table`");
    $ddl = preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl['Create Table']);
    // MySQL temporary tables cannot carry foreign-key constraints. Native
    // column types/indexes/defaults are otherwise unchanged; no rows copied.
    $ddl = implode("\n",array_filter(explode("\n",$ddl),fn($line)=>!preg_match('/^\s*CONSTRAINT /',$line)));
    $ddl = preg_replace('/,\s*\n\)/',"\n)",$ddl);
    DB::statement($ddl);
}
$definitions=[
'mapa_mobile_bindings'=>'id char(36) primary key,company_uuid char(36),user_uuid char(36),role varchar(20),driver_uuid char(36) null,place_uuid char(36) null,enabled boolean,revision int,created_at timestamp null,updated_at timestamp null',
'mapa_mobile_sessions'=>'token_hash varchar(64) primary key,binding_id char(36),company_uuid char(36),user_uuid char(36),binding_revision int,credential_hash varchar(64),scope_hash varchar(64),device_name varchar(100),expires_at bigint,revoked_at bigint null,created_at timestamp null,updated_at timestamp null',
'mapa_mobile_receipts'=>'id bigint auto_increment primary key,company_uuid char(36),user_uuid char(36),action_id varchar(100),request_hash varchar(64),result json,created_at timestamp null,unique(company_uuid,user_uuid,action_id)',
'mapa_mobile_order_states'=>'order_uuid char(36) primary key,company_uuid char(36),driver_uuid char(36),acknowledged_by char(36),acknowledged_at bigint,assignment_hash varchar(64),created_at timestamp null,updated_at timestamp null',
'mapa_mobile_driver_states'=>'driver_uuid char(36) primary key,company_uuid char(36),recorded_at bigint,created_at timestamp null,updated_at timestamp null',
];
foreach($definitions as $table=>$columns) DB::statement("CREATE TEMPORARY TABLE `$table` ($columns) ENGINE=InnoDB");
DB::beginTransaction();
$count=0;
function check($ok,$message) { global $count; if(!$ok)throw new RuntimeException($message);$count++; }
function denied(callable $fn,int $status) { try{$fn();throw new RuntimeException('Expected denial '.$status);}catch(Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){check($e->getStatusCode()===$status,'Unexpected denial '.$e->getStatusCode().': '.$e->getMessage());} }
function row(string $table,array $data): string {
    $uuid=(string)($data['uuid']??Str::uuid());
    $base=['uuid'=>$uuid,'public_id'=>rtrim($table,'s').'_DEMO_'.str_replace('-','',$uuid),'name'=>'DEMO synthetic '. $table,'meta'=>json_encode(['mapa_demo'=>true]),'created_at'=>now(),'updated_at'=>now()];
    $columns=array_column(DB::select("SHOW COLUMNS FROM `$table`"),'Field');
    DB::table($table)->insert(array_intersect_key([...$base,...$data],array_flip($columns))); return $uuid;
}
function req(array $body=[],?string $token=null): Request {return Request::create('/mapa/mobile/v1/test','POST',[],[],[],['CONTENT_TYPE'=>'application/json',...($token?['HTTP_AUTHORIZATION'=>'Bearer '.$token]:[])],json_encode($body));}
class IsolatedService extends MobileService { public function company(): string {return config('mapa.company_uuid');} }
try {
    // The source read contract pins the real organization identity. This row is
    // synthetic in the isolated/temporary QA database, not a production lookup.
    require_once __DIR__.'/../SourceSync.php';
    $company=row('companies',['uuid'=>\MapaSource\SourceSync::COMPANY]);$foreign=row('companies',[]);config(['mapa.company_uuid'=>$company,'mapa_mobile.enabled'=>true,'mapa_mobile.trips_enabled'=>false]);
    $point=DB::raw("ST_GeomFromText('POINT(18.08 82.66)')");
    $user=row('users',['email'=>'demo-crew@example.invalid','password'=>Hash::make('DEMO-test-password-only'),'status'=>'active','company_uuid'=>$company,'email_verified_at'=>now()]);
    $otherUser=row('users',['email'=>'demo-other@example.invalid','password'=>Hash::make('DEMO-other-password-only'),'status'=>'active','company_uuid'=>$foreign]);
    row('company_users',['company_uuid'=>$company,'user_uuid'=>$user,'status'=>'active']);row('company_users',['company_uuid'=>$foreign,'user_uuid'=>$otherUser,'status'=>'active']);
    $vehicle=row('vehicles',['company_uuid'=>$company,'online'=>false]);$otherVehicle=row('vehicles',['company_uuid'=>$company,'online'=>false]);
    $driver=row('drivers',['company_uuid'=>$company,'user_uuid'=>$user,'vehicle_uuid'=>$vehicle,'location'=>$point,'online'=>false]);
    $otherDriver=row('drivers',['company_uuid'=>$foreign,'user_uuid'=>$otherUser,'location'=>$point,'online'=>false]);
    $binding=(string)Str::uuid();DB::table('mapa_mobile_bindings')->insert(['id'=>$binding,'company_uuid'=>$company,'user_uuid'=>$user,'role'=>'crew','driver_uuid'=>$driver,'enabled'=>true,'revision'=>1]);
    $service=new IsolatedService();
    $credentials=['email'=>'demo-crew@example.invalid','password'=>'DEMO-test-password-only'];
    denied(fn()=>$service->login(req(['email'=>'demo-crew@example.invalid','password'=>'wrong'])),401);
    denied(fn()=>$service->login(req(['email'=>'demo-other@example.invalid','password'=>'DEMO-other-password-only'])),401);
    $login=$service->login(req(['email'=>'demo-crew@example.invalid','password'=>'DEMO-test-password-only']));$token=$login['token'];
    $userKey=Fleetbase\Models\User::where('uuid',$user)->first()->getKey();
    foreach (['user.'.$userKey.'.2fa'=>'enabled','company.'.$company.'.2fa'=>'enforced','system.2fa'=>'enforced'] as $key=>$flag) {
        DB::table('settings')->insert(['key'=>$key,'value'=>json_encode([$flag=>true])]);
        denied(fn()=>$service->login(req($credentials)),403);
        denied(fn()=>$service->context(req([],$token)),403);
        DB::table('settings')->where('key',$key)->delete();
    }
    check(DB::table('settings')->count()===0,'authentication checks do not create native settings');
    foreach (['two_factor_enabled','password_reset_required','force_password_reset','must_change_password','password_expired'] as $flag) {
        DB::table('users')->where('uuid',$user)->update(['meta'=>json_encode(['mapa_demo'=>true,$flag=>true])]);
        denied(fn()=>$service->login(req($credentials)),403);denied(fn()=>$service->context(req([],$token)),403);
    }
    DB::table('users')->where('uuid',$user)->update(['meta'=>json_encode(['mapa_demo'=>true]),'email_verified_at'=>null]);
    denied(fn()=>$service->login(req($credentials)),403);denied(fn()=>$service->context(req([],$token)),403);
    DB::table('users')->where('uuid',$user)->update(['email_verified_at'=>now()]);
    check(strlen($token)===64,'opaque token');check(DB::table('mapa_mobile_sessions')->where('token_hash',$token)->doesntExist(),'plaintext never stored');
    $context=$service->context(req([],$token));check($context['driver']->uuid===$driver&&$context['vehicle']->uuid===$vehicle,'same native identities');
    check($login['capabilities']['gps']&&!$login['capabilities']['photo_upload']&&!$login['capabilities']['activities'],'accurate capabilities');
    $duty=['action_id'=>(string)Str::uuid(),'online'=>true];$service->mutate(req($duty,$token),'duty');
    check(DB::table('drivers')->where('uuid',$driver)->value('online')==1,'native driver duty');check(DB::table('vehicles')->where('uuid',$vehicle)->value('online')==1,'native vehicle duty');
    check($service->mutate(req($duty,$token),'duty')['replayed'],'same action replay');denied(fn()=>$service->mutate(req([...$duty,'online'=>false],$token),'duty'),409);
    $gps=['action_id'=>(string)Str::uuid(),'latitude'=>18.1,'longitude'=>82.7,'recorded_at'=>(int)round(microtime(true)*1000)-1000,'accuracy_m'=>5];
    $service->mutate(req($gps,$token),'gps');check(DB::table('positions')->count()===2,'native driver and vehicle positions');
    check(DB::table('positions')->where('subject_uuid',$vehicle)->where('company_uuid',$company)->count()===1,'vehicle identity in native GPS');
    $service->mutate(req($gps,$token),'gps');check(DB::table('positions')->count()===2,'GPS replay no duplicate');
    $history=$service->snapshot($service->context(req([],$token)))['travel_evidence'];
    check(count($history['points'])===1&&$history['points'][0]['accuracy_m']==5&&$history['points'][0]['recorded_at']===$gps['recorded_at'],'accepted device fix retained with accuracy and device time');
    check($history['points'][0]['received_at']>=$gps['recorded_at']&&$history['proof_of_travel']===false,'receipt time separate and no proof claim');
    check($history['points'][0]['mock_location']===null,'legacy mock state explicitly unknown');
    $travel=new App\MapaMobile\TravelEvidence;
    check(count($travel->inspect(req([],$token),$service->context(req([],$token)))['travel_evidence']['points'])===1,'crew can inspect own accepted fixes');
    denied(fn()=>$travel->inspect(Request::create('/travel?driver_id=someone_else'),$service->context(req([],$token))),404);
    foreach([true,null,1,'false'] as $mock)denied(fn()=>$service->mutate(req([...$gps,'action_id'=>(string)Str::uuid(),'recorded_at'=>$gps['recorded_at']+1,'mock_location'=>$mock],$token),'gps'),422);
    check(DB::table('positions')->count()===2,'mock and invalid reports never update native positions');
    foreach([['recorded_at'=>$gps['recorded_at']-1],['recorded_at'=>$gps['recorded_at']+60000],['accuracy_m'=>150]] as $change)
        denied(fn()=>$service->mutate(req([...$gps,...$change,'action_id'=>(string)Str::uuid()],$token),'gps'),422);
    DB::table('drivers')->where('uuid',$driver)->update(['vehicle_uuid'=>$otherVehicle]);
    denied(fn()=>$service->mutate(req(['action_id'=>(string)Str::uuid(),'online'=>true],$token),'duty'),401);
    denied(fn()=>$service->mutate(req([...$gps,'action_id'=>(string)Str::uuid()],$token),'gps'),401);
    $reassigned=$service->login(req($credentials));
    check($service->snapshot($service->context(req([],$reassigned['token'])))['travel_evidence']['points']===[],'fresh reassignment cannot see former ambulance history');
    denied(fn()=>$service->mutate(req($duty,$reassigned['token']),'duty'),409);
    denied(fn()=>$service->mutate(req($gps,$reassigned['token']),'gps'),409);
    check(DB::table('vehicles')->where('uuid',$otherVehicle)->value('online')==0,'queued action never touches reassigned ambulance');
    DB::table('drivers')->where('uuid',$driver)->update(['vehicle_uuid'=>$vehicle]);
    $sameScope=$service->login(req($credentials));
    check($service->mutate(req($gps,$sameScope['token']),'gps')['replayed'],'same-scope new session safely replays existing receipt');
    $payload=row('payloads',['company_uuid'=>$company]);
    $order=row('orders',['company_uuid'=>$company,'driver_assigned_uuid'=>$driver,'vehicle_assigned_uuid'=>$vehicle,'payload_uuid'=>$payload,'status'=>'dispatched','dispatched'=>1]);
    $foreignOrder=row('orders',['company_uuid'=>$foreign,'driver_assigned_uuid'=>$otherDriver,'status'=>'dispatched','dispatched'=>1]);
    $snap=$service->snapshot($service->context(req([],$token)));check(count($snap['orders'])===1,'only assigned native order visible');
    $baseMeta=['mapa_demo'=>true,'unchanged'=>'retain','mapa_dispatch_review'=>['callback_number'=>'+12025550199','reported_problem'=>'DEMO reported incident']];
    foreach([['mapa_caller_phone'=>'+1 (202) 555-0142'],['mapa_caller_phone'=>''],['mapa_caller_phone'=>null],[]] as $override){
        $meta=[...$baseMeta,...$override];DB::table('orders')->where('uuid',$order)->update(['meta'=>json_encode($meta)]);
        $phoneSnapshot=$service->snapshot($service->context(req([],$token)));$details=$phoneSnapshot['orders'][0]['dispatch_details'];
        $expected=array_key_exists('mapa_caller_phone',$override)?($override['mapa_caller_phone']?'+12025550142':null):'+12025550199';
        check(($details['callback_number']??null)===$expected,'native caller phone takes precedence, explicit unknown clears, absent preserves review callback');
        check($details['reported_problem']==='DEMO reported incident','caller mapping preserves other reviewed handover fields');
    }
    $otherCompanyPhone=['mapa_demo'=>true,'mapa_caller_phone'=>'+12025550143'];DB::table('orders')->where('uuid',$foreignOrder)->update(['meta'=>json_encode($otherCompanyPhone)]);
    $unassigned=row('orders',['company_uuid'=>$company,'status'=>'dispatched','dispatched'=>1,'meta'=>json_encode(['mapa_demo'=>true,'mapa_caller_phone'=>'+12025550144'])]);
    $privateSnapshot=$service->snapshot($service->context(req([],$token)));check(count($privateSnapshot['orders'])===1&&!str_contains(json_encode($privateSnapshot),'+12025550143')&&!str_contains(json_encode($privateSnapshot),'+12025550144'),'another company or unassigned order cannot reveal callback to this crew');
    DB::table('orders')->where('uuid',$unassigned)->delete();
    DB::table('orders')->where('uuid',$order)->update(['meta'=>json_encode(['mapa_demo'=>true])]);
    $snap=$service->snapshot($service->context(req([],$token)));
    $o=$snap['orders'][0];check(!$o['acknowledged'],'not silently acknowledged');check($o['can_acknowledge']===true,'active native dispatch may be acknowledged');
    check($o['next_activities']===[],'unreviewed activity events unavailable');
    foreach (['created','scheduled'] as $notActive) {
        DB::table('orders')->where('uuid',$order)->update(['status'=>$notActive]);
        check($service->snapshot($service->context(req([],$token)))['orders'][0]['can_acknowledge']===false,'non-dispatch status has no acknowledgement button');
        denied(fn()=>$service->mutate(req(['action_id'=>(string)Str::uuid(),'expected_revision'=>$o['revision']],$token),'ack',$o['id']),409);
    }
    DB::table('orders')->where('uuid',$order)->update(['status'=>'dispatched']);
    $ack=['action_id'=>(string)Str::uuid(),'expected_revision'=>$o['revision']];$service->mutate(req($ack,$token),'ack',$o['id']);
    check(DB::table('orders')->where('uuid',$order)->value('status')==='dispatched','ack does not fabricate activity');
    $ackMeta=json_decode(DB::table('orders')->where('uuid',$order)->value('meta'),true);
    check($ackMeta['mapa_demo']===true && $ackMeta['mapa_mobile_acknowledgement']['driver_id']===$context['driver']->public_id,'native order retains DEMO metadata and records crew acknowledgement');
    denied(fn()=>$service->mutate(req(['action_id'=>(string)Str::uuid(),'expected_revision'=>$o['revision'],'activity_code'=>'started'],$token),'activity',$o['id']),409);
    check(DB::table('tracking_statuses')->count()===0,'disabled activity cannot generate native events or tracking');
    check($service->snapshot($service->context(req([],$token)))['orders'][0]['acknowledged'],'explicit acknowledgement persisted');
    if(isset($mapaDedicatedQaApp)) require __DIR__.'/hospital-handover-native-cases.php';
    require __DIR__.'/trip-cases.php';
    if(isset($mapaDedicatedQaApp)) require __DIR__.'/enrollment-native-cases.php';
    denied(fn()=>$service->mutate(req(['action_id'=>(string)Str::uuid(),'expected_revision'=>$o['revision']],$token),'ack',$o['id']),409);
    $foreignId=DB::table('orders')->where('uuid',$foreignOrder)->value('public_id');denied(fn()=>$service->mutate(req($ack,$token),'ack',$foreignId),404);
    DB::table('orders')->where('uuid',$order)->update(['status'=>'completed']);check($service->mutate(req($ack,$token),'ack',$o['id'])['replayed'],'receipt survives terminal state');
    DB::table('orders')->where('uuid',$order)->update(['driver_assigned_uuid'=>$otherDriver]);denied(fn()=>$service->mutate(req($ack,$token),'ack',$o['id']),404);
    DB::table('company_users')->where('user_uuid',$user)->update(['status'=>'inactive']);denied(fn()=>$service->context(req([],$token)),403);
    DB::table('company_users')->where('user_uuid',$user)->update(['status'=>'active']);
    DB::table('mapa_mobile_bindings')->where('id',$binding)->update(['role'=>'admin']);denied(fn()=>$service->context(req([],$token)),401);
    DB::table('mapa_mobile_bindings')->where('id',$binding)->update(['role'=>'crew']);
    $savedPassword=DB::table('users')->where('uuid',$user)->value('password');
    DB::table('users')->where('uuid',$user)->update(['password'=>Hash::make('DEMO-new-password-only')]);
    denied(fn()=>$service->context(req([],$token)),401);
    DB::table('users')->where('uuid',$user)->update(['password'=>$savedPassword]);
    $tokenHash=hash('sha256',$token);$expiry=DB::table('mapa_mobile_sessions')->where('token_hash',$tokenHash)->value('expires_at');
    DB::table('mapa_mobile_sessions')->where('token_hash',$tokenHash)->update(['expires_at'=>1]);denied(fn()=>$service->context(req([],$token)),401);
    DB::table('mapa_mobile_sessions')->where('token_hash',$tokenHash)->update(['expires_at'=>$expiry]);
    $service->logout($service->context(req([],$token)));denied(fn()=>$service->context(req([],$token)),401);
    $place=row('places',['company_uuid'=>$company,'location'=>$point]);
    row('orders',['company_uuid'=>$company,'status'=>'dispatched','dispatched'=>1]);
    foreach (['hospital','admin','superadmin'] as $role) {
        $email='demo-'.$role.'@example.invalid';
        $staff=row('users',['email'=>$email,'password'=>Hash::make('DEMO-staff-password-only'),'status'=>'active','company_uuid'=>$company,'email_verified_at'=>now(),'type'=>$role==='superadmin'?'admin':'user']);
        row('company_users',['company_uuid'=>$company,'user_uuid'=>$staff,'status'=>'active']);
        DB::table('mapa_mobile_bindings')->insert(['id'=>(string)Str::uuid(),'company_uuid'=>$company,'user_uuid'=>$staff,'role'=>$role,'place_uuid'=>$role==='hospital'?$place:null,'enabled'=>true,'revision'=>1]);
        $staffCredentials=['email'=>$email,'password'=>'DEMO-staff-password-only'];
        if($role==='superadmin') {
            denied(fn()=>$service->login(req($staffCredentials)),403);
            config(['mapa_mobile.superadmin_user_uuids'=>[$staff]]);
        }
        $staffLogin=$service->login(req($staffCredentials));$staffToken=$staffLogin['token'];
        $staffSnapshot=$service->snapshot($service->context(req([],$staffToken)));
        check($staffSnapshot['user']['role']===$role && !$staffSnapshot['capabilities']['gps'],'explicit staff role never crew');
        check(count($staffSnapshot['orders'])===($role==='hospital'?0:1),'role and company constrain order reads');
        check(count($staffSnapshot['places'])===($role==='hospital'?1:0),'hospital sees only bound native place');
        $staffContext=$service->context(req([],$staffToken));
        if($role==='hospital')denied(fn()=>$travel->inspect(Request::create('/travel'),$staffContext),403);
        else{
            $roster=$travel->inspect(Request::create('/travel'),$staffContext);
            check(count($roster['drivers'])===1,'staff roster excludes another company');
            $path='/travel?driver_id='.urlencode($context['driver']->public_id);
            check(count($travel->inspect(Request::create($path),$staffContext)['travel_evidence']['points'])===1,'staff can inspect company crew evidence');
            denied(fn()=>$travel->inspect(Request::create('/travel?driver_id='.urlencode(DB::table('drivers')->where('uuid',$otherDriver)->value('public_id'))),$staffContext),404);
        }
        denied(fn()=>$service->mutate(req(['action_id'=>(string)Str::uuid(),'online'=>true],$staffToken),'duty'),403);
        if($role==='superadmin') {
            DB::table('users')->where('uuid',$staff)->update(['type'=>'user']);
            denied(fn()=>$service->context(req([],$staffToken)),403);
        }
    }
    // Use a private in-memory limiter, never the live Redis/cache namespace.
    $limiter = new Illuminate\Cache\RateLimiter(app('cache')->store('array'));
    Illuminate\Support\Facades\RateLimiter::swap($limiter);
    (new App\MapaMobile\MobileServiceProvider($app))->boot();
    $throttle = new Illuminate\Routing\Middleware\ThrottleRequests($limiter);
    $sharedAllowed=true;
    for($phone=0;$phone<25;$phone++) {
        $phoneToken=bin2hex(random_bytes(32));
        for($poll=0;$poll<12;$poll++) {
            $shared=req([],$phoneToken);$shared->server->set('REMOTE_ADDR','192.0.2.25');
            try{$response=$throttle->handle($shared,fn()=>response()->json(['ok'=>true]),'mapa-mobile');}
            catch(Illuminate\Http\Exceptions\HttpResponseException $e){$response=$e->getResponse();}
            $sharedAllowed=$sharedAllowed&&$response->getStatusCode()===200;
        }
    }
    check($sharedAllowed,'25 phones at six GPS and six snapshot requests/minute fit shared NAT allowance');
    $singleToken=bin2hex(random_bytes(32));
    for($poll=0;$poll<121;$poll++) {
        $single=req([],$singleToken);$single->server->set('REMOTE_ADDR','192.0.2.26');
        try{$response=$throttle->handle($single,fn()=>response()->json(['ok'=>true]),'mapa-mobile');}
        catch(Illuminate\Http\Exceptions\HttpResponseException $e){$response=$e->getResponse();}
    }
    check($response->getStatusCode()===429,'per-session protection remains 120/minute');
    for ($i=0;$i<1201;$i++) {
        try { $response=$throttle->handle(req([],bin2hex(random_bytes(32))),fn()=>response()->json(['ok'=>true]),'mapa-mobile'); }
        catch (Illuminate\Http\Exceptions\HttpResponseException $e) { $response=$e->getResponse(); }
        if($i===1199)check($response->getStatusCode()===200,'IP allowance before cap');
        if($i===1200)check($response->getStatusCode()===429 && str_contains($response->headers->get('Cache-Control'),'no-store'),'rotating tokens cannot escape fixed IP cap');
    }
    for($i=0;$i<6;$i++) {
        try { $response=$throttle->handle(req(['email'=>$i%2?' DEMO-CREW@example.invalid ':'demo-crew@example.invalid']),fn()=>response()->json(['ok'=>true]),'mapa-mobile-login'); }
        catch (Illuminate\Http\Exceptions\HttpResponseException $e) { $response=$e->getResponse(); }
    }
    check($response->getStatusCode()===429,'account normalization cannot bypass login limiter');
    $oversized=req(['data'=>str_repeat('a',16400)]);
    check((new App\MapaMobile\BoundedRequest())->handle($oversized,fn()=>response()->json(['unexpected'=>true]))->getStatusCode()===413,'oversized request blocked before auth');
    echo "$count native integration checks passed; temporary synthetic tables only.\n";
} finally {
    while(DB::transactionLevel()>0)DB::rollBack();
    foreach(array_merge(array_keys($definitions),$tables) as $table) DB::statement("DROP TEMPORARY TABLE IF EXISTS `$table`");
}

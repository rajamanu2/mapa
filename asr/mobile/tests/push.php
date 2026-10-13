<?php
/** Isolated CLI only: temporary native-schema shadows, fake HTTP/queue, no real devices or notifications. */
require '/fleetbase/api/vendor/autoload.php';
$app = require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(\Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
use Illuminate\Support\Facades\{DB,Http,Queue,Bus,Mail,Notification,Event,Hash,Cache};
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\MapaMobile\{MobileService,PushService,FcmTransport,DeliverAssignmentPush,PushAssignmentObserver};
if (!app()->runningInConsole() || DB::getDriverName() !== 'mysql') throw new RuntimeException('Requires isolated MySQL CLI connection.');
Http::preventStrayRequests();Http::fake();Queue::fake();Bus::fake();Mail::fake();Notification::fake();Event::fake();
config(['cache.default'=>'array']);Cache::setDefaultDriver('array');
$tables=['users','companies','company_users','drivers','vehicles','orders','settings'];
foreach($tables as $table){$ddl=(array)DB::selectOne("SHOW CREATE TABLE `$table`");$ddl=preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl['Create Table']);$ddl=implode("\n",array_filter(explode("\n",$ddl),fn($line)=>!preg_match('/^\s*CONSTRAINT /',$line)));$ddl=preg_replace('/,\s*\n\)/',"\n)",$ddl);DB::statement($ddl);}
$definitions=[
'mapa_mobile_bindings'=>'id char(36) primary key,company_uuid char(36),user_uuid char(36),role varchar(20),driver_uuid char(36) null,place_uuid char(36) null,enabled boolean,revision int,created_at timestamp null,updated_at timestamp null',
'mapa_mobile_sessions'=>'token_hash varchar(64) primary key,binding_id char(36),company_uuid char(36),user_uuid char(36),binding_revision int,credential_hash varchar(64),scope_hash varchar(64),device_name varchar(100),expires_at bigint,revoked_at bigint null,created_at timestamp null,updated_at timestamp null',
'mapa_mobile_push_devices'=>'token_hash varchar(64) primary key,installation_id char(36),token_encrypted text,company_uuid char(36),binding_id char(36),session_hash varchar(64),driver_uuid char(36),vehicle_uuid char(36),expires_at bigint,revoked_at bigint null,created_at timestamp null,updated_at timestamp null,unique(company_uuid,installation_id)',
'mapa_mobile_push_deliveries'=>'id varchar(64) primary key,event_id varchar(64),token_hash varchar(64),status varchar(20),claimed_at bigint null,accepted_at bigint null,created_at timestamp null,updated_at timestamp null'];
foreach($definitions as $table=>$columns)DB::statement("CREATE TEMPORARY TABLE `$table` ($columns) ENGINE=InnoDB");
$count=0;$credentialFile=null;DB::beginTransaction();
function checkPush($ok,$why){global $count;if(!$ok)throw new RuntimeException($why);$count++;}
function rowPush(string $table,array $data=[]):string{$id=(string)Str::uuid();$base=['uuid'=>$id,'public_id'=>rtrim($table,'s').'_DEMO_'.str_replace('-','',$id),'name'=>'DEMO isolated push '.$table,'meta'=>json_encode(['mapa_demo'=>true]),'created_at'=>now(),'updated_at'=>now()];$columns=array_column(DB::select("SHOW COLUMNS FROM `$table`"),'Field');DB::table($table)->insert(array_intersect_key([...$base,...$data],array_flip($columns)));return $id;}
function pushRequest(array $data,string $token=''):Request{return Request::create('/mapa/mobile/v1/push/register','POST',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer '.$token],json_encode($data));}
function deniedPush(callable $f,int $status){try{$f();throw new RuntimeException('Expected denial');}catch(Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){checkPush($e->getStatusCode()===$status,'Expected '.$status.', got '.$e->getStatusCode());}}
class IsolatedPushService extends PushService {protected function storageReady():bool{return true;}public function available():bool{return true;}}
class FakeFcm extends FcmTransport {public array $sent=[];public string $result='accepted';public function configured():bool{return true;}public function send(string $token,string $eventId,string $sessionHash,int $issuedAt):string{$this->sent[]=self::message($token,$eventId,$sessionHash,$issuedAt);return $this->result;}}
// The test override permits ONLY these connection-local marked synthetic rows to exercise the fake transport.
class IsolatedPushJob extends DeliverAssignmentPush {protected function isTraining($model):bool{return false;}}
try{
    $company=rowPush('companies');config(['mapa.company_uuid'=>$company,'mapa_mobile.enabled'=>true,'mapa_mobile.push.enabled'=>false]);
    $user=rowPush('users',['email'=>'demo-push@example.invalid','password'=>Hash::make('DEMO isolated password'),'status'=>'active','company_uuid'=>$company,'email_verified_at'=>now()]);
    rowPush('company_users',['company_uuid'=>$company,'user_uuid'=>$user,'status'=>'active']);
    $vehicle=rowPush('vehicles',['company_uuid'=>$company,'online'=>true]);$driver=rowPush('drivers',['company_uuid'=>$company,'user_uuid'=>$user,'vehicle_uuid'=>$vehicle,'online'=>true,'location'=>DB::raw("ST_GeomFromText('POINT(18.08 82.66)')")]);
    $binding=(string)Str::uuid();DB::table('mapa_mobile_bindings')->insert(['id'=>$binding,'company_uuid'=>$company,'user_uuid'=>$user,'role'=>'crew','driver_uuid'=>$driver,'enabled'=>true,'revision'=>1]);
    $mobile=new MobileService;$login=$mobile->login(pushRequest(['email'=>'demo-push@example.invalid','password'=>'DEMO isolated password']));$token=$login['token'];
    $input=['installation_id'=>(string)Str::uuid(),'fcm_token'=>'DEMO_FCM_TOKEN_FOR_ISOLATED_TEST_ONLY_0123456789'];
    deniedPush(fn()=>(new PushService)->register(pushRequest($input)),401);
    deniedPush(fn()=>(new PushService)->register(pushRequest($input,$token)),503);
    checkPush((new FcmTransport)->configured()===false,'missing provider fails closed');
    checkPush($login['capabilities']['push_alerts']===false,'no false push capability');
    $push=new IsolatedPushService;$registered=$push->register(pushRequest($input,$token));
    checkPush($registered['registered']===true,'device registration receipt');
    $device=DB::table('mapa_mobile_push_devices')->first();
    checkPush($device->token_encrypted!==$input['fcm_token']&&!str_contains($device->token_encrypted,$input['fcm_token']),'encrypted registration token');
    checkPush($device->driver_uuid===$driver&&$device->vehicle_uuid===$vehicle&&$device->company_uuid===$company,'server-owned scope');
    checkPush($device->session_hash===hash('sha256',$token),'exact session bound');
    checkPush($push->status($mobile->context(pushRequest([],$token)))['registered'],'registration status');
    $input['fcm_token'].='_ROTATED';$push->register(pushRequest($input,$token));checkPush(DB::table('mapa_mobile_push_devices')->count()===1,'token rotation replaces old registration');
    $orderId=rowPush('orders',['company_uuid'=>$company,'driver_assigned_uuid'=>$driver,'vehicle_assigned_uuid'=>$vehicle,'status'=>'dispatched','dispatched'=>true]);
    $fcm=new FakeFcm;$event=hash('sha256','DEMO-event');
    (new DeliverAssignmentPush($orderId,$company,$driver,$vehicle,$event,time()))->handle($fcm,$push);checkPush(count($fcm->sent)===0,'production job refuses training records');
    $job=new IsolatedPushJob($orderId,$company,$driver,$vehicle,$event,time());$job->handle($fcm,$push);checkPush(count($fcm->sent)===1,'scoped live-shape job reaches fake provider');
    $message=$fcm->sent[0]['message'];checkPush(array_keys($message['data'])===['kind','event_id','session_tag','issued_at'],'payload contains only non-sensitive hint');
    checkPush(!isset($message['notification'])&&!str_contains(json_encode($message),$orderId),'no auto-display or order identity in provider payload');
    $job->handle($fcm,$push);checkPush(count($fcm->sent)===1,'accepted event deduplicated');
    DB::table('orders')->where('uuid',$orderId)->update(['driver_assigned_uuid'=>(string)Str::uuid()]);
    (new IsolatedPushJob($orderId,$company,$driver,$vehicle,hash('sha256','changed'),time()))->handle($fcm,$push);checkPush(count($fcm->sent)===1,'reassignment prevents delivery');
    DB::table('orders')->where('uuid',$orderId)->update(['driver_assigned_uuid'=>$driver]);
    (new IsolatedPushJob($orderId,$company,$driver,$vehicle,hash('sha256','old'),time()-121))->handle($fcm,$push);checkPush(count($fcm->sent)===1,'expired alert discarded');
    DB::table('drivers')->where('uuid',$driver)->update(['online'=>false]);
    (new IsolatedPushJob($orderId,$company,$driver,$vehicle,hash('sha256','off-duty'),time()))->handle($fcm,$push);checkPush(count($fcm->sent)===1,'off-duty phone not alerted');
    DB::table('drivers')->where('uuid',$driver)->update(['online'=>true]);
    $fcm->result='unregistered';(new IsolatedPushJob($orderId,$company,$driver,$vehicle,hash('sha256','expired-device'),time()))->handle($fcm,$push);
    checkPush(DB::table('mapa_mobile_push_devices')->value('revoked_at')!==null,'provider invalid token revoked');
    $push->register(pushRequest($input,$token));$mobile->logout($mobile->context(pushRequest([],$token)));$sent=count($fcm->sent);
    (new IsolatedPushJob($orderId,$company,$driver,$vehicle,hash('sha256','logged-out'),time()))->handle($fcm,$push);checkPush(count($fcm->sent)===$sent,'logout prevents queued alert');
    $login=$mobile->login(pushRequest(['email'=>'demo-push@example.invalid','password'=>'DEMO isolated password']));$token=$login['token'];$push->register(pushRequest($input,$token));
    DB::table('settings')->insert(['key'=>'company.'.$company.'.2fa','value'=>json_encode(['enforced'=>true])]);
    (new IsolatedPushJob($orderId,$company,$driver,$vehicle,hash('sha256','mfa-change'),time()))->handle($fcm,$push);checkPush(count($fcm->sent)===$sent,'MFA change prevents queued alert');
    DB::table('settings')->delete();$push->register(pushRequest($input,$token));$push->unregister(pushRequest([],$token));checkPush(DB::table('mapa_mobile_push_devices')->value('revoked_at')!==null,'explicit unregister');
    // Real OAuth/HTTP v1 request construction, intercepted entirely by the in-memory fake HTTP client.
    $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);openssl_pkey_export($key,$private);
    $credentialFile=tempnam(sys_get_temp_dir(),'mapa-push-test-');file_put_contents($credentialFile,json_encode(['type'=>'service_account','project_id'=>'demo-mapa-test','client_email'=>'demo@demo-mapa-test.iam.gserviceaccount.com','private_key'=>$private]));
    config(['mapa_mobile.push.enabled'=>true,'mapa_mobile.push.project_id'=>'demo-mapa-test','mapa_mobile.push.credentials_file'=>$credentialFile]);
    Http::swap(new Illuminate\Http\Client\Factory);Http::preventStrayRequests();
    Http::fake(['https://oauth2.googleapis.com/token'=>Http::response(['access_token'=>str_repeat('x',30),'expires_in'=>3600],200),
        'https://fcm.googleapis.com/v1/projects/demo-mapa-test/messages:send'=>Http::response(['name'=>'projects/demo-mapa-test/messages/DEMO'],200)]);
    $transport=new FcmTransport;checkPush($transport->configured(),'valid test provider configuration');
    checkPush($transport->send($input['fcm_token'],$event,hash('sha256',$token),time())==='accepted','HTTP v1 provider acceptance');
    checkPush(Http::recorded(fn($request)=>$request->url()==='https://fcm.googleapis.com/v1/projects/demo-mapa-test/messages:send'&&$request['message']['android']['ttl']==='120s')->count()===1,'fixed HTTPS v1 endpoint and bounded TTL');
    config(['mapa_mobile.push.project_id'=>'different-project']);checkPush(!$transport->configured(),'project mismatch fails closed');
    echo "$count push integration checks passed; temporary synthetic tables and fake HTTP only.\n";
}finally{
    while(DB::transactionLevel()>0)DB::rollBack();foreach(array_merge(array_keys($definitions),$tables) as $table)DB::statement("DROP TEMPORARY TABLE IF EXISTS `$table`");
    if($credentialFile && is_file($credentialFile))unlink($credentialFile);
}

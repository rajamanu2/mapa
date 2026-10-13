<?php
/** Suite body after dedicated-native.php's fresh isolated MySQL bootstrap. */
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Fleetbase\FleetOps\Models\Order;

if (!isset($mapaDedicatedVoiceQaApp) || getenv('MAPA_VOICE_QA_ISOLATED') !== '1'
    || getenv('DB_DATABASE') !== 'mapa_voice_qa'
    || !preg_match('/^mapa-voice-qa-db-[a-f0-9]{8}$/D',(string)getenv('DB_HOST'))
    || getenv('DB_USERNAME') !== 'root' || getenv('DB_PASSWORD') !== 'DEMO-voice-qa-local-only'
    || !app()->runningInConsole() || DB::getDriverName() !== 'mysql'
    || DB::connection()->getDatabaseName() !== 'mapa_voice_qa') throw new RuntimeException('Fresh isolated MySQL arrival QA required.');
foreach (['users','companies','orders','mapa_mobile_sessions','mapa_mobile_receipts'] as $table)
    if(DB::table($table)->exists())throw new RuntimeException('Arrival QA requires empty fixture tables: '.$table);
foreach(['EnrollmentPolicy','PrivateMedia','TripCompletionEvidence','Policy','CallerPhone','CallerPhoneRequest','TravelEvidence','TripWorkflow','CrewDriverLocationChanged','CrewVehicleLocationChanged','VehiclePositionProjection','MobileService','TripArrivalService'] as $file)
    require_once __DIR__.'/../'.$file.'.php';
Http::fake(); Mail::fake(); Notification::fake();
Event::fakeExcept(['eloquent.creating: Fleetbase\\FleetOps\\Models\\Position']);
$count=0;
function arrivalCheck($ok,string $message):void {global $count;if(!$ok)throw new RuntimeException($message);$count++;}
function arrivalDenied(callable $run,int $status):void {try{$run();throw new RuntimeException('Expected HTTP '.$status);}catch(Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){arrivalCheck($e->getStatusCode()===$status,'Unexpected '.$e->getStatusCode().': '.$e->getMessage());}}
function arrivalRow(string $table,array $data=[]):string {
    $uuid=(string)($data['uuid']??Str::uuid());
    $base=['uuid'=>$uuid,'public_id'=>rtrim($table,'s').'_DEMO_'.str_replace('-','',$uuid),'name'=>'DEMO isolated arrival '.$table,'meta'=>json_encode(['mapa_demo'=>true]),'created_at'=>now(),'updated_at'=>now()];
    $columns=array_column(DB::select("SHOW COLUMNS FROM `$table`"),'Field');
    DB::table($table)->insert(array_intersect_key([...$base,...$data],array_flip($columns)));return $uuid;
}
function arrivalRequest(array $body=[],?string $token=null):Request {return Request::create('/mapa/mobile/v1/orders/DEMO/arrivals','POST',[],[],[],['CONTENT_TYPE'=>'application/json',...($token?['HTTP_AUTHORIZATION'=>'Bearer '.$token]:[])],json_encode($body));}
class ArrivalNativeProbe extends App\MapaMobile\TripArrivalService {
    // Expose only the production revision/ack helpers; authentication is never overridden.
    public function inspect(string $uuid):array {$o=Order::where('uuid',$uuid)->with(['payload','orderConfig'])->firstOrFail();return ['revision'=>$this->revision($o),'acknowledged'=>$this->acknowledged($o),'status'=>$o->status];}
}
// DDL precedes the fixture transaction, so MySQL cannot silently commit fixtures.
DB::unprepared("CREATE TRIGGER mapa_arrival_qa_receipt_fail BEFORE INSERT ON mapa_mobile_receipts FOR EACH ROW BEGIN IF @mapa_arrival_qa_fail = 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'DEMO injected receipt failure'; END IF; END");
DB::beginTransaction();
try {
    $company=arrivalRow('companies');$foreign=arrivalRow('companies');
    config(['mapa.company_uuid'=>$company,'mapa_mobile.enabled'=>true,'mapa_mobile.trips_enabled'=>true]);
    $point=DB::raw("ST_GeomFromText('POINT(18.08 82.66)')");
    $user=arrivalRow('users',['company_uuid'=>$company,'email'=>'demo-arrival@example.invalid','password'=>Hash::make('DEMO-arrival-only'),'status'=>'active','email_verified_at'=>now()]);
    arrivalRow('company_users',['company_uuid'=>$company,'user_uuid'=>$user,'status'=>'active']);
    $vehicle=arrivalRow('vehicles',['company_uuid'=>$company,'online'=>true]);
    $driver=arrivalRow('drivers',['company_uuid'=>$company,'user_uuid'=>$user,'vehicle_uuid'=>$vehicle,'online'=>true,'location'=>$point]);
    $binding=(string)Str::uuid();DB::table('mapa_mobile_bindings')->insert(['id'=>$binding,'company_uuid'=>$company,'user_uuid'=>$user,'role'=>'crew','driver_uuid'=>$driver,'enabled'=>true,'revision'=>1]);
    $payload=arrivalRow('payloads',['company_uuid'=>$company]);
    $order=arrivalRow('orders',['company_uuid'=>$company,'driver_assigned_uuid'=>$driver,'vehicle_assigned_uuid'=>$vehicle,'payload_uuid'=>$payload,'status'=>'started','started'=>true,'dispatched'=>true]);
    $id=DB::table('orders')->where('uuid',$order)->value('public_id');
    DB::table('drivers')->where('uuid',$driver)->update(['current_job_uuid'=>$order]);
    $service=new ArrivalNativeProbe;
    $credentials=['email'=>'demo-arrival@example.invalid','password'=>'DEMO-arrival-only'];
    arrivalDenied(fn()=>$service->login(arrivalRequest([...$credentials,'password'=>'wrong'])),401);
    $token=$service->login(arrivalRequest($credentials))['token'];
    arrivalCheck($service->context(arrivalRequest([],$token))['driver']->uuid===$driver,'Real login resolves native assignment');
    $ack=fn()=>$service->mutate(arrivalRequest(['action_id'=>(string)Str::uuid(),'expected_revision'=>$service->inspect($order)['revision']],$token),'ack',$id);
    $body=fn(string $code='pickup_arrived')=>['action_id'=>(string)Str::uuid(),'expected_revision'=>$service->inspect($order)['revision'],'arrival_code'=>$code,'confirmed'=>true,'reported_at'=>(int)round(microtime(true)*1000)];
    $send=fn(array $b,?string $t=null,string $target='')=>$service->recordArrival(arrivalRequest($b,$t??$token),$target?:$id);
    arrivalDenied(fn()=>$send($body(),str_repeat('0',64)),401);
    arrivalDenied(fn()=>$send($body()),409); // no current acknowledgement
    $ack();arrivalCheck($service->inspect($order)['acknowledged'],'Native acknowledgement survives MySQL JSON normalization');
    arrivalDenied(fn()=>$send($body()),422); // DEMO remains blocked, never live evidence
    // Isolated fault-injection only: all identifiers remain unmistakably DEMO.
    // Remove flags inside this rollback-only QA transaction to exercise the live-only branch.
    foreach(['orders'=>$order,'drivers'=>$driver,'vehicles'=>$vehicle] as $table=>$uuid)DB::table($table)->where('uuid',$uuid)->update(['meta'=>json_encode(['qa_fixture'=>'DEMO isolated rollback only','untouched'=>'retain'])]);
    $ack();
    arrivalDenied(fn()=>$send($body()),422); // missing GPS
    usleep(3000);
    $gps=['action_id'=>(string)Str::uuid(),'latitude'=>18.08,'longitude'=>82.66,'recorded_at'=>(int)round(microtime(true)*1000),'accuracy_m'=>5,'mock_location'=>false];
    arrivalDenied(fn()=>$service->mutate(arrivalRequest([...$gps,'mock_location'=>true],$token),'gps'),422);
    $service->mutate(arrivalRequest($gps,$token),'gps');
    arrivalCheck(DB::table('positions')->where('company_uuid',$company)->count()===2,'Actual GPS mutation creates both native spatial positions');
    $gpsReceipt=DB::table('mapa_mobile_receipts')->where('action_id',$gps['action_id'])->first();
    $original=json_decode($gpsReceipt->result,true);
    foreach([['mock_location'=>true],['recorded_at'=>$gps['recorded_at']-180000],['accuracy_m'=>51],['demo'=>true]] as $bad){
        DB::table('mapa_mobile_receipts')->where('id',$gpsReceipt->id)->update(['result'=>json_encode([...$original,'gps_evidence'=>[...$original['gps_evidence'],...$bad]])]);
        arrivalDenied(fn()=>$send($body()),422);
    }
    DB::table('mapa_mobile_receipts')->where('id',$gpsReceipt->id)->update(['result'=>json_encode([...$original,'_session_hash'=>str_repeat('0',64)])]);
    arrivalDenied(fn()=>$send($body()),422);
    DB::table('mapa_mobile_receipts')->where('id',$gpsReceipt->id)->update(['result'=>$gpsReceipt->result]);
    arrivalDenied(fn()=>$send([...$body(),'reported_at'=>$gps['recorded_at']-180000]),422);
    arrivalDenied(fn()=>$send([...$body(),'expected_revision'=>str_repeat('a',64)]),409);
    arrivalDenied(fn()=>$send($body('hospital_arrived')),409);
    $foreignOrder=arrivalRow('orders',['company_uuid'=>$foreign,'status'=>'started','dispatched'=>true,'driver_assigned_uuid'=>$driver,'vehicle_assigned_uuid'=>$vehicle]);
    $foreignId=DB::table('orders')->where('uuid',$foreignOrder)->value('public_id');
    arrivalDenied(fn()=>$send($body(),$token,$foreignId),404);
    $before=DB::table('orders')->where('uuid',$order)->first();$stateBefore=DB::table('mapa_mobile_order_states')->where('order_uuid',$order)->first();
    $failure=$body();$receiptCount=DB::table('mapa_mobile_receipts')->count();
    DB::statement('SET @mapa_arrival_qa_fail = 1');
    try{$send($failure);throw new RuntimeException('Expected injected SQL receipt failure');}catch(Illuminate\Database\QueryException $e){arrivalCheck(str_contains($e->getMessage(),'DEMO injected receipt failure'),'Injected receipt failure reached MySQL');}finally{DB::statement('SET @mapa_arrival_qa_fail = 0');}
    arrivalCheck(DB::table('orders')->where('uuid',$order)->value('meta')===$before->meta,'Metadata rolled back with failed receipt');
    arrivalCheck(DB::table('mapa_mobile_order_states')->where('order_uuid',$order)->value('assignment_hash')===$stateBefore->assignment_hash,'Acknowledgement hash rolled back');
    arrivalCheck(DB::table('mapa_mobile_receipts')->count()===$receiptCount,'Failed write leaves no receipt');
    $pickup=$body();$result=$send($pickup);
    arrivalCheck($result['arrival']['driver_uuid']===$driver&&$result['arrival']['vehicle_uuid']===$vehicle&&!$result['arrival']['independently_verified'],'Arrival stores scoped crew report without independent verification claim');
    arrivalCheck(DB::table('orders')->where('uuid',$order)->value('status')==='started'&&DB::table('tracking_statuses')->count()===0,'Arrival does not advance native stage or tracking');
    $meta=json_decode(DB::table('orders')->where('uuid',$order)->value('meta'),true);
    arrivalCheck($meta['untouched']==='retain'&&isset($meta['mapa_mobile_arrivals']['pickup_arrived']),'Arrival preserves existing metadata');
    arrivalCheck($service->inspect($order)['acknowledged'],'Arrival refreshes current acknowledgement signature');
    arrivalCheck($send($pickup)['replayed']===true&&!isset($send($pickup)['_session_hash']),'Exact retry returns receipt without private session hash');
    arrivalCheck(DB::table('mapa_mobile_receipts')->where('action_id',$pickup['action_id'])->count()===1,'Retry cannot duplicate receipt');
    arrivalDenied(fn()=>$send([...$pickup,'confirmed'=>false]),422);
    arrivalDenied(fn()=>$send([...$pickup,'reported_at'=>$pickup['reported_at']-1]),409);
    arrivalDenied(fn()=>$send($body()),409);
    // Native transport is fixture state, not a source/native transition caused by arrival.
    DB::table('orders')->where('uuid',$order)->update(['status'=>'enroute']);$ack();usleep(3000);
    $gps['action_id']=(string)Str::uuid();$gps['recorded_at']=(int)round(microtime(true)*1000);$service->mutate(arrivalRequest($gps,$token),'gps');
    $hospital=$body('hospital_arrived');$hospitalResult=$send($hospital);
    arrivalCheck($hospitalResult['arrival']['native_stage']==='enroute'&&$service->inspect($order)['status']==='enroute','Hospital report keeps transport native state');
    DB::table('orders')->where('uuid',$order)->update(['status'=>'completed']);
    arrivalCheck($send($hospital)['replayed']===true,'Lost receipt recovered after native completion');
    DB::table('mapa_mobile_order_states')->where('order_uuid',$order)->increment('acknowledged_at');
    arrivalDenied(fn()=>$send($hospital),409);
    DB::table('mapa_mobile_bindings')->where('id',$binding)->update(['revision'=>2]);
    arrivalDenied(fn()=>$send($hospital),401);
    DB::table('mapa_mobile_bindings')->where('id',$binding)->update(['role'=>'admin']);
    $staffToken=$service->login(arrivalRequest($credentials))['token'];
    arrivalDenied(fn()=>$send($hospital,$staffToken),403);
    DB::table('mapa_mobile_sessions')->where('token_hash',hash('sha256',$staffToken))->update(['revoked_at'=>(int)round(microtime(true)*1000)]);
    arrivalDenied(fn()=>$send($hospital,$staffToken),401);
    // Production QA image deliberately omits PHPUnit. Inspect fake collections
    // directly instead of facade assertion helpers which depend on PHPUnit.
    arrivalCheck(Http::recorded()->count()===0,'No outbound HTTP');
    foreach([[Notification::getFacadeRoot(),'notifications'],[Mail::getFacadeRoot(),'mailables'],[Mail::getFacadeRoot(),'queuedMailables']] as [$fake,$property]){
        $reflection=new ReflectionObject($fake);
        if(!$reflection->hasProperty($property))throw new RuntimeException('Cannot verify outbound fake collection: '.$property);
        $field=$reflection->getProperty($property);$field->setAccessible(true);$recorded=$field->getValue($fake);
        arrivalCheck(is_array($recorded)&&count($recorded)===0,'No outbound '.$property);
    }
} finally {
    while(DB::transactionLevel()>0)DB::rollBack();
    DB::statement('SET @mapa_arrival_qa_fail = 0');
    DB::unprepared('DROP TRIGGER IF EXISTS mapa_arrival_qa_receipt_fail');
}
arrivalCheck(DB::table('orders')->count()===0&&DB::table('mapa_mobile_receipts')->count()===0,'All disposable fixture records rolled back');
echo "MAPA_ARRIVALS_SUITE_COMPLETE: $count checks passed.\n";

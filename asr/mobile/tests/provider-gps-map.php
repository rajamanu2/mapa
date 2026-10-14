<?php
/** Isolated in-memory SQLite only. No application config, private file, network or operational database. */
namespace App\MapaMobile {
    class MobileService {public static function fail(string $message,int $status=403): void {throw new \Symfony\Component\HttpKernel\Exception\HttpException($status,$message);}}
}
namespace {
require $argv[1];require __DIR__.'/../ProviderGpsSnapshotPolicy.php';require __DIR__.'/../ProviderGpsSnapshotService.php';
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\MapaMobile\ProviderGpsSnapshotPolicy as P;
use App\MapaMobile\ProviderGpsSnapshotService as S;
$checks=0;function verify($ok,$label){global $checks;if(!$ok)throw new \RuntimeException($label);$checks++;}
function invalid(callable $fn){try{$fn();}catch(\UnexpectedValueException $e){verify(true,'invalid snapshot rejected');return;}throw new \RuntimeException('Expected invalid snapshot');}
$company='00000000-0000-4000-8000-000000000001';$foreign='00000000-0000-4000-8000-000000000002';$now=1791378000000;
$row=['external_id'=>'123456789012345','latitude'=>18.4,'longitude'=>82.9,'recorded_at'=>$now-1000,'provider_received_at'=>$now-500,
    'gps_on'=>true,'connected'=>true,'position_quality'=>'provider_reported','mapped'=>true,'provider_label'=>'DEMO provider unit'];
$snapshot=['contract'=>P::INPUT_CONTRACT,'company_id'=>$company,'generated_at'=>$now,'devices'=>[$row]];
$view=P::view($snapshot,$company,[],$now);$device=$view['devices'][0];
verify($view['contract']==='mapa-gps-map-v1'&&$view['company_id']===$company&&$view['server_time']===$now,'map contract explicitly scopes tenant and millisecond clock');
verify($device['location_state']==='fresh'&&!$device['stale']&&$view['counts']['plotted']===1,'recent provider coordinate is a visible reported marker');
verify(!$device['mapped']&&$device['vehicle_id']===null,'provider supplied mapping hint cannot create an ambulance association');
verify($device['dispatch_eligible']===false&&$device['position_quality']==='provider_reported'&&$device['accuracy_m']===null,'displayable point never implies accuracy or dispatch eligibility');
$telemetry=P::view([...$snapshot,'devices'=>[[...$row,'speed_kmh'=>42.5,'heading_degrees'=>173]]],$company,[],$now)['devices'][0];
verify($telemetry['speed_kmh']===42.5&&$telemetry['heading_degrees']===173.0,'bounded provider speed and heading appear as display-only values');
$telemetry=P::view([...$snapshot,'devices'=>[[...$row,'address'=>'Haritha Hill Resort','provider_status'=>'Stopped','ignition'=>'Off','external_power_v'=>12.73,'battery_percent'=>100.0,'gsm_percent'=>75.0]]],$company,[],$now)['devices'][0];
verify($telemetry['address']==='Haritha Hill Resort'&&$telemetry['provider_status']==='Stopped'&&$telemetry['ignition']==='Off'&&$telemetry['external_power_v']===12.73&&$telemetry['battery_percent']===100.0&&$telemetry['gsm_percent']===75.0,'observed provider telemetry reaches the display DTO');
$invalidTelemetry=P::view([...$snapshot,'devices'=>[[...$row,'speed_kmh'=>'42.5','heading_degrees'=>361]]],$company,[],$now)['devices'][0];
verify($invalidTelemetry['speed_kmh']===null&&$invalidTelemetry['heading_degrees']===null,'untrusted telemetry cannot enter the display DTO');
$invalidTelemetry=P::view([...$snapshot,'devices'=>[[...$row,'provider_status'=>'Immobilize','ignition'=>'maybe','external_power_v'=>-1,'battery_percent'=>101,'gsm_percent'=>'75']]],$company,[],$now)['devices'][0];
verify($invalidTelemetry['provider_status']===null&&$invalidTelemetry['ignition']===null&&$invalidTelemetry['external_power_v']===null&&$invalidTelemetry['battery_percent']===null&&$invalidTelemetry['gsm_percent']===null,'unexpected statuses and out-of-range telemetry stay absent');
foreach([['gps_on'=>false],['connected'=>false],['gps_on'=>null],['connected'=>null],['recorded_at'=>$now-86400000],['provider_received_at'=>null]] as $change){
    $v=P::view([...$snapshot,'devices'=>[[...$row,...$change]]],$company,[],$now)['devices'][0];
    verify($v['latitude']===18.4&&$v['location_state']==='last_known'&&$v['stale'],'offline or stale provider pin remains visible as last-known');
}
$old=P::view([...$snapshot,'generated_at'=>$now-121000],$company,[],$now);
verify($old['feed_stale']&&$old['devices'][0]['stale'],'old feed cannot advertise unchanged fresh GPS');
foreach([['latitude'=>0,'longitude'=>0],['latitude'=>91],['longitude'=>181],['latitude'=>null],['latitude'=>'18.4']] as $change){
    $v=P::view([...$snapshot,'devices'=>[[...$row,...$change]]],$company,[],$now);
    verify($v['counts']['total']===1&&$v['counts']['without_coordinates']===1&&$v['devices'][0]['latitude']===null,'invalid point retains device without inventing a map coordinate');
}
foreach([['recorded_at'=>null],['recorded_at'=>$now+30001]] as $change){
    $v=P::view([...$snapshot,'devices'=>[[...$row,...$change]]],$company,[],$now)['devices'][0];
    verify($v['latitude']===18.4&&$v['location_state']==='unknown'&&$v['stale'],'unknown/future fix time never claims live location');
}
invalid(fn()=>P::view($snapshot,$foreign,[],$now));
invalid(fn()=>P::view([...$snapshot,'contract'=>'other'],$company,[],$now));
invalid(fn()=>P::view([...$snapshot,'generated_at'=>$now+30001],$company,[],$now));
invalid(fn()=>P::view([...$snapshot,'devices'=>[$row,$row]],$company,[],$now));
invalid(fn()=>P::view([...$snapshot,'devices'=>[[...$row,'external_id'=>'other']]],$company,[],$now));
invalid(fn()=>P::view([...$snapshot,'devices'=>array_fill(0,1001,$row)],$company,[],$now));
$unsafe=P::view([...$snapshot,'cookie'=>'PRIVATE-DO-NOT-LEAK','devices'=>[[...$row,'secret'=>'PRIVATE-DO-NOT-LEAK','raw'=>['password'=>'PRIVATE-DO-NOT-LEAK']]]],$company,[],$now);
verify(!str_contains(json_encode($unsafe),'PRIVATE-DO-NOT-LEAK'),'read DTO cannot expose unexpected private provider fields');
$app=new \Illuminate\Container\Container;\Illuminate\Container\Container::setInstance($app);
$app->instance('config',new \Illuminate\Config\Repository([]));\Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$db=new \Illuminate\Database\Capsule\Manager($app);$db->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);$db->setAsGlobal();$db->bootEloquent();
$app->instance('db',$db->getDatabaseManager());$app->bind('db.schema',fn()=>$db->getConnection()->getSchemaBuilder());
Schema::create('mapa_tracking_devices',function($t){$t->string('id');$t->string('company_uuid');$t->string('external_id');$t->string('vehicle_uuid');$t->bigInteger('revoked_at')->nullable();});
Schema::create('vehicles',function($t){foreach(['uuid','company_uuid','public_id','plate_number','name','type','make','model','year','color','call_sign','status','meta'] as $c)$t->string($c)->nullable();$t->boolean('online')->nullable();$t->integer('seating_capacity')->nullable();$t->timestamp('deleted_at')->nullable();});
Schema::create('drivers',function($t){foreach(['uuid','company_uuid','public_id','vehicle_uuid','user_uuid'] as $c)$t->string($c);$t->timestamp('deleted_at')->nullable();});
Schema::create('users',function($t){foreach(['uuid','company_uuid','name'] as $c)$t->string($c);});
DB::table('vehicles')->insert(['uuid'=>'DEMO-vehicle','company_uuid'=>$company,'public_id'=>'DEMO-public','plate_number'=>'DEMO-QA-01','name'=>'DEMO provider mapping','type'=>'ambulance','make'=>'DEMO make','model'=>'DEMO model','year'=>'2026','color'=>'white','call_sign'=>'DEMO-01','status'=>'available','online'=>0,'seating_capacity'=>4,'meta'=>'{}']);
DB::table('users')->insert(['uuid'=>'DEMO-user','company_uuid'=>$company,'name'=>'DEMO Driver']);
DB::table('drivers')->insert(['uuid'=>'DEMO-driver','company_uuid'=>$company,'public_id'=>'DEMO-driver-id','vehicle_uuid'=>'DEMO-vehicle','user_uuid'=>'DEMO-user']);
DB::table('mapa_tracking_devices')->insert(['id'=>'DEMO-device','company_uuid'=>$company,'external_id'=>$row['external_id'],'vehicle_uuid'=>'DEMO-vehicle']);
$context=['company'=>$company,'binding'=>(object)['role'=>'admin']];$service=new S;
$mapped=$service->readSnapshot($context,$snapshot,$now)['devices'][0];
verify($mapped['mapped']&&$mapped['vehicle_id']==='DEMO-public'&&$mapped['registration']==='DEMO-QA-01','exact credential mapping resolves scoped ambulance identity');
verify($mapped['vehicle_type']==='ambulance'&&$mapped['vehicle_make']==='DEMO make'&&$mapped['vehicle_status']==='available'&&$mapped['vehicle_online']===false&&$mapped['seating_capacity']===4,'vehicle details come from the scoped MAPA record');
verify($mapped['driver_id']==='DEMO-driver-id'&&$mapped['driver_name']==='DEMO Driver','linked driver appears with the mapped vehicle');
Schema::create('mapa_dispatch_offers',function($t){foreach(['company_uuid','vehicle_uuid','state'] as $c)$t->string($c);$t->timestamp('expires_at');});
$offer=['company_uuid'=>$company,'vehicle_uuid'=>'DEMO-vehicle','state'=>'offered','expires_at'=>gmdate('Y-m-d H:i:s',(int)($now/1000)+30)];
DB::table('mapa_dispatch_offers')->insert($offer);
verify($service->readSnapshot($context,$snapshot,$now)['devices'][0]['offer_expires_at']===$now+30000,'unexpired native offer is exposed for this mapped vehicle');
DB::table('mapa_dispatch_offers')->update(['company_uuid'=>$foreign]);
verify($service->readSnapshot($context,$snapshot,$now)['devices'][0]['offer_expires_at']===null,'foreign offers cannot color this fleet');
DB::table('mapa_dispatch_offers')->update(['company_uuid'=>$company,'state'=>'accepted']);
verify($service->readSnapshot($context,$snapshot,$now)['devices'][0]['offer_expires_at']===null,'accepted offer is no longer pending');
DB::table('mapa_dispatch_offers')->update(['state'=>'offered','expires_at'=>gmdate('Y-m-d H:i:s',(int)($now/1000))]);
verify($service->readSnapshot($context,$snapshot,$now)['devices'][0]['offer_expires_at']===null,'expired offers disappear even if scheduler cleanup is delayed');
$spoof=$snapshot;$spoof['devices'][0]['offer_expires_at']=$now+30000;
verify($service->readSnapshot($context,$spoof,$now)['devices'][0]['offer_expires_at']===null,'provider file cannot assert a pending operational offer');
DB::table('drivers')->insert(['uuid'=>'DEMO-driver-two','company_uuid'=>$company,'public_id'=>'DEMO-driver-id-2','vehicle_uuid'=>'DEMO-vehicle','user_uuid'=>'DEMO-user']);
verify($service->readSnapshot($context,$snapshot,$now)['devices'][0]['driver_id']===null,'ambiguous driver assignments are not presented as one driver');
DB::table('drivers')->where('uuid','DEMO-driver-two')->delete();
DB::table('mapa_tracking_devices')->update(['revoked_at'=>$now]);
verify(!$service->readSnapshot($context,$snapshot,$now)['devices'][0]['mapped'],'revoked device never gains a native mapping');
DB::table('mapa_tracking_devices')->update(['revoked_at'=>null,'company_uuid'=>$foreign]);
verify(!$service->readSnapshot($context,$snapshot,$now)['devices'][0]['mapped'],'foreign tracker record cannot map this tenant');
DB::table('mapa_tracking_devices')->update(['company_uuid'=>$company]);
DB::table('vehicles')->update(['company_uuid'=>$foreign]);
verify(!$service->readSnapshot($context,$snapshot,$now)['devices'][0]['mapped'],'foreign native vehicle cannot map through tracker record');
DB::table('vehicles')->update(['company_uuid'=>$company,'meta'=>'{"mapa_demo":true}']);
verify(!$service->readSnapshot($context,$snapshot,$now)['devices'][0]['mapped'],'synthetic ambulance never links real provider telemetry');
DB::table('vehicles')->update(['meta'=>'{}','deleted_at'=>'2026-10-07 00:00:00']);
verify(!$service->readSnapshot($context,$snapshot,$now)['devices'][0]['mapped'],'deleted ambulance mapping is not exposed');
DB::table('vehicles')->update(['deleted_at'=>null]);
DB::table('mapa_tracking_devices')->insert(['id'=>'DEMO-duplicate','company_uuid'=>$company,'external_id'=>$row['external_id'],'vehicle_uuid'=>'DEMO-vehicle']);
verify(!$service->readSnapshot($context,$snapshot,$now)['devices'][0]['mapped'],'ambiguous duplicate mapping remains unresolved');
try{$service->readSnapshot(['company'=>$company,'binding'=>(object)['role'=>'crew']],$snapshot,$now);throw new \RuntimeException('Expected access denial');}
catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){verify($e->getStatusCode()===403,'crew cannot read entire provider fleet');}
DB::connection()->enableQueryLog();DB::connection()->flushQueryLog();$service->readSnapshot($context,$snapshot,$now);
$queries=array_column(DB::connection()->getQueryLog(),'query');
verify(count($queries)>0&&!array_filter($queries,fn($q)=>preg_match('/^\s*(insert|update|delete|replace)/i',$q)),'provider map read performs no database mutations');
echo "$checks provider GPS map checks passed.\n";
}

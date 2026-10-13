<?php
/** Run only in the fresh disposable QA network/container; never uses a live .env. */
if(getenv('MAPA_IMPORT_QA_ISOLATED')!=='1' || getenv('DB_DATABASE')!=='mapa_import_qa'
    || !preg_match('/^mapa-import-qa-db-[a-f0-9]{8}$/D',(string)getenv('DB_HOST'))
    || getenv('DB_USERNAME')!=='root' || getenv('DB_PASSWORD')!=='DEMO-import-qa-local-only') throw new RuntimeException('Dedicated empty QA database is required.');
require '/fleetbase/api/vendor/autoload.php';
$app=require '/fleetbase/api/bootstrap/app.php';
if(is_file($app->getCachedConfigPath()))throw new RuntimeException('Cached image config must be absent; do not bootstrap with unknown DB configuration.');
$app->useEnvironmentPath('/tmp/mapa-import-qa-no-env');
$app->useStoragePath('/tmp/mapa-import-qa-storage');
foreach(['logs','framework/cache','framework/sessions','framework/views','app'] as $dir) @mkdir($app->storagePath($dir),0700,true);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default'=>'mysql','database.connections.mysql.host'=>getenv('DB_HOST'),'database.connections.mysql.database'=>'mapa_import_qa',
    'database.connections.mysql.username'=>'root','database.connections.mysql.password'=>'DEMO-import-qa-local-only','database.connections.mysql.url'=>null,'database.connections.mysql.options'=>[PDO::ATTR_TIMEOUT=>2],
    'cache.default'=>'array','queue.default'=>'sync','mail.default'=>'array','broadcasting.default'=>'log']);
Illuminate\Support\Facades\DB::purge('mysql');
$connected=false;
for($i=0;$i<120;$i++){try{Illuminate\Support\Facades\DB::connection()->getPdo();$connected=true;break;}catch(Throwable $e){$lastConnectionError=$e->getMessage();Illuminate\Support\Facades\DB::purge('mysql');usleep(1000000);}}
if(!$connected) throw new RuntimeException('Disposable QA database unavailable: '.($lastConnectionError??'unknown')); 
if(Illuminate\Support\Facades\DB::select('SHOW TABLES')!==[])throw new RuntimeException('QA database must start empty; do not reuse an existing database.');
Illuminate\Support\Facades\Http::fake();Illuminate\Support\Facades\Mail::fake();Illuminate\Support\Facades\Notification::fake();Illuminate\Support\Facades\Queue::fake();Illuminate\Support\Facades\Bus::fake();
$result=Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true,'--database'=>'mysql','--realpath'=>true,'--path'=>[
    '/fleetbase/api/database/migrations',
    '/fleetbase/api/vendor/laravel/sanctum/database/migrations',
    '/fleetbase/api/vendor/fleetbase/core-api/migrations',
    '/fleetbase/api/vendor/fleetbase/fleetops-api/server/migrations',
    '/fleetbase/api/app/MapaMobile/migrations',
]]);
if($result!==0){echo Illuminate\Support\Facades\Artisan::output();throw new RuntimeException('Disposable native migration failed.');}
echo 'Initialized disposable native schema from cached source; zero production connections.',PHP_EOL;

require __DIR__.'/../ImportPlan.php';
require __DIR__.'/../ImportApply.php';
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use ASR\Import\ImportPlan;
use ASR\Import\ImportApply;
set_exception_handler(function(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);exit(1);});
Event::fake();
config(['mapa.company_uuid'=>ImportPlan::COMPANY]);
$checks=0;
function verify(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function expectFailure(callable $fn,string $contains):void{try{$fn();throw new LogicException('Expected failure');}catch(RuntimeException|InvalidArgumentException $e){verify(str_contains($e->getMessage(),$contains),'Unexpected failure: '.$e->getMessage());}}
function counts():array{$out=[];foreach(['users','drivers','mapa_mobile_bindings','mapa_mobile_sessions','positions','orders','places','vehicles'] as $t)$out[$t]=DB::table($t)->count();return $out;}
function hospital(string $base,string $institution='PHC',float $latitude=18.1,float $longitude=82.6):array{
    $key=hash('sha256',strtolower($institution)."\0".strtolower($base));
    return ['key'=>$key,'status'=>'create','source_rows'=>[2],'institution_type'=>$institution,'base_name'=>$base,'latitude'=>$latitude,'longitude'=>$longitude,
        'context'=>[['secretariat'=>'DEMO Secretariat','mandal'=>'DEMO Mandal','date_mapped'=>'2026-09-30']],
        'native'=>['name'=>ImportPlan::hospitalName($institution,$base),'type'=>'hospital']];
}
function ambulance(string $registration,?string $hospitalKey,string $type='104 MMU'):array{
    return ['key'=>ImportPlan::plate($registration),'status'=>'create','source_rows'=>[2],'hospital_key'=>$hospitalKey,'source_vehicle_type'=>$type,
        'context'=>[['secretariat'=>'DEMO Secretariat','mandal'=>'DEMO Mandal','date_mapped'=>'2026-09-30']],
        'native'=>['name'=>'DEMO '.$type.' '.$registration,'plate_number'=>$registration,'type'=>$type,'online'=>false,'status'=>'available']];
}
function plan(array $hospitals,array $vehicles):array{return ['contract'=>'asr-source-resource-plan-v1','mode'=>'dry_run','company_uuid'=>ImportPlan::COMPANY,
    'source_origin'=>'https://asrhealth.in','snapshot_compared'=>true,'driver_import_allowed'=>false,'drivers_staged'=>[['status'=>'staged_only','source'=>['name'=>'DEMO private staged driver']]],
    'hospitals'=>$hospitals,'vehicles'=>$vehicles,'fingerprints'=>['snapshot_sha256'=>str_repeat('1',64),'csv_sha256'=>str_repeat('2',64),'drivers_sha256'=>str_repeat('3',64),'staging_source_sha256'=>str_repeat('4',64)]];}
function applyPlan(array $plan):array{$raw=json_encode($plan,JSON_THROW_ON_ERROR);$digest=hash('sha256',$raw);$parsed=ImportPlan::unpack(['ack'=>'REVIEWED_RESOURCE_CREATION_ONLY','plan_base64'=>base64_encode($raw),'expected_plan_sha256'=>$digest,'expected_snapshot_sha256'=>str_repeat('1',64)]);return ImportApply::apply($parsed,$digest);}
function fixtureCompany(string $uuid,string $name):void{DB::table('companies')->insert(['uuid'=>$uuid,'public_id'=>'company_DEMO_'.str_replace('-','',$uuid),'name'=>$name,'meta'=>json_encode(['mapa_demo'=>true]),'created_at'=>now(),'updated_at'=>now()]);}
function fixtureVehicle(string $registration,array $extra=[]):Vehicle{$v=new Vehicle;$v->forceFill(['uuid'=>(string)Str::uuid(),'public_id'=>'vehicle_DEMO_'.bin2hex(random_bytes(8)),'company_uuid'=>ImportPlan::COMPANY,
    'name'=>'DEMO preexisting vehicle','plate_number'=>$registration,'type'=>'DEMO native ALS','online'=>true,'status'=>'maintenance','location'=>new Point(18.9,83.1),
    'meta'=>['mapa_demo'=>false,'DEMO_qa_only'=>'synthetic-native-fixture'],...$extra]);$v->saveQuietly();return $v->fresh();}
fixtureCompany(ImportPlan::COMPANY,'DEMO isolated ASR import company');
$foreign=(string)Str::uuid();fixtureCompany($foreign,'DEMO isolated foreign company');
$initial=counts();
$h=hospital('DEMO Base');$v=ambulance('AP99QA1001',$h['key']);$p=plan([$h],[$v]);
$r=applyPlan($p);
verify($r['counts']['created_hospitals']===1&&$r['counts']['created_vehicles']===1,'Native source apply creates one hospital and one vehicle');
verify($r['plan_sha256']===hash('sha256',json_encode($p,JSON_THROW_ON_ERROR)),'Receipt retains exact reviewed digest');
$place=Place::withoutGlobalScopes()->where('company_uuid',ImportPlan::COMPANY)->firstOrFail();
$vehicle=Vehicle::withoutGlobalScopes()->where('company_uuid',ImportPlan::COMPANY)->firstOrFail();
verify($place->type==='hospital'&&$place->name==='PHC DEMO Base','Native hospital identity preserves institution prefix');
verify(abs($place->location->getLat()-18.1)<1e-9&&abs($place->location->getLng()-82.6)<1e-9,'Native hospital uses actual source base coordinates');
verify(data_get($place->meta,'asr_import.coordinate_semantics')==='source_hospital_or_parking_base_not_live_gps','Hospital point explicitly is base coordinate');
verify($vehicle->type==='104 MMU','Native source vehicle type preserved');
verify($vehicle->online===false&&$vehicle->status==='available','Source vehicle remains offline');
verify(DB::table('vehicles')->where('uuid',$vehicle->uuid)->whereNull('location')->exists()&&$vehicle->location===null,'Vehicle SQL NULL location is retained by native model');
verify(data_get($vehicle->meta,'asr_import.coordinate_semantics')==='no_vehicle_location_imported','No hospital point mislabeled vehicle GPS');
verify(data_get($vehicle->meta,'asr_import.import_scope')==='inventory_only'&&data_get($vehicle->meta,'asr_import.operational_status_verified')===false,'Source vehicle is inventory only with unverified operational availability');
verify(json_decode(json_encode(data_get($vehicle->meta,'asr_import.source_context')),true)==$v['context']&&json_decode(json_encode(data_get($place->meta,'asr_import.source_context')),true)==$h['context'],'Allowlisted mapping context retained separately from GPS');
verify(data_get($vehicle->meta,'asr_import.source_parking_base_place_uuid')===$place->uuid,'Parking-base identity metadata linked without GPS/driver writes');
foreach(['users','drivers','mapa_mobile_bindings','mapa_mobile_sessions','positions','orders'] as $table)verify(counts()[$table]===$initial[$table],$table.' unchanged by import');
$request=Illuminate\Http\Request::create('/int/v1/vehicles');app()->instance('request',$request);
$serialized=(new Fleetbase\FleetOps\Http\Resources\Internal\v1\Vehicle($vehicle))->resolve($request);
verify($serialized['online']===false&&array_key_exists('location',$serialized)&&$serialized['location']===null,'Native internal Vehicle resource serializes unknown offline location without fake point');
verify($serialized['type']==='104 MMU','Native Vehicle list preserves source vehicle type');
$indexSerialized=(new Fleetbase\FleetOps\Http\Resources\v1\Index\Vehicle($vehicle))->resolve($request);
verify($indexSerialized['location']===null&&data_get($indexSerialized,'meta.location_coordinates')===null,'Native lightweight Vehicle index keeps both point and coordinates unknown');
$monitorReflection=new ReflectionMethod(Fleetbase\FleetOps\Http\Controllers\Internal\v1\LiveController::class,'serializeMonitorVehicle');$monitorReflection->setAccessible(true);
$monitorInstance=(new ReflectionClass(Fleetbase\FleetOps\Http\Controllers\Internal\v1\LiveController::class))->newInstanceWithoutConstructor();
$monitor=$monitorReflection->invoke($monitorInstance,$vehicle);verify($monitor['location']===null&&$monitor['online']===false,'Native operations sidebar monitor preserves unknown offline vehicle location');
$publicSerialized=(new Fleetbase\FleetOps\Http\Resources\v1\Vehicle($vehicle))->resolve($request);
verify(array_key_exists('location',$publicSerialized)&&$publicSerialized['location']===null,'Native public Vehicle resource preserves unknown location');
verify((new Fleetbase\FleetOps\Http\Resources\v1\Vehicle($vehicle))->toWebhookPayload()['location']===null&&(new Fleetbase\FleetOps\Http\Resources\v1\VehicleWithoutDriver($vehicle))->toWebhookPayload()['location']===null,'Both native webhook resource shapes preserve unknown location');
$afterFirst=counts();$placeBefore=DB::table('places')->where('uuid',$place->uuid)->first();$vehicleBefore=DB::table('vehicles')->where('uuid',$vehicle->uuid)->first();
$replay=applyPlan($p);verify($replay['counts']['created_hospitals']===0&&$replay['counts']['created_vehicles']===0&&$replay['counts']['preserved_hospitals']===1&&$replay['counts']['preserved_vehicles']===1,'Exact lost-response replay preserves same native resources');
verify(counts()===$afterFirst,'Replay creates no duplicate resources or identities');
verify(DB::table('places')->where('uuid',$place->uuid)->first()==$placeBefore&&DB::table('vehicles')->where('uuid',$vehicle->uuid)->first()==$vehicleBefore,'Replay leaves exact native rows unchanged');
$live=fixtureVehicle('AP-99 QA-2002');$before=DB::table('vehicles')->where('uuid',$live->uuid)->first();
$race=applyPlan(plan([], [ambulance('AP99QA2002',null,'BLS')]));
verify($race['counts']['preserved_vehicles']===1&&$race['counts']['created_vehicles']===0,'Current normalized plate preserves native resource added after snapshot');
verify(DB::table('vehicles')->where('uuid',$live->uuid)->first()==$before,'Existing type, online flag, location and metadata remain unchanged');
$liveJson=(new Fleetbase\FleetOps\Http\Resources\Internal\v1\Vehicle($live))->resolve($request);
verify($liveJson['location'] instanceof Point&&abs($liveJson['location']->getLat()-18.9)<1e-9&&abs($liveJson['location']->getLng()-83.1)<1e-9,'Serializer null guard preserves actual existing coordinates');
$foreignVehicle=fixtureVehicle('AP99QA3003',['company_uuid'=>$foreign]);$scope=applyPlan(plan([], [ambulance('AP99QA3003',null,'ALS')]));
verify($scope['counts']['created_vehicles']===1&&DB::table('vehicles')->where('plate_number','AP99QA3003')->count()===2,'Foreign-company identity does not block or overwrite scoped create');
$deleted=fixtureVehicle('AP99QA4004',['deleted_at'=>now()]);$beforeCollision=counts();$rollbackHospital=hospital('DEMO Rollback Base');
expectFailure(fn()=>applyPlan(plan([$rollbackHospital],[ambulance('AP99QA4004',$rollbackHospital['key'])])),'Vehicle collision changed');
verify(counts()===$beforeCollision&&!Place::withoutGlobalScopes()->where('name','PHC DEMO Rollback Base')->exists(),'Late normalized deleted-plate collision rolls back earlier hospital create atomically');
$dem=fixtureVehicle('AP99QA5005',['meta'=>['mapa_demo'=>true]]);$beforeDemo=counts();
expectFailure(fn()=>applyPlan(plan([hospital('DEMO Reject Demo Base')],[ambulance('AP99QA5005',null)])),'Vehicle collision changed');
verify(counts()===$beforeDemo,'Training vehicle collision rolls back entire transaction');
$ambiguity=new Place;$ambiguity->forceFill(['uuid'=>(string)Str::uuid(),'public_id'=>'place_DEMO_'.bin2hex(random_bytes(8)),'company_uuid'=>ImportPlan::COMPANY,'name'=>'DEMO Ambiguous Base','type'=>'hospital','location'=>new Point(18.3,82.8),'meta'=>['mapa_demo'=>false,'DEMO_qa_only'=>true]])->saveQuietly();
$beforeAmbiguous=counts();expectFailure(fn()=>applyPlan(plan([hospital('DEMO Ambiguous Base')],[])),'Existing hospital needs institution review');verify(counts()===$beforeAmbiguous,'Untyped bare-name collision preserves native hospital');
$tierA=hospital('DEMO Tier Base','PHC',18.4,82.9);$tierB=hospital('DEMO Tier Base','CHC',18.5,83.0);$tiers=applyPlan(plan([$tierA,$tierB],[]));
verify($tiers['counts']['created_hospitals']===2,'Distinct reviewed institution tiers sharing a base name do not merge or abort');
verify(Place::withoutGlobalScopes()->whereIn('name',['PHC DEMO Tier Base','CHC DEMO Tier Base'])->count()===2,'Hospital institution prefixes appear as distinct native names');
$missing=['key'=>hash('sha256','DEMO missing'),'status'=>'deferred','reason'=>'missing_coordinates'];$skip=applyPlan(plan([$missing],[]));verify($skip['counts']['created_hospitals']===0&&$skip['counts']['deferred_hospitals']===1,'Missing coordinates do not invent native hospital point');
$beforeGuard=counts();$tampered=$p;$tampered['vehicles'][0]['native']['location']=['latitude'=>18.1,'longitude'=>82.6];expectFailure(fn()=>applyPlan($tampered),'Only allowlisted native resource fields');verify(counts()===$beforeGuard,'Mass-assigned vehicle location rejected before writes');
$wrong=$p;$wrong['driver_import_allowed']=true;expectFailure(fn()=>applyPlan($wrong),'Wrong scope or non-resource');
config(['mapa.company_uuid'=>$foreign]);expectFailure(fn()=>applyPlan($p),'Target company mismatch');config(['mapa.company_uuid'=>ImportPlan::COMPANY]);verify(counts()===$beforeGuard,'Foreign runtime target does not mutate resources');
foreach(['users','drivers','mapa_mobile_bindings','mapa_mobile_sessions','positions','orders'] as $table)verify(counts()[$table]===0,$table.' still empty after all source applies');
verify(DB::transactionLevel()===0,'No transaction remains open after accepted or rejected source apply');
echo $checks.' native import checks passed; isolated actual native schema, zero production connections.',PHP_EOL;

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


require __DIR__.'/../ImportPlan.php';require __DIR__.'/../SupplementPlan.php';require __DIR__.'/../SupplementApply.php';
use Illuminate\Support\Facades\{DB,Event};
use Illuminate\Support\Str;
use ASR\Import\{ImportPlan,SupplementPlan,SupplementApply};
use Fleetbase\FleetOps\Models\{Vehicle,Place,Driver};
use Fleetbase\LaravelMysqlSpatial\Types\Point;
set_exception_handler(function(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);exit(1);});
Event::fake();config(['mapa.company_uuid'=>ImportPlan::COMPANY]);
$checks=0;
function verify(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function reject(callable $fn,string $expected):void{try{$fn();throw new LogicException('Expected rejection');}catch(RuntimeException|InvalidArgumentException $e){verify(str_contains($e->getMessage(),$expected),'Unexpected rejection: '.$e->getMessage());}}
function counts():array{$out=[];foreach(['companies','users','drivers','mapa_mobile_bindings','mapa_mobile_sessions','mapa_mobile_enrollments','mapa_mobile_trip_reports','positions','orders','places','vehicles'] as $t)$out[$t]=DB::table($t)->count();return $out;}
function fixtureCompany(string $uuid):void{DB::table('companies')->insert(['uuid'=>$uuid,'public_id'=>'company_DEMO_'.str_replace('-','',$uuid),'name'=>'DEMO isolated supplement company','meta'=>json_encode(['mapa_demo'=>true]),'created_at'=>now(),'updated_at'=>now()]);}
function hospital(string $base,string $institution='PHC',float $lat=18.1,float $lng=82.7):array{
 $name=ImportPlan::hospitalName($institution,$base);
 return ['hospital_key'=>hash('sha256',strtolower($institution)."\0".strtolower($base)),'institution_type'=>$institution,'base_name'=>$base,'name'=>$name,'identity'=>'DEMO public research '.$base,
   'latitude'=>$lat,'longitude'=>$lng,'address'=>'DEMO verified base address '.$base,'google_url'=>'https://www.google.com/maps/place/DEMO+offline+fixture/data=!3d'.$lat.'!4d'.$lng,
   'official_sources'=>[['url'=>'https://example.invalid/DEMO-offline-government-fixture','supports'=>'DEMO synthetic fixture, never contacted']],
   'conflicts'=>[],'confidence'=>'DEMO isolated testing only','source_rows'=>[2],'source_context'=>[['secretariat'=>'DEMO Secretariat','mandal'=>'DEMO Mandal','date_mapped'=>'2026-10-01']],
   'collision_names'=>[$name,$base,'DEMO public research '.$base],'coordinate_semantics'=>'researched_hospital_facility_pin_not_live_gps','ground_surveyed'=>false];
}
function plan(array $hospitals,array $links=[]):array{return ['contract'=>'asr-hospital-pin-supplement-v1','mode'=>'review_before_apply','company_uuid'=>ImportPlan::COMPANY,
 'source_origin'=>'https://asrhealth.in','original_plan_sha256'=>SupplementPlan::ORIGINAL_SHA,'source_csv_sha256'=>str_repeat('2',64),'evidence_sha256'=>str_repeat('3',64),
 'hospitals'=>$hospitals,'vehicle_links'=>$links,'unresolved'=>[['hospital_key'=>hash('sha256','DEMO unresolved'),'reason'=>'DEMO unknown pin remains unresolved']]];}
function inventoryLink(string $plate,array $hospital):array{return ['vehicle_key'=>ImportPlan::plate($plate),'hospital_key'=>$hospital['hospital_key']];}
function applyPlan(array $plan):array{$raw=json_encode($plan,JSON_THROW_ON_ERROR);$digest=hash('sha256',$raw);$parsed=SupplementPlan::unpack(['ack'=>'REVIEWED_HOSPITAL_PIN_SUPPLEMENT','plan_base64'=>base64_encode($raw),'expected_plan_sha256'=>$digest]);return SupplementApply::apply($parsed,$digest);}
function importedVehicle(string $plate,array $hospital,array $extra=[]):Vehicle{
 $v=new Vehicle;$v->forceFill(['uuid'=>(string)Str::uuid(),'public_id'=>'vehicle_DEMO_'.bin2hex(random_bytes(8)),'_key'=>'asr-source-import-v1','company_uuid'=>ImportPlan::COMPANY,
   'name'=>'DEMO source inventory '.$plate,'plate_number'=>$plate,'type'=>'DEMO native ALS','online'=>true,'status'=>'maintenance','location'=>new Point(18.9,83.1),
   'meta'=>['mapa_demo'=>false,'simulated'=>false,'DEMO_qa_only'=>true,'retained'=>['live_operator_note'=>'DEMO retained value'],
    'asr_import'=>['origin'=>'https://asrhealth.in','source_csv_sha256'=>str_repeat('2',64),'vehicle_key'=>ImportPlan::plate($plate),'hospital_key'=>$hospital['hospital_key'],
       'source_parking_base_place_uuid'=>null,'import_scope'=>'inventory_only','operational_status_verified'=>false,'coordinate_semantics'=>'no_vehicle_location_imported']],...$extra]);
 $v->saveQuietly();return $v->fresh();
}
function rawVehicle(Vehicle $v):object{return DB::table('vehicles')->where('uuid',$v->uuid)->first();}
function addMeta(Vehicle $v,string $key,mixed $value):void{DB::update('UPDATE vehicles SET meta=JSON_SET(meta,?,CAST(? AS JSON)) WHERE uuid=?',[$key,json_encode($value,JSON_THROW_ON_ERROR),$v->uuid]);}
function existingPlace(array $h,array $extra=[]):Place{$p=new Place;$p->forceFill(['uuid'=>(string)Str::uuid(),'public_id'=>'place_DEMO_'.bin2hex(random_bytes(8)),'company_uuid'=>ImportPlan::COMPANY,'name'=>$h['name'],'type'=>'hospital','street1'=>$h['address'],'location'=>new Point($h['latitude'],$h['longitude']),'meta'=>['mapa_demo'=>false,'DEMO_qa_only'=>true],...$extra])->saveQuietly();return $p->fresh();}
fixtureCompany(ImportPlan::COMPANY);$foreign=(string)Str::uuid();fixtureCompany($foreign);
$h=hospital('DEMO First Facility');$vehicle=importedVehicle('AP99QA1001',$h);
$user=(string)Str::uuid();DB::table('users')->insert(['uuid'=>$user,'public_id'=>'user_DEMO_'.bin2hex(random_bytes(8)),'company_uuid'=>ImportPlan::COMPANY,'name'=>'DEMO preserved actual-schema crew fixture','email'=>'demo-supplement@example.invalid','password'=>'DEMO no-login password hash','status'=>'active','type'=>'driver','meta'=>json_encode(['mapa_demo'=>true]),'created_at'=>now(),'updated_at'=>now()]);
$driver=new Driver;$driver->forceFill(['uuid'=>(string)Str::uuid(),'public_id'=>'driver_DEMO_'.bin2hex(random_bytes(8)),'company_uuid'=>ImportPlan::COMPANY,'user_uuid'=>$user,'vehicle_uuid'=>$vehicle->uuid,'location'=>new Point(18.9,83.1),'online'=>true])->saveQuietly();
$binding=(string)Str::uuid();DB::table('mapa_mobile_bindings')->insert(['id'=>$binding,'company_uuid'=>ImportPlan::COMPANY,'user_uuid'=>$user,'role'=>'crew','driver_uuid'=>$driver->uuid,'enabled'=>true,'revision'=>1,'created_at'=>now(),'updated_at'=>now()]);
$initial=counts();$beforeVehicle=rawVehicle($vehicle);$beforeDriver=DB::table('drivers')->where('uuid',$driver->uuid)->first();$beforeUser=DB::table('users')->where('uuid',$user)->first();$beforeBinding=DB::table('mapa_mobile_bindings')->where('id',$binding)->first();
$p=plan([$h],[inventoryLink($vehicle->plate_number,$h)]);$receipt=applyPlan($p);
verify($receipt['counts']['created_hospitals']===1&&$receipt['counts']['linked_inventory_metadata']===1,'Actual native supplement creates verified Place and links existing inventory metadata');
verify($receipt['counts']['unresolved_hospitals']===1&&$receipt['counts']['vehicles_created']===0,'Unresolved identity remains uncreated, no vehicle created');
verify($receipt['plan_sha256']===hash('sha256',json_encode($p,JSON_THROW_ON_ERROR)),'Receipt retains immutable exact reviewed digest');
$place=Place::withoutGlobalScopes()->where('company_uuid',ImportPlan::COMPANY)->where('name',$h['name'])->firstOrFail();
verify($place->type==='hospital'&&$place->street1===$h['address']&&$place->_key==='asr-source-import-v1','Native hospital identity/address/provenance marker retained');
verify(abs($place->location->getLat()-$h['latitude'])<1e-8&&abs($place->location->getLng()-$h['longitude'])<1e-8,'Actual named facility pin persisted as hospital Point');
verify(data_get($place->meta,'asr_import.coordinate_semantics')==='researched_hospital_facility_pin_not_live_gps'&&data_get($place->meta,'asr_import.web_supplement.ground_surveyed')===false,'Hospital coordinate explicitly researched facility pin, not live GPS/survey/gate');
verify(data_get($place->meta,'asr_import.web_supplement.google_url')===$h['google_url']&&data_get($place->meta,'asr_import.web_supplement.evidence_sha256')===$p['evidence_sha256'],'Exact research provenance retained');
$after=rawVehicle($vehicle);$beforeNoMeta=clone $beforeVehicle;$afterNoMeta=clone $after;unset($beforeNoMeta->meta,$afterNoMeta->meta);
verify($beforeNoMeta==$afterNoMeta,'All native vehicle fields including GPS/duty/status/type/updated_at remain byte-identical');
$expected=json_decode($beforeVehicle->meta,true);$expected['asr_import']['source_parking_base_place_uuid']=$place->uuid;
verify(json_decode($after->meta,true)===$expected || json_decode($after->meta,true)==$expected,'Exactly one nested vehicle inventory metadata value changed');
verify(DB::table('drivers')->where('uuid',$driver->uuid)->first()==$beforeDriver&&DB::table('users')->where('uuid',$user)->first()==$beforeUser&&DB::table('mapa_mobile_bindings')->where('id',$binding)->first()==$beforeBinding,'Existing actual-schema crew/user/binding untouched');
foreach($initial as $table=>$count)verify(counts()[$table]===$count+($table==='places'?1:0),$table.' count changed only for newly verified hospital');
$afterFirst=counts();$placeBefore=DB::table('places')->where('uuid',$place->uuid)->first();$vehicleBefore=rawVehicle($vehicle);$replay=applyPlan($p);
verify($replay['counts']['replayed_hospitals']===1&&$replay['counts']['replayed_inventory_links']===1&&$replay['counts']['created_hospitals']===0&&$replay['counts']['linked_inventory_metadata']===0,'Exact lost-response replay is idempotent');
verify(counts()===$afterFirst&&rawVehicle($vehicle)==$vehicleBefore&&DB::table('places')->where('uuid',$place->uuid)->first()==$placeBefore,'Exact replay leaves resources and timestamps unchanged');
$unknown=hospital('DEMO Offline Unknown');$unknownV=importedVehicle('AP99QA1002',$unknown);DB::table('vehicles')->where('uuid',$unknownV->uuid)->update(['location'=>null,'online'=>false]);$unknownBefore=rawVehicle($unknownV);
applyPlan(plan([$unknown],[inventoryLink($unknownV->plate_number,$unknown)]));$unknownAfter=rawVehicle($unknownV);verify($unknownAfter->location===null&&$unknownAfter->online==0,'Linking hospital pin never invents offline vehicle location');
$unknownBeforeMeta=clone $unknownBefore;$unknownAfterMeta=clone $unknownAfter;unset($unknownBeforeMeta->meta,$unknownAfterMeta->meta);verify($unknownBeforeMeta==$unknownAfterMeta,'Unknown-location vehicle operational fields unchanged');
$foreignH=hospital('DEMO Foreign Same Identity');existingPlace($foreignH,['company_uuid'=>$foreign]);$foreignResult=applyPlan(plan([$foreignH]));verify($foreignResult['counts']['created_hospitals']===1&&Place::withoutGlobalScopes()->where('name',$foreignH['name'])->count()===2,'Foreign company identity does not block or overwrite scoped hospital create');
$aliasH=hospital('DEMO Existing Alias');$aliasPlace=existingPlace($aliasH,['name'=>$aliasH['identity']]);$beforeAlias=counts();reject(fn()=>applyPlan(plan([$aliasH])),'alias or typed identity collision');verify(counts()===$beforeAlias&&$aliasPlace->fresh()->name===$aliasH['identity'],'Existing untyped alias collision preserves native hospital');
$changed=$p;$changed['hospitals'][0]['address'].=' changed';$beforeChanged=counts();reject(fn()=>applyPlan($changed),'Existing researched hospital changed');verify(counts()===$beforeChanged&&DB::table('places')->where('uuid',$place->uuid)->first()==$placeBefore,'Changed replay body does not reuse or overwrite existing researched hospital');
DB::table('places')->where('uuid',$place->uuid)->update(['deleted_at'=>now()]);reject(fn()=>applyPlan($p),'Existing researched hospital changed');DB::table('places')->where('uuid',$place->uuid)->update(['deleted_at'=>null]);
$good=hospital('DEMO Rollback First');$bad=hospital('DEMO Rollback Later');$goodV=importedVehicle('AP99QA2001',$good);$badV=importedVehicle('AP99QA2002',$bad);addMeta($badV,'$.asr_import.source_csv_sha256',str_repeat('9',64));$beforeRollback=counts();$beforeGood=rawVehicle($goodV);
reject(fn()=>applyPlan(plan([$good,$bad],[inventoryLink($goodV->plate_number,$good),inventoryLink($badV->plate_number,$bad)])),'provenance baseline changed');
verify(counts()===$beforeRollback&&rawVehicle($goodV)==$beforeGood&&!Place::withoutGlobalScopes()->whereIn('name',[$good['name'],$bad['name']])->exists(),'Late provenance rejection rolls back both hospital creation and earlier JSON metadata update');
$occupied=hospital('DEMO Occupied Link');$occupiedV=importedVehicle('AP99QA3001',$occupied);addMeta($occupiedV,'$.asr_import.source_parking_base_place_uuid',$foreign);$beforeOccupied=counts();$occupiedBefore=rawVehicle($occupiedV);reject(fn()=>applyPlan(plan([$occupied],[inventoryLink($occupiedV->plate_number,$occupied)])),'Existing source base link changed');verify(counts()===$beforeOccupied&&rawVehicle($occupiedV)==$occupiedBefore,'Existing different source base link is never overwritten');
foreach(['deleted','training','unimported','duplicate'] as $case){$denyH=hospital('DEMO '.$case.' Collision');$denyV=importedVehicle('AP99QA'.(4001+array_search($case,['deleted','training','unimported','duplicate'])),$denyH);
 if($case==='deleted')DB::table('vehicles')->where('uuid',$denyV->uuid)->update(['deleted_at'=>now()]);
 if($case==='training')addMeta($denyV,'$.mapa_demo',true);
 if($case==='unimported')DB::table('vehicles')->where('uuid',$denyV->uuid)->update(['_key'=>null]);
 if($case==='duplicate')importedVehicle($denyV->plate_number,$denyH);
 $beforeDeny=counts();reject(fn()=>applyPlan(plan([$denyH],[inventoryLink($denyV->plate_number,$denyH)])),$case==='duplicate'?'missing or duplicated':'provenance baseline changed');verify(counts()===$beforeDeny&&!Place::withoutGlobalScopes()->where('name',$denyH['name'])->exists(),$case.' collision leaves no hospital residue');
}
$missing=hospital('DEMO Missing Vehicle');$beforeMissing=counts();reject(fn()=>applyPlan(plan([$missing],[inventoryLink('AP99QA9999',$missing)])),'missing or duplicated');verify(counts()===$beforeMissing,'Missing reviewed inventory vehicle leaves no new place');
$foreignOnly=hospital('DEMO Foreign Vehicle Only');$foreignV=importedVehicle('AP99QA9001',$foreignOnly,['company_uuid'=>$foreign]);$beforeForeign=counts();reject(fn()=>applyPlan(plan([$foreignOnly],[inventoryLink($foreignV->plate_number,$foreignOnly)])),'missing or duplicated');verify(counts()===$beforeForeign,'Foreign vehicle cannot receive this tenant metadata or cause partial hospital creation');
$wrong=$p;$wrong['company_uuid']=$foreign;reject(fn()=>applyPlan($wrong),'Supplement scope mismatch');config(['mapa.company_uuid'=>$foreign]);reject(fn()=>applyPlan($p),'runtime scope mismatch');config(['mapa.company_uuid'=>ImportPlan::COMPANY]);
$badPin=$p;$badPin['hospitals'][0]['latitude']=0;$badPin['hospitals'][0]['longitude']=0;reject(fn()=>applyPlan($badPin),'nonzero hospital coordinates');
$viewport=$p;$viewport['hospitals'][0]['google_url']='https://www.google.com/maps/place/DEMO/@18.1,82.7,17z';reject(fn()=>applyPlan($viewport),'Named Google facility point');
$badDigest=['ack'=>'REVIEWED_HOSPITAL_PIN_SUPPLEMENT','plan_base64'=>base64_encode(json_encode($p,JSON_THROW_ON_ERROR)),'expected_plan_sha256'=>str_repeat('0',64)];reject(fn()=>SupplementPlan::unpack($badDigest),'Exact reviewed supplement fingerprint');
verify(DB::table('positions')->count()===0&&DB::table('orders')->count()===0&&DB::table('mapa_mobile_enrollments')->count()===0&&DB::table('mapa_mobile_sessions')->count()===0&&DB::table('mapa_mobile_trip_reports')->count()===0,'No GPS/dispatch/enrollment/session/evidence rows written by any supplement');
verify(DB::table('users')->where('uuid',$user)->first()==$beforeUser&&DB::table('drivers')->where('uuid',$driver->uuid)->first()==$beforeDriver&&DB::table('mapa_mobile_bindings')->where('id',$binding)->first()==$beforeBinding,'Existing driver/account/approved binding retained through all rejection cases');
verify(DB::transactionLevel()===0,'No transaction remains open after supplement success or failure');
echo $checks.' native supplement checks passed; isolated actual native schema, zero production connections.',PHP_EOL;

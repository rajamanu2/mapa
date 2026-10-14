<?php
namespace {require $argv[1];}
namespace App\MapaMobile {
    class SourceInventoryController {
        public static array $state=[];
        public static function verifiedState(string $company):array {return self::$state;}
    }
}
namespace {
require __DIR__.'/../../mobile/DispatchAuthority.php';
require __DIR__.'/../Policy.php';require __DIR__.'/../AutomaticDispatchPolicy.php';require __DIR__.'/../SourceTripEligibility.php';
use Illuminate\Support\Facades\DB;use Illuminate\Support\Facades\Schema;
$app=new Illuminate\Container\Container;Illuminate\Container\Container::setInstance($app);
$app->instance('config',new Illuminate\Config\Repository(['mapa_mobile'=>['dispatch_authority'=>'mapa']]));
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$db=new Illuminate\Database\Capsule\Manager($app);$db->addConnection(['driver'=>'sqlite','database'=>':memory:']);$db->setAsGlobal();$db->bootEloquent();
$app->instance('db',$db->getDatabaseManager());$app->bind('db.schema',fn()=>$db->getConnection()->getSchemaBuilder());
Schema::create('mapa_source_connections',function($t){foreach(['company_uuid','dataset','native_uuid','source_id'] as $c)$t->text($c);$t->boolean('active');});
Schema::create('vehicles',function($t){$t->text('company_uuid');$t->text('plate_number');$t->timestamp('deleted_at')->nullable();});
$company='DEMO-company';$driver=(object)['uuid'=>'DEMO-driver','meta'=>null];
$vehicle=(object)['uuid'=>'DEMO-vehicle','plate_number'=>'AP 31 AB 1234','meta'=>json_encode(['asr_import'=>['vehicle_key'=>'AP31AB1234']])];
DB::table('vehicles')->insert(['company_uuid'=>$company,'plate_number'=>'AP31AB1234']);
$state=['received_at'=>time(),'snapshot'=>['generated_at'=>time(),'complete'=>true,'contract'=>'mapa-asr-source-v3','datasets'=>[
    'drivers'=>[['id'=>'D1']],'ambulances'=>[['id'=>'V1','vehicle_number'=>'AP31AB1234'],['id'=>'V2','vehicle_number'=>'AP31AB9999']],
    'daily_status'=>[],'cad_dispatches'=>[],'trip_activity'=>[]]]];
App\MapaMobile\SourceInventoryController::$state=$state;
$service=new App\MapaVoice\SourceTripEligibility;$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
check($service->inspect($company,$driver,$vehicle)===null,'Exact CSV registration with native driver resolves unoccupied ambulance');
$original=$vehicle->meta;$vehicle->meta=json_encode(['asr_source_id'=>'V2','asr_import'=>['vehicle_key'=>'AP31AB1234']]);
check($service->inspect($company,$driver,$vehicle)['code']==='source_trip_unknown','Conflicting explicit ID cannot fall back to plate');$vehicle->meta=$original;
$link=['company_uuid'=>$company,'dataset'=>'ambulances','native_uuid'=>$vehicle->uuid,'source_id'=>'V1','active'=>false];DB::table('mapa_source_connections')->insert($link);
check($service->inspect($company,$driver,$vehicle)['code']==='source_trip_unknown','Revoked connection cannot fall back to CSV plate');
DB::table('mapa_source_connections')->update(['active'=>true]);DB::table('mapa_source_connections')->insert([...$link,'active'=>true,'source_id'=>'V2']);
check($service->inspect($company,$driver,$vehicle)['code']==='source_trip_unknown','Multiple exact connections are ambiguous');
DB::table('mapa_source_connections')->delete();DB::table('mapa_source_connections')->insert([...$link,'company_uuid'=>'DEMO-foreign','source_id'=>'V2','active'=>false]);
check($service->inspect($company,$driver,$vehicle)===null,'Foreign revoked link cannot affect own source identity');
DB::table('vehicles')->insert(['company_uuid'=>'DEMO-foreign','plate_number'=>'AP31AB1234']);
check($service->inspect($company,$driver,$vehicle)===null,'Foreign vehicle plate cannot create own-tenant ambiguity');
DB::table('vehicles')->insert(['company_uuid'=>$company,'plate_number'=>'AP 31 AB 1234']);
check($service->inspect($company,$driver,$vehicle)['code']==='source_trip_unknown','Duplicate own-tenant registration blocks fallback');
DB::table('vehicles')->where('company_uuid',$company)->where('plate_number','AP 31 AB 1234')->delete();
$driver->meta=json_encode(['asr_import'=>['source_id'=>'D1']]);
App\MapaMobile\SourceInventoryController::$state['snapshot']['datasets']['cad_dispatches'][]=['driver_id'=>'D1','ambulance_id'=>'V2','status'=>'transporting'];
check($service->inspect($company,$driver,$vehicle)['code']==='source_trip_unresolved','Exact imported driver busy on another ambulance blocks native pair');
$driver->meta=json_encode(['asr_import'=>['name'=>'DEMO driver']]);
check($service->inspect($company,$driver,$vehicle)['code']==='source_trip_unknown','Driver name never substitutes for exact source ID');
$driver->meta=null;App\MapaMobile\SourceInventoryController::$state=$state;App\MapaMobile\SourceInventoryController::$state['received_at']=1;
check($service->inspect($company,$driver,$vehicle)['code']==='source_trip_unknown','Identity match does not bypass stale source evidence');
echo 'SOURCE TRIP IDENTITIES PASS '.$n.' checks; isolated SQLite, no network or production writes'.PHP_EOL;
}

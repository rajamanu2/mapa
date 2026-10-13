<?php
/** Explicit read-only snapshot. No provisioning, model saves, credentials or token output. */
require '/fleetbase/api/vendor/autoload.php';
$app=require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Fleetbase\FleetOps\Models\Place;
$company='29eff7bc-7b9b-4d8a-b1b4-55f33681bd2d';
try {
    if(config('mapa.company_uuid')!==$company)throw new RuntimeException('Target company mismatch.');
    DB::statement('SET TRANSACTION READ ONLY');
    DB::beginTransaction();
    if(!DB::table('companies')->where('uuid',$company)->exists())throw new RuntimeException('Target company missing.');
    $schema=[];
    foreach(['users','drivers','vehicles','places'] as $table){$schema[$table]=[];
        foreach(DB::select('SHOW COLUMNS FROM `'.$table.'`') as $column)$schema[$table][$column->Field]=['type'=>$column->Type,'nullable'=>$column->Null==='YES'];}
    $vehicles=DB::table('vehicles')->where('company_uuid',$company)->get(['uuid','public_id','company_uuid','name','plate_number','type','online','status','deleted_at',
        DB::raw('location IS NULL AS location_is_null'),DB::raw("JSON_UNQUOTE(JSON_EXTRACT(meta,'$.asr_import.vehicle_key')) AS source_key"),
        DB::raw("JSON_EXTRACT(meta,'$.mapa_demo') AS demo"),DB::raw("JSON_EXTRACT(meta,'$.simulated') AS simulated")]);
    $places=[];
    foreach(Place::withoutGlobalScopes()->where('company_uuid',$company)->get(['uuid','public_id','company_uuid','name','street1','type','location','deleted_at',
        DB::raw("JSON_UNQUOTE(JSON_EXTRACT(meta,'$.asr_import.hospital_key')) AS source_key"),
        DB::raw("JSON_UNQUOTE(JSON_EXTRACT(meta,'$.asr_import.institution_type')) AS institution_type"),
        DB::raw("JSON_UNQUOTE(JSON_EXTRACT(meta,'$.asr_import.base_name')) AS source_base_name"),
        DB::raw("JSON_EXTRACT(meta,'$.mapa_demo') AS demo"),DB::raw("JSON_EXTRACT(meta,'$.simulated') AS simulated")]) as $place){
        $point=$place->location;
        $places[]=['uuid'=>$place->uuid,'public_id'=>$place->public_id,'company_uuid'=>$company,'name'=>$place->name,'address'=>$place->street1,'type'=>$place->type,
            'latitude'=>$point instanceof \Fleetbase\LaravelMysqlSpatial\Types\Point?$point->getLat():null,
            'longitude'=>$point instanceof \Fleetbase\LaravelMysqlSpatial\Types\Point?$point->getLng():null,'deleted_at'=>$place->deleted_at,
            'source_key'=>$place->source_key,'institution_type'=>$place->institution_type,'source_base_name'=>$place->source_base_name,'demo'=>$place->demo,'simulated'=>$place->simulated];
    }
    $users=DB::table('users')->where('company_uuid',$company)->get(['uuid','public_id','company_uuid','name','phone','status','type','deleted_at',
        DB::raw("JSON_UNQUOTE(JSON_EXTRACT(meta,'$.asr_import.source_driver_id')) AS source_driver_id"),
        DB::raw("JSON_EXTRACT(meta,'$.asr_import.profile_only') AS profile_only")]);
    $drivers=DB::table('drivers')->where('company_uuid',$company)->get(['uuid','public_id','company_uuid','user_uuid','vehicle_uuid','online','status','deleted_at',
        DB::raw('location IS NULL AS location_is_null'),DB::raw("JSON_UNQUOTE(JSON_EXTRACT(meta,'$.asr_import.source_driver_id')) AS source_driver_id")]);
    $result=['contract'=>'asr-native-import-snapshot-v1','company_uuid'=>$company,'read_only'=>true,'captured_at'=>gmdate('c'),'schema'=>$schema,
        'vehicles'=>$vehicles,'places'=>$places,'users'=>$users,'drivers'=>$drivers];
    DB::rollBack();
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;
}catch(Throwable $error){if(DB::transactionLevel()>0)DB::rollBack();fwrite(STDERR,'Read-only import snapshot failed: '.get_class($error).PHP_EOL);exit(1);}

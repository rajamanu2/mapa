<?php
/** Authorized read-only post-import inventory inspection. No provisioning or model saves. */
require '/fleetbase/api/app/AsrImport/ImportPlan.php';
require '/fleetbase/api/app/AsrImport/VerifyInventory.php';
use ASR\Import\ImportPlan;
use ASR\Import\VerifyInventory;
use Illuminate\Support\Facades\DB;
use Fleetbase\FleetOps\Models\Place;
try {
    if(!isset($asrVerifyInput) || !is_array($asrVerifyInput))throw new RuntimeException('Explicit verification input required.');
    $plan=ImportPlan::unpack($asrVerifyInput);$snapshotRaw=base64_decode($asrVerifyInput['snapshot_base64']??'',true);
    if($snapshotRaw===false || !hash_equals($plan['fingerprints']['snapshot_sha256'],hash('sha256',$snapshotRaw)))throw new RuntimeException('Verification snapshot mismatch.');
    $before=json_decode($snapshotRaw,true,512,JSON_THROW_ON_ERROR);
    if(($before['contract']??null)!=='asr-native-import-snapshot-v1')throw new RuntimeException('Invalid native snapshot.');
    if(($asrVerifyInput['legacy_snapshot_windows1252']??false)===true)$before=VerifyInventory::decodeLegacyWindowsCapture($before);
    require '/fleetbase/api/vendor/autoload.php';$app=require '/fleetbase/api/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$company=ImportPlan::COMPANY;
    if(config('mapa.company_uuid')!==$company)throw new RuntimeException('Target company mismatch.');
    DB::statement('SET TRANSACTION READ ONLY');DB::beginTransaction();
    if(!DB::table('companies')->where('uuid',$company)->exists())throw new RuntimeException('Company missing.');
    $provenance=function(array $keys){$parts=[];foreach($keys as $key)$parts[]="'".$key."',JSON_EXTRACT(meta,'$.asr_import.".$key."')";
        return DB::raw('JSON_OBJECT('.implode(',',$parts).') AS import_metadata');};
    $keyColumn=function(string $table){$columns=collect(DB::select('SHOW COLUMNS FROM `'.$table.'`'))->pluck('Field')->all();return DB::raw(in_array('_key',$columns,true)?'`_key` AS _import_key':'NULL AS _import_key');};
    $positionCounts=DB::table('positions')->where('company_uuid',$company)->groupBy('subject_uuid')->select('subject_uuid',DB::raw('COUNT(*) AS n'))->pluck('n','subject_uuid');
    $baseMeta=[DB::raw("JSON_EXTRACT(meta,'$.mapa_demo') AS demo"),DB::raw("JSON_EXTRACT(meta,'$.simulated') AS simulated")];
    $vehicles=DB::table('vehicles')->where('company_uuid',$company)->get(['uuid','public_id','company_uuid','name','plate_number','type','online','status','deleted_at',
        DB::raw('location IS NULL AS location_is_null'),$keyColumn('vehicles'),DB::raw("JSON_UNQUOTE(JSON_EXTRACT(meta,'$.asr_import.vehicle_key')) AS source_key"),...$baseMeta,
        $provenance(['origin','vehicle_key','hospital_key','source_vehicle_type','source_csv_sha256','source_rows','source_context','coordinate_semantics','import_scope','operational_status_verified','source_parking_base_place_uuid'])])->map(function($row)use($positionCounts){
            $data=(array)$row;$data['meta']=['asr_import'=>json_decode($data['import_metadata']??'{}',true,512,JSON_THROW_ON_ERROR)];unset($data['import_metadata']);$data['position_count']=(int)($positionCounts[$row->uuid]??0);return $data;})->all();
    $places=[];
    foreach(Place::withoutGlobalScopes()->where('company_uuid',$company)->get(['uuid','public_id','company_uuid','name','street1','type','location','deleted_at',$keyColumn('places'),
        DB::raw("JSON_UNQUOTE(JSON_EXTRACT(meta,'$.asr_import.hospital_key')) AS source_key"),DB::raw("JSON_UNQUOTE(JSON_EXTRACT(meta,'$.asr_import.institution_type')) AS institution_type"),
        DB::raw("JSON_UNQUOTE(JSON_EXTRACT(meta,'$.asr_import.base_name')) AS source_base_name"),...$baseMeta,
        $provenance(['origin','hospital_key','institution_type','base_name','source_csv_sha256','source_rows','source_context','coordinate_semantics'])]) as $place){$point=$place->location;
        $places[]=['uuid'=>$place->uuid,'public_id'=>$place->public_id,'company_uuid'=>$company,'name'=>$place->name,'address'=>$place->street1,'type'=>$place->type,'deleted_at'=>$place->deleted_at,
            'latitude'=>$point instanceof \Fleetbase\LaravelMysqlSpatial\Types\Point?$point->getLat():null,'longitude'=>$point instanceof \Fleetbase\LaravelMysqlSpatial\Types\Point?$point->getLng():null,
            '_import_key'=>$place->_import_key,'source_key'=>$place->source_key,'institution_type'=>$place->institution_type,'source_base_name'=>$place->source_base_name,'demo'=>$place->demo,'simulated'=>$place->simulated,
            'meta'=>['asr_import'=>json_decode($place->import_metadata??'{}',true,512,JSON_THROW_ON_ERROR)]];}
    $users=DB::table('users')->where('company_uuid',$company)->get(['uuid','public_id','company_uuid','name','phone','status','type','deleted_at',$keyColumn('users'),
        DB::raw("JSON_UNQUOTE(JSON_EXTRACT(meta,'$.asr_import.source_driver_id')) AS source_driver_id"),DB::raw("JSON_EXTRACT(meta,'$.asr_import.profile_only') AS profile_only"),$provenance(['origin','source_csv_sha256'])]);
    $drivers=DB::table('drivers')->where('company_uuid',$company)->get(['uuid','public_id','company_uuid','user_uuid','vehicle_uuid','online','status','deleted_at',$keyColumn('drivers'),
        DB::raw('location IS NULL AS location_is_null'),DB::raw("JSON_UNQUOTE(JSON_EXTRACT(meta,'$.asr_import.source_driver_id')) AS source_driver_id"),$provenance(['origin','source_csv_sha256'])]);
    $identities=function($rows){return $rows->map(function($row){$data=(array)$row;$data['meta']=['asr_import'=>json_decode($data['import_metadata']??'{}',true,512,JSON_THROW_ON_ERROR)];unset($data['import_metadata']);return $data;})->all();};
    $current=json_decode(json_encode(['company_uuid'=>$company,'read_only'=>true,'vehicles'=>$vehicles,'places'=>$places,'users'=>$identities($users),'drivers'=>$identities($drivers)],JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);
    $report=VerifyInventory::compare($plan,$before,$current);$report['plan_sha256']=$asrVerifyInput['expected_plan_sha256'];$report['verified_at']=gmdate('c');
    $report['snapshot_sha256']=hash('sha256',$snapshotRaw);
    $report['snapshot_capture_decoding']=($asrVerifyInput['legacy_snapshot_windows1252']??false)===true?'utf8_bytes_misdecoded_as_windows1252':'utf8';
    $report['decoded_snapshot_sha256']=hash('sha256',json_encode($before,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    DB::rollBack();echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;
}catch(Throwable $error){if(class_exists(DB::class,false) && DB::transactionLevel()>0)DB::rollBack();fwrite(STDERR,'Read-only import verification failed: '.get_class($error).PHP_EOL);exit(1);}

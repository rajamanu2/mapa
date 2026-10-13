<?php
require dirname(__DIR__).'/ImportPlan.php';require dirname(__DIR__).'/VerifyInventory.php';
use ASR\Import\ImportPlan;use ASR\Import\VerifyInventory;
$checks=0;
function check(bool $ok,string $name):void{global $checks;if(!$ok)throw new RuntimeException('DEMO verification assertion: '.$name);$checks++;}
function compare(array $p,array $b,array $c):array{return VerifyInventory::compare($p,$b,$c);}
function hasError(array $report,string $code):bool{return in_array($code,array_column($report['errors'],'code'),true);}
foreach(['DEMO — ambulance','DEMO pickup · Fictional training'] as $text){$legacy=iconv('Windows-1252','UTF-8',$text);check(VerifyInventory::decodeLegacyWindowsCapture($legacy)===$text,'Exact Windows capture byte reversal');}
check(VerifyInventory::decodeLegacyWindowsCapture(['name'=>'DEMO ASCII','online'=>false,'longitude'=>82.1])===['name'=>'DEMO ASCII','online'=>false,'longitude'=>82.1],'Capture reversal preserves ASCII and scalar types');
try{VerifyInventory::decodeLegacyWindowsCapture('DEMO — already correct Unicode');throw new LogicException('Expected encoding failure');}catch(InvalidArgumentException $ok){check(true,'Correct Unicode cannot be mislabeled legacy');}
$key=str_repeat('a',64);$csv=str_repeat('c',64);$company=ImportPlan::COMPANY;
$h=['key'=>$key,'status'=>'create','institution_type'=>'PHC','base_name'=>'DEMO Base','latitude'=>18.1,'longitude'=>82.1,'source_rows'=>[2],
    'context'=>[['secretariat'=>'DEMO Secretariat','mandal'=>'DEMO Mandal','date_mapped'=>'2026-10-01']],'native'=>['name'=>'PHC DEMO Base','type'=>'hospital']];
$v=['key'=>'AP99QA0001','status'=>'create','hospital_key'=>$key,'source_vehicle_type'=>'Special','source_rows'=>[2],'context'=>$h['context'],
    'native'=>['name'=>'DEMO Special AP99QA0001','plate_number'=>'AP99QA0001','type'=>'Special','online'=>false,'status'=>'available']];
$p=['company_uuid'=>$company,'source_origin'=>'https://asrhealth.in','fingerprints'=>['csv_sha256'=>$csv],'vehicles'=>[$v],'hospitals'=>[$h]];
$before=['company_uuid'=>$company,'read_only'=>true,'vehicles'=>[],'places'=>[],'users'=>[],'drivers'=>[]];
$current=$before;
$current['vehicles']=[['uuid'=>'DEMO_vehicle_uuid','public_id'=>'vehicle_DEMO','company_uuid'=>$company,'name'=>$v['native']['name'],'plate_number'=>'AP99QA0001','type'=>'Special','online'=>0,'status'=>'available','deleted_at'=>null,
    'source_key'=>'AP99QA0001','location_is_null'=>1,'position_count'=>0,'_import_key'=>'asr-source-import-v1','meta'=>['asr_import'=>['origin'=>$p['source_origin'],'vehicle_key'=>$v['key'],'hospital_key'=>$key,
        'source_vehicle_type'=>'Special','source_csv_sha256'=>$csv,'source_rows'=>[2],'source_context'=>$h['context'],'coordinate_semantics'=>'no_vehicle_location_imported','import_scope'=>'inventory_only','operational_status_verified'=>false]]]];
$current['places']=[['uuid'=>'DEMO_place_uuid','public_id'=>'place_DEMO','company_uuid'=>$company,'name'=>'PHC DEMO Base','address'=>null,'type'=>'hospital','latitude'=>18.1,'longitude'=>82.1,'deleted_at'=>null,
    'source_key'=>$key,'institution_type'=>'PHC','source_base_name'=>'DEMO Base','_import_key'=>'asr-source-import-v1','meta'=>['asr_import'=>['origin'=>$p['source_origin'],'hospital_key'=>$key,'institution_type'=>'PHC','base_name'=>'DEMO Base',
        'source_csv_sha256'=>$csv,'source_rows'=>[2],'source_context'=>$h['context'],'coordinate_semantics'=>'source_hospital_or_parking_base_not_live_gps']]]];
$r=compare($p,$before,$current);check($r['state']==='PASS','Complete imported inventory passes');check($r['counts']['imported_vehicles_verified']===1&&$r['counts']['source_hospitals_verified']===1,'Counts match');check($r['imported_vehicle_types']===['Special'=>1],'Exact source type aggregate');
foreach(['vehicles','places'] as $collection){foreach([true,'true','1',1] as $flag){$c=$current;$c[$collection][0]['demo']=$flag;check(hasError(compare($p,$before,$c),'imported_inventory_marked_training'),'SQL/JSON truth flags rejected');}
    $c=$current;$c[$collection][0]['demo']='false';$c[$collection][0]['simulated']='false';check(compare($p,$before,$c)['state']==='PASS','SQL/JSON false flags accepted');}
$c=$current;foreach(['vehicles','places'] as $collection)$c[$collection][0]['meta']['asr_import']['source_context']=[['date_mapped'=>'2026-10-01','mandal'=>'DEMO Mandal','secretariat'=>'DEMO Secretariat']];check(compare($p,$before,$c)['state']==='PASS','MySQL JSON object key ordering ignored');
$q=$p;$extra=['secretariat'=>'DEMO Second','mandal'=>'DEMO Second Mandal','date_mapped'=>'2026-10-02'];foreach(['vehicles','hospitals'] as $collection)$q[$collection][0]['context'][]=$extra;
$c=$current;foreach(['vehicles','places'] as $collection)$c[$collection][0]['meta']['asr_import']['source_context']=[$extra,$h['context'][0]];check(hasError(compare($q,$before,$c),'source_provenance_mismatch'),'Source context list order preserved');
$c=$current;$c['vehicles'][0]['meta']['asr_import']['source_context'][0]['date_mapped']=2026;check(hasError(compare($p,$before,$c),'source_provenance_mismatch'),'Source context scalar type preserved');
foreach(['online'=>1,'location_is_null'=>0,'position_count'=>1] as $field=>$value){$c=$current;$c['vehicles'][0][$field]=$value;check(hasError(compare($p,$before,$c),'inventory_has_online_gps_or_position'),'New inventory must not claim duty/GPS');}
$c=$current;$c['vehicles'][0]['meta']['asr_import']['operational_status_verified']=true;check(hasError(compare($p,$before,$c),'inventory_semantics_mismatch'),'No invented ready status');
foreach(['source_csv_sha256'=>str_repeat('d',64),'source_context'=>[],'source_vehicle_type'=>'DEMO guessed ALS'] as $field=>$value){$c=$current;$c['vehicles'][0]['meta']['asr_import'][$field]=$value;check(hasError(compare($p,$before,$c),'source_provenance_mismatch'),'Exact provenance');}
$c=$current;$c['vehicles'][0]['type']='DEMO guessed ALS';check(hasError(compare($p,$before,$c),'source_type_mismatch'),'Exact source native type');
$c=$current;$c['places'][0]['latitude']=0;$c['places'][0]['longitude']=0;check(hasError(compare($p,$before,$c),'hospital_identity_or_coordinates_mismatch'),'World origin never source evidence');
$c=$current;$c['places'][0]['latitude']=null;check(hasError(compare($p,$before,$c),'hospital_identity_or_coordinates_mismatch'),'Missing point rejected');
$c=$current;$c['places'][0]['meta']['asr_import']['source_context']=[];check(hasError(compare($p,$before,$c),'source_provenance_mismatch'),'Hospital context retained');
$c=$current;$c['vehicles'][]=[...$c['vehicles'][0],'uuid'=>'DEMO_other_uuid','plate_number'=>'AP 99-QA0001'];check(hasError(compare($p,$before,$c),'missing_or_duplicate_normalized_plate'),'Duplicate canonical plate rejected');
$c=$current;$c['vehicles']=[];check(hasError(compare($p,$before,$c),'missing_or_duplicate_normalized_plate'),'Missing vehicle rejected');
$c=$current;$c['places']=[];check(hasError(compare($p,$before,$c),'missing_or_duplicate_hospital_key'),'Missing hospital rejected');
$q=$p;$q['hospitals'][0]['status']='deferred';check(hasError(compare($q,$before,$current),'deferred_hospital_was_imported'),'Deferred coordinates never imported');
$plain=['uuid'=>'DEMO_existing_vehicle','public_id'=>'vehicle_DEMO_existing','company_uuid'=>$company,'name'=>'DEMO existing','plate_number'=>'AP99QA9001','type'=>'DEMO native type','online'=>1,'status'=>'active','deleted_at'=>null,'location_is_null'=>0,'source_key'=>null];
$b=$before;$b['vehicles']=[$plain];$c=$current;$c['vehicles'][]=[...$plain,'online'=>0,'status'=>'maintenance','location_is_null'=>1,'position_count'=>8];check(compare($p,$b,$c)['state']==='PASS','Concurrent existing duty/status/GPS excluded');
$c['vehicles'][1]['name']='DEMO changed unexpectedly';check(hasError(compare($p,$b,$c),'existing_captured_fields_changed'),'Existing inventory identity preserved');
$b['vehicles'][0]['name']=iconv('Windows-1252','UTF-8','DEMO — original');$c['vehicles'][1]['name']='DEMO — changed';
check(hasError(compare($p,VerifyInventory::decodeLegacyWindowsCapture($b),$c),'existing_captured_fields_changed'),'Legacy correction cannot conceal a real Unicode identity change');
$c['vehicles'][1]['name']='DEMO — original';check(compare($p,VerifyInventory::decodeLegacyWindowsCapture($b),$c)['state']==='PASS','Legacy baseline exact identity remains strictly checked');
$b=$before;$b['places']=[$current['places'][0]];$c=$current;$c['places'][0]['latitude']=18.2;check(hasError(compare($p,$b,$c),'existing_captured_fields_changed'),'Existing static base point preserved');
$race=$current;$race['vehicles'][0]=[...$race['vehicles'][0],'_import_key'=>null,'meta'=>[],'online'=>1,'location_is_null'=>0];$r=compare($p,$before,$race);check($r['state']==='PASS'&&$r['counts']['native_plate_matches_preserved']===1&&count($r['warnings'])===1,'Current native addition preserved and reported');
$q=$p;$q['vehicles'][0]['status']='preserve_existing';$b=$before;$b['vehicles']=[$race['vehicles'][0]];check(compare($q,$b,$race)['state']==='PASS','Prior matching vehicle never forced offline or retyped');
foreach(['users','drivers'] as $collection){$c=$current;$c[$collection][]=['uuid'=>'DEMO_concurrent_identity','public_id'=>'DEMO_identity','company_uuid'=>$company,'_import_key'=>null,'meta'=>[]];$r=compare($p,$before,$c);
    check($r['state']==='PASS'&&$r['counts']['new_'.$collection.'_since_snapshot']===1&&count($r['warnings'])===1,'Concurrent identity delta not attributed to importer');
    $c[$collection][0]['_import_key']='asr-source-import-v1';check(hasError(compare($p,$before,$c),'unexpected_import_identity_marker'),'Importer never creates identity');}
$c=$current;$c['users'][]=['uuid'=>'DEMO_tagged_identity','company_uuid'=>$company,'meta'=>['asr_import'=>['origin'=>$p['source_origin'],'source_csv_sha256'=>$csv]]];check(hasError(compare($p,$before,$c),'unexpected_import_identity_marker'),'CSV identity metadata rejected');
$c=$current;$c['vehicles'][0]['company_uuid']='DEMO_foreign';try{compare($p,$before,$c);throw new LogicException('Expected scope failure');}catch(InvalidArgumentException $ok){check(true,'Foreign row guard');}
$c=$current;$c['read_only']=false;try{compare($p,$before,$c);throw new LogicException('Expected readonly failure');}catch(InvalidArgumentException $ok){check(true,'Readonly guard');}
echo $checks.' pure read-only inventory verification checks passed.',PHP_EOL;

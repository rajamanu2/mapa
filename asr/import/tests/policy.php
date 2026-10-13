<?php
require dirname(__DIR__).'/ImportPlan.php';
use ASR\Import\ImportPlan;
$checks=0;
function check(bool $condition):void {global $checks;if(!$condition)throw new RuntimeException('DEMO resource policy assertion failed.');$checks++;}
function packet(array $plan):array {$raw=json_encode($plan,JSON_THROW_ON_ERROR);return ['ack'=>'REVIEWED_RESOURCE_CREATION_ONLY','plan_base64'=>base64_encode($raw),'expected_plan_sha256'=>hash('sha256',$raw),'expected_snapshot_sha256'=>str_repeat('2',64)];}
function denied(array $plan):void {try{ImportPlan::unpack(packet($plan));throw new RuntimeException('DEMO expected policy rejection.');}catch(InvalidArgumentException $expected){check(true);}}
$vehicle=['key'=>'TS09AB1001','status'=>'create','source_vehicle_type'=>'Special','hospital_key'=>str_repeat('a',64),
    'native'=>['name'=>'DEMO Special TS09AB1001','plate_number'=>'TS09AB1001','type'=>'Special','online'=>false,'status'=>'available']];
$hospital=['key'=>str_repeat('a',64),'status'=>'create','base_name'=>'DEMO Base','institution_type'=>'PHC','latitude'=>18.1,'longitude'=>82.1,'native'=>['name'=>'PHC DEMO Base','type'=>'hospital']];
$plan=['contract'=>'asr-source-resource-plan-v1','mode'=>'dry_run','company_uuid'=>ImportPlan::COMPANY,'source_origin'=>'https://asrhealth.in','snapshot_compared'=>true,'driver_import_allowed'=>false,
    'fingerprints'=>['snapshot_sha256'=>str_repeat('2',64),'csv_sha256'=>str_repeat('3',64),'drivers_sha256'=>str_repeat('4',64),'staging_source_sha256'=>str_repeat('5',64)],'vehicles'=>[$vehicle],'hospitals'=>[$hospital]];
check(ImportPlan::unpack(packet($plan))===$plan);
check(ImportPlan::hospitalName('KGH','KGH')==='KGH');check(ImportPlan::hospitalName('PHC','DEMO Base')==='PHC DEMO Base');
check(ImportPlan::plate('ts 09-ab.1001')==='TS09AB1001');
foreach(['company_uuid'=>'DEMO_foreign','source_origin'=>'https://other.invalid','driver_import_allowed'=>true,'snapshot_compared'=>false] as $field=>$value)denied([...$plan,$field=>$value]);
foreach(['location'=>null,'driver_uuid'=>'DEMO_driver','company_uuid'=>'DEMO_foreign','online'=>true,'online_coerced'=>0] as $field=>$value){$record=$vehicle;$record['native'][$field==='online_coerced'?'online':$field]=$value;denied([...$plan,'vehicles'=>[$record]]);}
$record=$vehicle;$record['native']['type']='DEMO guessed BLS';denied([...$plan,'vehicles'=>[$record]]);
foreach([['latitude'=>0,'longitude'=>0],['latitude'=>null],['latitude'=>'18.1'],['latitude'=>91],['longitude'=>181]] as $change)denied([...$plan,'hospitals'=>[[...$hospital,...$change]]]);
denied([...$plan,'vehicles'=>[$vehicle,$vehicle]]);denied([...$plan,'hospitals'=>[$hospital,$hospital]]);
$bad=packet($plan);$bad['expected_plan_sha256']=str_repeat('0',64);try{ImportPlan::unpack($bad);throw new RuntimeException('DEMO expected fingerprint rejection.');}catch(InvalidArgumentException $expected){check(true);}
$bad=packet($plan);$bad['expected_snapshot_sha256']=str_repeat('0',64);try{ImportPlan::unpack($bad);throw new RuntimeException('DEMO expected snapshot rejection.');}catch(InvalidArgumentException $expected){check(true);}
echo $checks,' isolated resource-only policy checks passed.',PHP_EOL;

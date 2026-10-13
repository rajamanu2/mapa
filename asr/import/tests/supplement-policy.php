<?php
require dirname(__DIR__).'/ImportPlan.php';
require dirname(__DIR__).'/SupplementPlan.php';
use ASR\Import\ImportPlan;
use ASR\Import\SupplementPlan;
$checks=0;
function supplementCheck(bool $condition):void {global $checks;if(!$condition)throw new RuntimeException('DEMO supplemental policy assertion failed.');$checks++;}
function supplementPacket(array $plan):array {$raw=json_encode($plan,JSON_THROW_ON_ERROR);return ['ack'=>'REVIEWED_HOSPITAL_PIN_SUPPLEMENT','plan_base64'=>base64_encode($raw),'expected_plan_sha256'=>hash('sha256',$raw)];}
function supplementDenied(array $plan):void {try{SupplementPlan::unpack(supplementPacket($plan));throw new RuntimeException('DEMO expected supplemental rejection.');}catch(InvalidArgumentException $expected){supplementCheck(true);}}
$record=['hospital_key'=>str_repeat('a',64),'institution_type'=>'PHC','base_name'=>'DEMO Base','name'=>'PHC DEMO Base','identity'=>'DEMO Health Centre','address'=>'DEMO public test address',
    'latitude'=>18.1,'longitude'=>82.2,'google_url'=>'https://www.google.com/maps/place/DEMO/@1,2,17z/data=!3d18.1!4d82.2',
    'coordinate_semantics'=>'researched_hospital_facility_pin_not_live_gps','ground_surveyed'=>false,
    'official_sources'=>[['url'=>'https://example.gov.in/DEMO','supports'=>'DEMO fixture identity']],
    'source_rows'=>[2],'source_context'=>[['mandal'=>'DEMO']], 'collision_names'=>['DEMO Base','PHC DEMO Base']];
$plan=['contract'=>'asr-hospital-pin-supplement-v1','company_uuid'=>ImportPlan::COMPANY,'source_origin'=>'https://asrhealth.in','mode'=>'review_before_apply',
    'original_plan_sha256'=>SupplementPlan::ORIGINAL_SHA,'source_csv_sha256'=>str_repeat('b',64),'evidence_sha256'=>str_repeat('c',64),
    'hospitals'=>[$record],'vehicle_links'=>[['vehicle_key'=>'TS09DEMO1','hospital_key'=>str_repeat('a',64)]],'unresolved'=>[]];
supplementCheck(SupplementPlan::unpack(supplementPacket($plan))===$plan);
// The selected !3d/!4d place point deliberately differs from viewport center.
supplementCheck(SupplementPlan::validate($plan)['hospitals'][0]['latitude']===18.1);
foreach(['company_uuid'=>'DEMO_foreign','source_origin'=>'https://elsewhere.invalid','original_plan_sha256'=>str_repeat('0',64),'mode'=>'unreviewed','evidence_sha256'=>'not a hash'] as $field=>$value)supplementDenied([...$plan,$field=>$value]);
foreach([['latitude'=>0,'longitude'=>0],['latitude'=>null],['latitude'=>'18.1'],['latitude'=>91],['longitude'=>181],['latitude'=>18.2],['ground_surveyed'=>true],['coordinate_semantics'=>'live_vehicle_gps'],['name'=>str_repeat('X',151)],['address'=>str_repeat('X',251)],['official_sources'=>[]],['source_rows'=>[1]],['collision_names'=>[]],['google_url'=>'https://www.google.com/maps/search/?query=18.1,82.2']] as $change)supplementDenied([...$plan,'hospitals'=>[[...$record,...$change]]]);
supplementDenied([...$plan,'hospitals'=>[]]);supplementDenied([...$plan,'hospitals'=>[$record,$record]]);
foreach([
    [['vehicle_key'=>'ts 09-demo1','hospital_key'=>str_repeat('a',64)]],
    [['vehicle_key'=>'TS09DEMO1','hospital_key'=>str_repeat('d',64)]],
    [$plan['vehicle_links'][0],$plan['vehicle_links'][0]],
    [['vehicle_key'=>'TS09DEMO1','hospital_key'=>str_repeat('a',64),'driver_uuid'=>'DEMO_DRIVER']],
] as $links)supplementDenied([...$plan,'vehicle_links'=>$links]);
foreach(['ack'=>'UNREVIEWED','expected_plan_sha256'=>str_repeat('0',64),'plan_base64'=>'invalid=='] as $field=>$value){$packet=supplementPacket($plan);$packet[$field]=$value;try{SupplementPlan::unpack($packet);throw new RuntimeException('DEMO expected reviewed-envelope rejection.');}catch(InvalidArgumentException $expected){supplementCheck(true);}}
echo $checks,' isolated supplemental policy checks passed.',PHP_EOL;

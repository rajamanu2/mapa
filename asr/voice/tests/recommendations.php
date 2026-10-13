<?php
require __DIR__.'/../Policy.php';require __DIR__.'/../AutomaticDispatchPolicy.php';require __DIR__.'/../RecommendationService.php';
use App\MapaVoice\RecommendationService;
use App\MapaVoice\AutomaticDispatchPolicy;
$checks=[];function verify($condition,$name){global $checks;if(!$condition)throw new RuntimeException($name);$checks[]=$name;}
$service=new RecommendationService;$review=['latitude'=>18.1,'longitude'=>82.1];
$directory=['candidates'=>[['id'=>'near','distance_km'=>1,'fix_recorded_at'=>100],['id'=>'fast','distance_km'=>2,'fix_recorded_at'=>200]],
    'unavailable'=>[['id'=>'stale','code'=>'gps_stale','message'=>'GPS stale']],'inventory_total'=>3];
$resource=fn($row)=>['latitude'=>18,'longitude'=>$row['id']==='near'?82.01:82.02];
$result=$service->rank($directory,$review,$resource,fn($from,$to)=>$from['longitude']===82.02?30:120);
verify($result['candidates'][0]['id']==='fast'&&$result['candidates'][0]['rank']===1,'Road time outranks straight line proximity');
$radius=$service->rank($directory,$review,fn($row)=>['latitude'=>18.1,'longitude'=>$row['id']==='near'?82.101:82.12],fn($from,$to)=>$from['longitude']===82.12?30:120,true);
verify($radius['candidates'][0]['id']==='near'&&$radius['candidates'][0]['radius_band_m']===500,'Automatic search checks pickup radius before road time');
$dual=$service->rank($directory,$review,fn($row)=>['latitude'=>18.1,'longitude'=>$row['id']==='near'?82.101:82.12,
    'source'=>'hardware_gps','recorded_at'=>1234,'crew_phone_location'=>['latitude'=>18.1,'longitude'=>82.1005],
    'ambulance_gps_location'=>['latitude'=>18.1,'longitude'=>82.101]],fn()=>60,true);
verify($dual['candidates'][0]['location_source']==='hardware_gps'&&$dual['candidates'][0]['location_recorded_at']===1234
    &&$dual['candidates'][0]['crew_phone_location']!==null&&$dual['candidates'][0]['ambulance_gps_location']!==null,
    'Automatic ETA uses fresh ambulance GPS while retaining separate crew phone evidence');
$apart=$service->rank($directory,$review,fn($row)=>['latitude'=>18.1,'longitude'=>82.101,'location_conflict'=>true],fn()=>60,true);
verify($apart['candidates']===[]&&$apart['unavailable'][1]['code']==='crew_vehicle_apart','Separated crew and ambulance require control review');
verify(AutomaticDispatchPolicy::radiusBand(0.1)===500&&AutomaticDispatchPolicy::radiusBand(0.5)===500&&AutomaticDispatchPolicy::radiusBand(0.501)===1500&&AutomaticDispatchPolicy::radiusBand(1.501)===2500,'Search expands from 500 m in 1 km bands');
verify($result['candidates'][0]['road_minutes']===1,'Road minutes use ceiling');
verify($result['candidates'][0]['fix_recorded_at']===200,'Verified GPS timestamp is preserved');
verify($result['ranked'][0]['latitude']===18.0&&$result['ranked'][0]['location_recorded_at']===200&&$result['ranked'][0]['location_source']==='driver_phone_gps','Authorized operator map identifies driver phone GPS and timestamp');
verify($result['unavailable'][0]['code']==='gps_stale'&&$result['inventory_total']===3,'Eligibility exclusions and inventory remain intact');
$result=$service->rank($directory,$review,fn($row)=>[...$resource($row),'source_owned'=>$row['id']==='fast'],fn()=>60);
verify(count($result['candidates'])===1&&$result['unavailable'][1]['code']==='source_owned','Shared ASR ambulance never recommended for native dispatch');
$result=$service->rank($directory,$review,fn($row)=>[...$resource($row),'pending_offer'=>true],fn()=>60);
verify($result['routing_status']==='no_eligible_crew'&&$result['candidates']===[],'Reserved crews excluded before routing');
$requests=0;$result=$service->rank($directory,[],$resource,function()use(&$requests){$requests++;return 60;});
verify($requests===0&&$result['routing_status']==='pickup_unconfirmed','Missing confirmed pickup prevents route requests');
foreach([null,-1,NAN,INF,172801] as $invalid){$result=$service->rank($directory,$review,$resource,fn()=>$invalid);verify($result['candidates']===[]&&$result['routing_status']==='unavailable','Invalid or missing road time rejected: '.var_export($invalid,true));}
$requests=0;$large=['candidates'=>array_map(fn($n)=>['id'=>(string)$n,'distance_km'=>$n],range(1,30))];
$result=$service->rank($large,$review,fn()=>['latitude'=>18,'longitude'=>82],function()use(&$requests){$requests++;return 60;});
verify($requests===10&&count($result['candidates'])===10&&$result['shortlist_truncated'],'Road routing bounded to 10 proximity shortlisted eligible vehicles');
$result=$service->rank($directory,$review,fn()=>['latitude'=>0,'longitude'=>0],function()use(&$requests){throw new RuntimeException('Must not route missing GPS');});
verify($result['candidates']===[]&&$result['unavailable'][1]['code']==='gps_missing','Invalid location never reaches router');
$response=AutomaticDispatchPolicy::response('offered',100,220,null,150);verify($response['remaining_seconds']===70&&$response['response_seconds']===null,'Countdown active and response unknown');
$response=AutomaticDispatchPolicy::response('offered',100,220,null,220);verify($response['no_response']&&$response['remaining_seconds']===0,'No response at exact expiry boundary');
$response=AutomaticDispatchPolicy::response('accepted',100,220,130,140);verify($response['response_seconds']===30&&!$response['no_response'],'Acceptance response time measured');
$response=AutomaticDispatchPolicy::response('expired',100,220,250,250);verify($response['response_seconds']===null&&$response['no_response'],'Worker expiry is not a crew response');
$response=AutomaticDispatchPolicy::response('cancelled',100,220,130,140);verify($response['response_seconds']===null&&!$response['no_response'],'Operator cancellation is not a crew response');
echo json_encode(['ok'=>true,'checks'=>count($checks),'cases'=>$checks,'network_requests'=>0,'production_writes'=>0],JSON_PRETTY_PRINT)."\n";

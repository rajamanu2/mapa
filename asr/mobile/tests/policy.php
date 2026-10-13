<?php
require_once __DIR__.'/../Policy.php';
use App\MapaMobile\Policy;
$count=0;
function check($condition,$message) { global $count; if (!$condition) throw new RuntimeException($message); $count++; }
$binding=['enabled'=>1,'company_uuid'=>'DEMO-A','user_uuid'=>'DEMO-U','role'=>'crew','revision'=>3];
$session=['company_uuid'=>'DEMO-A','user_uuid'=>'DEMO-U','binding_revision'=>3];
check(Policy::bindingMatches($binding,$session,'DEMO-A'),'same binding');
foreach ([['company_uuid'=>'DEMO-B'],['user_uuid'=>'OTHER'],['binding_revision'=>2]] as $change)
    check(!Policy::bindingMatches($binding,[...$session,...$change],'DEMO-A'),'cross tenant/user/revision denied');
foreach ([['enabled'=>false],['role'=>'owner'],['company_uuid'=>'DEMO-B']] as $change)
    check(!Policy::bindingMatches([...$binding,...$change],$session,'DEMO-A'),'disabled/unknown role denied');
check(Policy::ownsOrder('DEMO-A','DRIVER-A',['company_uuid'=>'DEMO-A','driver_assigned_uuid'=>'DRIVER-A']),'native assignment');
check(!Policy::ownsOrder('DEMO-A','DRIVER-B',['company_uuid'=>'DEMO-A','driver_assigned_uuid'=>'DRIVER-A']),'other driver denied');
check(!Policy::ownsOrder('DEMO-B','DRIVER-A',['company_uuid'=>'DEMO-A','driver_assigned_uuid'=>'DRIVER-A']),'other company denied');
$body=['action_id'=>'demo-0001','online'=>true];
check(Policy::fingerprint('duty',$body)===Policy::fingerprint('duty',array_reverse($body,true)),'canonical key ordering');
check(Policy::fingerprint('duty',$body)!==Policy::fingerprint('duty',[...$body,'online'=>false]),'changed payload differs');
check(Policy::fingerprint('ack:order_demoA',$body)!==Policy::fingerprint('ack:order_demoB',$body),'native resource in receipt identity');
check(Policy::validAction('d3943bf0-c144-4387-a390-d96359ef52dc'),'UUID accepted');
foreach (['short','../../escape',null,123] as $bad) check(!Policy::validAction($bad),'invalid action denied');
foreach (['hospital','operator','admin','superadmin','unknown'] as $role) {
    $c=Policy::capabilities($role,true);check(!$c['gps']&&!$c['duty']&&!$c['acknowledge'],'staff cannot impersonate crew');
    check(!$c['photo_upload']&&!$c['hospital_response']&&!$c['admin_writes'],'unsupported features unadvertised');
}
check(Policy::bindingMatches([...$binding,'role'=>'operator'],$session,'DEMO-A'),'operator binding is recognized');
check(!Policy::capabilities('operator',false)['orders'],'operator mobile snapshot does not advertise native orders');
check(!Policy::capabilities('crew',false)['gps'],'no assigned vehicle no GPS');
check(!Policy::capabilities('crew',true)['activities'],'unreviewed native activity side effects unavailable');
$order=['company_uuid'=>'DEMO-A','driver_assigned_uuid'=>'DRIVER-A','vehicle_assigned_uuid'=>'VEHICLE-A','dispatched'=>true,'status'=>'dispatched'];
check(Policy::canAcknowledge('DEMO-A','DRIVER-A','VEHICLE-A',$order),'assigned active dispatch acknowledges');
foreach (['created','scheduled','completed','canceled','cancelled','failed'] as $status)
    check(!Policy::canAcknowledge('DEMO-A','DRIVER-A','VEHICLE-A',[...$order,'status'=>$status]),'inactive dispatch cannot acknowledge');
check(!Policy::canAcknowledge('DEMO-A','DRIVER-A','VEHICLE-B',$order),'other vehicle cannot acknowledge');
check(!Policy::canAcknowledge('DEMO-A','DRIVER-A',null,$order),'unassigned vehicle cannot acknowledge');
$scope=Policy::scopeHash('DEMO-A','USER-A','crew','DRIVER-A','VEHICLE-A',null);
check($scope!==Policy::scopeHash('DEMO-A','USER-A','crew','DRIVER-A','VEHICLE-B',null),'queued GPS cannot cross reassigned native vehicle');
check($scope!==Policy::scopeHash('DEMO-A','USER-A','admin','DRIVER-A','VEHICLE-A',null),'role changes invalidate session scope');
$now=1800000000000; $fix=['latitude'=>18.1,'longitude'=>82.7,'recorded_at'=>$now-1000,'accuracy_m'=>10];
check(Policy::freshFix($fix,$now,0),'fresh accurate real location valid');
check(!Policy::freshFix([...$fix,'latitude'=>0,'longitude'=>0],$now,0),'Exact unknown (0,0) pair cannot become live GPS');
check(!Policy::freshFix([...$fix,'latitude'=>'0.0','longitude'=>'-0'], $now,0),'Numeric-string unknown pair cannot bypass GPS rejection');
check(Policy::freshFix([...$fix,'latitude'=>0],$now,0),'Equator point with actual longitude remains valid');
check(Policy::freshFix([...$fix,'longitude'=>0],$now,0),'Prime-meridian point with actual latitude remains valid');
foreach ([['latitude'=>91],['longitude'=>181],['accuracy_m'=>101],['recorded_at'=>$now-300001],['recorded_at'=>$now+30001],['latitude'=>NAN]] as $change)
    check(!Policy::freshFix([...$fix,...$change],$now,0),'invalid GPS rejected');
check(!Policy::freshFix($fix,$now,$fix['recorded_at']),'duplicate timestamp rejected');
echo "$count policy checks passed\n";

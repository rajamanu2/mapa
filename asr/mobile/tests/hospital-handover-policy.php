<?php
require_once __DIR__.'/../HospitalHandoverPolicy.php';
use App\MapaMobile\HospitalHandoverPolicy as P;
$checks=0;
function verify(bool $v,string $label):void {global $checks; if(!$v)throw new RuntimeException($label);$checks++;}
$report=['company_uuid'=>'c','order_uuid'=>'o','place_uuid'=>'p','driver_uuid'=>'d','vehicle_uuid'=>'v'];
$order=['company_uuid'=>'c','uuid'=>'o','status'=>'started','dispatched'=>1,'deleted_at'=>null,'driver_assigned_uuid'=>'d','vehicle_assigned_uuid'=>'v'];
$place=['company_uuid'=>'c','uuid'=>'p','deleted_at'=>null];
verify(P::receivingPlaceMatches('c','p',$report,$order,$place),'same receiving hospital');
foreach(['company_uuid'=>'other','place_uuid'=>'other','order_uuid'=>'other'] as $key=>$value) verify(!P::receivingPlaceMatches('c','p',array_replace($report,[$key=>$value]),$order,$place),'report isolation '.$key);
foreach(['company_uuid'=>'other','uuid'=>'other','deleted_at'=>'2026-01-01'] as $key=>$value) verify(!P::receivingPlaceMatches('c','p',$report,$order,array_replace($place,[$key=>$value])),'destination isolation '.$key);
foreach(['created','scheduled','completed','failed','cancelled','canceled'] as $status) verify(!P::receivingPlaceMatches('c','p',$report,array_replace($order,['status'=>$status]),$place),'closed trip '.$status);
verify(!P::receivingPlaceMatches('c','p',$report,$order,null),'missing destination');
verify(!P::receivingPlaceMatches('other','p',$report,$order,$place),'tenant mismatch');
verify(!P::receivingPlaceMatches('c','',$report,$order,$place),'unbound hospital');
verify(!P::receivingPlaceMatches('c','p',$report,array_replace($order,['deleted_at'=>'2026-01-01']),$place),'deleted trip');
foreach(['driver_assigned_uuid','vehicle_assigned_uuid'] as $key) foreach(['other',null] as $value) verify(!P::receivingPlaceMatches('c','p',$report,array_replace($order,[$key=>$value]),$place),'changed assignment '.$key);
verify(!P::receivingPlaceMatches('c','p',$report,array_replace($order,['dispatched'=>0]),$place),'withdrawn dispatch');
verify(P::assignedPlaceMatches('c','p',$order,$place),'early assignment before observed report');
foreach(['created','scheduled','completed','failed','cancelled','canceled'] as $status) verify(!P::assignedPlaceMatches('c','p',array_replace($order,['status'=>$status]),$place),'early inactive '.$status);
foreach(['driver_assigned_uuid'=>null,'vehicle_assigned_uuid'=>null,'company_uuid'=>'other','dispatched'=>0,'deleted_at'=>'2026-01-01'] as $field=>$value)
 verify(!P::assignedPlaceMatches('c','p',array_replace($order,[$field=>$value]),$place),'early scope '.$field);
verify(!P::assignedPlaceMatches('c','other',$order,$place),'early destination mismatch');
verify(P::callerReported([])===null,'missing caller report stays missing');
$facts=P::callerReported(['reported_problem'=>'DEMO caller problem','patient_count'=>2,'note'=>'not caller facts','condition'=>'critical']);
verify($facts['observed_by_crew']===false&&!isset($facts['note'])&&!isset($facts['condition']),'intake cannot become observed condition');
echo "Hospital handover policy: $checks checks passed.\n";

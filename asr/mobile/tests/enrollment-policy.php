<?php
require __DIR__.'/../Policy.php'; require __DIR__.'/../EnrollmentPolicy.php';
use App\MapaMobile\EnrollmentPolicy as E;
$count=0; function check($ok,$name){global $count;if(!$ok)throw new RuntimeException($name);$count++;}
$now=1800000000000;$gps=['latitude'=>18.326,'longitude'=>82.874,'recorded_at'=>$now-1000,'received_at'=>$now,'accuracy_m'=>8,'mock_location'=>false];
check(E::token(str_repeat('a',64)),'opaque 256-bit token shape');
foreach(['a',str_repeat('a',63),str_repeat('a',65),str_repeat('A',64),str_repeat('g',64),null,123] as $t)check(!E::token($t),'invalid token');
check(E::gps($gps,$now),'actual fresh candidate fix');
foreach([['mock_location'=>true],['mock_location'=>null],['accuracy_m'=>101],['latitude'=>91],['longitude'=>181],['latitude'=>0,'longitude'=>0],['recorded_at'=>$now-120001],['recorded_at'=>$now+30001]] as $change)check(!E::gps([...$gps,...$change],$now),'invalid registration fix');
$body=['completion_note'=>'DEMO synthetic private handover note','photo_captured_at'=>$now-1500];
check(E::evidence($body,$gps,$now),'note camera and accepted GPS fresh');
foreach([['completion_note'=>''],['completion_note'=>str_repeat('a',2001)],['photo_captured_at'=>$now-120001],['photo_captured_at'=>$now+30001],['latitude'=>19],['recorded_at'=>$gps['recorded_at']-1],['mock_location'=>true]] as $change)check(!E::evidence([...$body,...$change],$gps,$now),'invalid completion evidence');
foreach([['received_at'=>$now-120001],['recorded_at'=>$now-32001],['mock_location'=>true],['accuracy_m'=>100.1]] as $change)check(!E::evidence($body,[...$gps,...$change],$now),'stale mismatched completion fix');
check(!E::evidence($body,$gps,$now+120001),'uncommitted stale draft must recapture explicitly');
$receipt=['ok'=>true,'completed'=>true];
// Service receipt replay precedes TripWorkflow and all evidence age/revision checks.
$source=file_get_contents(__DIR__.'/../MobileService.php');
check(strpos($source,'if ($receipt)')<strpos($source,'TripWorkflow)->apply'),'receipt precedes completion validation');
check(strpos($source,'$this->context($request,true)')<strpos($source,'if ($receipt)'),'receipt replay still authenticates current scope');
check(strpos($source,"Policy::ownsOrder")<strpos($source,'if ($receipt)'),'receipt replay still checks current order ownership');
check(App\MapaMobile\Policy::fingerprint('completion',$body)!==App\MapaMobile\Policy::fingerprint('completion',[...$body,'photo_captured_at'=>$now]),'changing captured time changes immutable receipt');
echo "Passed $count enrollment/evidence policy checks.\n";

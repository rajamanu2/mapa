<?php
/** Pure isolated evidence checks. No application, network, database or credentials. */
require __DIR__.'/../Way4TrackFixPolicy.php';
require __DIR__.'/../HardwareFixPolicy.php';
use App\MapaMobile\Way4TrackFixPolicy as P;
use App\MapaMobile\HardwareFixPolicy as H;
$checks=0;
function verify($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
$now=1791378000000;
$fix=['source_protocol'=>'way4track','position_quality'=>'provider_reported','latitude'=>18.4,'longitude'=>82.9,
    'accuracy_m'=>null,'recorded_at'=>$now-5000,'provider_received_at'=>$now-1000];
verify(P::fresh($fix,$now),'fresh provider-reported position accepted with accuracy unknown');
verify(H::fresh($fix,$now),'hardware policy delegates explicit provider type');
foreach([
    ['source_protocol'=>'gt06'],['position_quality'=>'verified'],['accuracy_m'=>0],['accuracy_m'=>5],
    ['gps_valid'=>true],['satellites'=>8],['latitude'=>91],['longitude'=>181],['latitude'=>0,'longitude'=>0],
    ['latitude'=>true],['latitude'=>NAN],['longitude'=>INF],['recorded_at'=>$now-300001],['recorded_at'=>$now+30001],
    ['recorded_at'=>true],['recorded_at'=>($now-1000)+0.5],['provider_received_at'=>false],
    ['provider_received_at'=>$now+30001],['provider_received_at'=>$fix['recorded_at']-30001],
] as $bad) verify(!P::fresh([...$fix,...$bad],$now),'invalid, stale or fabricated provider evidence rejected');
foreach(['accuracy_m','position_quality','recorded_at','provider_received_at','latitude','longitude'] as $key){
    $missing=$fix;unset($missing[$key]);verify(!P::fresh($missing,$now),'required evidence missing '.$key);
}
verify(!P::fresh($fix,$now,$fix['recorded_at']),'equal timestamp cannot append another position');
verify(!P::fresh($fix,$now,$fix['recorded_at']+1),'older timestamp cannot regress latest position');
verify(!P::fresh([...$fix,'recorded_at'=>$now-600000,'provider_received_at'=>$now],$now),'new receipt never freshens old GPS fix');
$last=(object)[...$fix,'gps_valid'=>null,'satellites'=>null];
verify(H::same([...$fix,'provider_received_at'=>$now],$last),'changed receipt time does not duplicate identical location');
verify(!H::same([...$fix,'latitude'=>18.5],$last),'same timestamp with altered coordinate is not an exact retry');
verify(!H::same([...$fix,'source_protocol'=>'gt06'], $last),'another source is not an exact provider retry');
echo "$checks Way4Track isolated quality checks passed.\n";

<?php
require __DIR__.'/../SourceTripEligibility.php';
use App\MapaVoice\SourceTripEligibility as P;
$n=0;function verify($ok,$message){global $n;if(!$ok)throw new RuntimeException($message);$n++;}
$state=['received_at'=>1000,'snapshot'=>['generated_at'=>1000,'complete'=>true,'contract'=>'mapa-asr-source-v3','datasets'=>[
    'drivers'=>[['id'=>'D1'],['id'=>'D2']],'ambulances'=>[['id'=>'V1'],['id'=>'V2']],
    'daily_status'=>[['id'=>'S1','ambulance_id'=>'V1']],'cad_dispatches'=>[],'trip_activity'=>[]]]];
$ids=['drivers'=>'D1','ambulances'=>'V1'];
verify(P::exactPlateId([['id'=>'V1','vehicle_number'=>'AP 31 AB 1234']],'AP31AB1234')==='V1','Exact normalized registration resolves one source ambulance');
verify(P::exactPlateId([['id'=>'V1','vehicle_number'=>'AP31AB1234'],['id'=>'V2','vehicle_number'=>'AP 31 AB 1234']],'AP31AB1234')===null,'Duplicate normalized plates cannot resolve identity');
verify(P::exactPlateId([['id'=>'V1','vehicle_number'=>'AP31TM1794']],'AP31TH1794')===null,'Plate typo is never treated as an exact mapping');
verify(P::evaluate($state,$ids,1000)===null,'Fresh exact source identities without unfinished trips remain eligible');
$open=$state;$open['snapshot']['datasets']['cad_dispatches'][]=['driver_id'=>'D1','ambulance_id'=>'V2','status'=>'dispatched'];
verify(P::evaluate($open,$ids,1000)['code']==='source_trip_unresolved','Matching driver active CAD blocks another ambulance');
verify(P::evaluate($open,['drivers'=>'D2','ambulances'=>'V2'],1000)['code']==='source_trip_unresolved','Matching ambulance active CAD blocks another driver');
verify(P::evaluate($open,['drivers'=>'D2','ambulances'=>'V1'],1000)===null,'Unrelated active source assignment does not block whole fleet');
$open=$state;$open['snapshot']['datasets']['trip_activity'][]=['driver_id'=>'D2','daily_status_id'=>'S1','drop_date'=>null,'drop_time'=>null];
verify(P::evaluate($open,$ids,1000)['code']==='source_trip_unresolved','Unclosed source trip matches ambulance through exact daily session');
$open['snapshot']['datasets']['trip_activity'][0]['drop_date']='2026-10-14';
verify(P::evaluate($open,$ids,1000)['code']==='source_trip_unresolved','Partial closure remains unresolved');
$open['snapshot']['datasets']['trip_activity'][0]['drop_time']='10:00:00';
verify(P::evaluate($open,$ids,1000)===null,'Explicit complete source drop does not block');
$old=$state;$old['received_at']=1;
verify(P::evaluate($old,$ids,1000)['code']==='source_trip_unknown','Stale snapshot is unknown rather than current busy/free claim');
$old=$state;$old['snapshot']['generated_at']=1;
verify(P::evaluate($old,$ids,1000)['code']==='source_trip_unknown','Recently received historical source generation remains stale');
verify(P::evaluate($state,['drivers'=>'missing'],1000)['code']==='source_trip_unknown','Missing exact source identity fails closed');
$old=$state;$old['snapshot']['complete']=false;
verify(P::evaluate($old,$ids,1000)['code']==='source_trip_unknown','Incomplete source snapshot cannot prove availability');
echo 'SOURCE TRIP ELIGIBILITY PASS '.$n.' checks; no network or production writes'.PHP_EOL;

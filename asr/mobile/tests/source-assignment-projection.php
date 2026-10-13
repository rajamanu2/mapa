<?php
require __DIR__.'/../SourceSync.php';
require __DIR__.'/../SourceAssignmentProjection.php';
use App\MapaMobile\SourceAssignmentProjection as P;
$s=['contract'=>\MapaSource\SourceSync::CONTRACT_V2,'datasets'=>[
    'ambulances'=>[['id'=>'10']], 'drivers'=>[['id'=>'20']],
    'cad_incidents'=>[['id'=>'1','status'=>'en_route'],['id'=>'2','status'=>'pending'],['id'=>'3','status'=>'completed']],
    'cad_dispatches'=>[]
]];
$make=fn($id,$incident,$status,$vehicle='10')=>['id'=>$id,'incident_id'=>$incident,'status'=>$status,'ambulance_id'=>$vehicle,'driver_id'=>'20'];
$s['datasets']['cad_dispatches']=[$make('a','1','en_route'),$make('b','2','dispatched'),$make('c','3','completed'),$make('d','1','cancelled'),$make('e','1','dispatched','99'),$make('f','missing','dispatched')];
$checks=0;$check=function($value,$message)use(&$checks){if(!$value)throw new RuntimeException($message);echo 'PASS '.(++$checks).' '.$message."\n";};
$a=P::active($s);
$check(count($a)===1&&$a[0]['id']==='a','only a current source assignment with known resources qualifies');
$check(P::label('cad_incidents',['id'=>'1'],$a)==='Assigned through asrhealth.in','active case displays source provenance');
$check(P::label('cad_incidents',['id'=>'2'],$a)===null,'pending call displays no assignment label');
$check(P::label('cad_incidents',['id'=>'3'],$a)===null,'completed history displays no active assignment label');
$check(P::label('ambulances',['id'=>'10'],$a)!==null,'exact source vehicle displays provenance');
$check(P::label('drivers',['id'=>'20'],$a)!==null,'exact source driver displays provenance');
$check(P::label('drivers',['id'=>'other'],$a)===null,'unrelated driver is never matched');
$s['contract']=\MapaSource\SourceSync::CONTRACT;
$check(P::active($s)===[],'old source contract cannot infer assignments');
echo "SOURCE ASSIGNMENT PROJECTION PASS $checks checks\n";

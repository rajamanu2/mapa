<?php
require __DIR__.'/SourceSync.php';
use MapaSource\SourceSync as S;
$s=['contract'=>S::CONTRACT,'source'=>'https://asrhealth.in','company_id'=>S::COMPANY,'complete'=>true,'generated_at'=>time(),'source_date'=>date('Y-m-d'),'datasets'=>array_fill_keys(array_keys(S::FIELDS),[])];
$s['datasets']['types']=[['id'=>'','type'=>'104']];
$vehicle=array_fill_keys(S::FIELDS['ambulances'],null);$vehicle['id']='test-vehicle';$vehicle['type_id']='';
$s['datasets']['ambulances']=[$vehicle];
S::validate($s,time());
foreach(['unknown-category','duplicate-category','blank-vehicle','unrecognized-reference'] as $case){
 $bad=$s;
 if($case==='unknown-category')$bad['datasets']['types'][0]['type']='Special';
 if($case==='duplicate-category')$bad['datasets']['types'][]=$bad['datasets']['types'][0];
 if($case==='blank-vehicle')$bad['datasets']['ambulances'][0]['id']='';
 if($case==='unrecognized-reference')$bad['datasets']['types']=[];
 try{S::validate($bad,time());throw new LogicException('Incorrectly accepted '.$case);}catch(RuntimeException $e){}
}
echo "LIVE CATEGORY PASS: empty ASR 104 key preserved; unknown, duplicate, blank vehicle and dangling empty reference rejected.\n";

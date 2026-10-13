<?php
require_once __DIR__.'/SourceCallFeed.php';
use MapaSource\SourceCallFeed as F;
$checks=0;function check($v,$message){global $checks;if(!$v)throw new RuntimeException($message);$checks++;}
function rejected(callable $fn){try{$fn();}catch(Throwable $e){check(true,'reject');return;}check(false,'Expected rejection');}
$row=array_fill_keys(F::FIELDS,null);$row=array_replace($row,['id'=>'1','incident_number'=>'DEMO-INC-001','caller_name'=>'DEMO CALLER','caller_phone'=>'2025550142','incident_type'=>'Medical','priority'=>'P2','status'=>'pending','created_at'=>'2026-10-07 10:00:00']);
$feed=['contract'=>F::CONTRACT,'company_id'=>'DEMO-COMPANY','source'=>'https://asrhealth.in','generated_at'=>time(),'truncated'=>false,'calls'=>[$row]];
F::validate($feed,'DEMO-COMPANY',time());check(true,'Valid synthetic caller accepted');
rejected(fn()=>F::validate($feed,'OTHER-COMPANY',time()));$bad=$feed;$bad['calls'][]=$row;rejected(fn()=>F::validate($bad,'DEMO-COMPANY',time()));
$bad=$feed;$bad['calls'][0]['password']='DEMO SECRET';rejected(fn()=>F::validate($bad,'DEMO-COMPANY',time()));
$bad=$feed;$bad['calls'][0]['status']='completed';rejected(fn()=>F::validate($bad,'DEMO-COMPANY',time()));
$bad=$feed;$bad['generated_at']-=121;rejected(fn()=>F::validate($bad,'DEMO-COMPANY',time()));
$bad=$feed;$bad['calls'][0]['caller_name']="DEMO\0BAD";rejected(fn()=>F::validate($bad,'DEMO-COMPANY',time()));
$secret=str_repeat('s',32);$ts=(string)time();$nonce=str_repeat('a',32);$server=['REQUEST_METHOD'=>'GET','QUERY_STRING'=>'','HTTP_X_MAPA_TIMESTAMP'=>$ts,'HTTP_X_MAPA_NONCE'=>$nonce,'HTTP_X_MAPA_SIGNATURE'=>F::signature($secret,$ts,$nonce)];
check(F::authorized($server,$secret,time()),'Signed caller read authorized');$server['QUERY_STRING']='id=1';check(!F::authorized($server,$secret,time()),'Query substitution rejected');$server['QUERY_STRING']='';$server['REQUEST_METHOD']='POST';check(!F::authorized($server,$secret,time()),'Writes rejected');
$other=$row;$other['caller_phone']='2025550199';check(F::revision($row)!==F::revision($other),'Phone change invalidates tracking context');
check(F::signature($secret,$ts,$nonce)!==hash_hmac('sha256',"GET\nmapa-asr-source-v1\n".$ts."\n".$nonce,$secret),'Fleet signature cannot authorize caller feed');
echo "PASS $checks source caller feed checks; synthetic data, no source database.\n";

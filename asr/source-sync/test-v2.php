<?php
use MapaSource\SourceSync as S;
// Extends the disposable eight-table v1 fixture; never a production database.
require_once __DIR__.'/SourceCommands.php';
rejects(fn()=>S::export($db,'Asia/Kolkata',S::CONTRACT_V2),'v2 missing operational schema fails closed');
check(S::supportedContracts()===[S::CONTRACT,S::CONTRACT_V2,S::CONTRACT_V3]&&S::capabilitiesFor(S::CONTRACT)===[],'v1 retained alongside explicit v2');
rejects(fn()=>S::fieldsFor('unknown'),'unknown source version rejected');
$extraTables=['repair_items'=>'amb_repair_items','trip_activity'=>'amb_trips','cad_incidents'=>'cad_incidents','cad_dispatches'=>'cad_dispatch_log','cad_status_updates'=>'cad_status_updates'];
$v2Tables=[...$tables,...$extraTables];
foreach(S::fieldsFor(S::CONTRACT_V2) as $dataset=>$fields){
 if(!isset($v2Tables[$dataset]))continue;
 $table=$v2Tables[$dataset];
 $exists=(bool)$root->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='$table'")->fetchColumn();
 $present=$exists?$root->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN):[];
 $cols=[];
 foreach($fields as $f)if(!in_array($f,$present,true))$cols[]="`$f` ".(in_array($f,['latitude','longitude','closing_odo','estimated_cost','distance_kms'])?'DOUBLE':($f==='is_closed'?'TINYINT DEFAULT 0':'VARCHAR(255) NULL'));
 if(!$exists)$root->exec("CREATE TABLE `$table` (".implode(',',$cols).") ENGINE=InnoDB");
 elseif($cols)$root->exec("ALTER TABLE `$table` ADD COLUMN ".implode(', ADD COLUMN ',$cols));
}
$root->exec("UPDATE ambulances SET mandal_id='M-DEMO',secretariat_id='S-DEMO',latitude=18.3,longitude=82.8 WHERE id=1");
$root->exec("UPDATE phc SET mandal_id='M-DEMO',latitude=18.31,longitude=82.81");
$root->exec("UPDATE amb_daily_status SET is_closed=1,closing_odo=125 WHERE id=1");
$root->exec("UPDATE amb_repairs SET daily_status_id=2,status='Pending',place_of_repair='DEMO workshop',estimated_cost=250");
$root->exec("INSERT INTO amb_repair_items VALUES ('1','1','DEMO tyre',250)");
$root->exec("UPDATE amb_trips SET pickup_date='2026-10-05',pickup_time='10:00:00',drop_date=NULL,drop_time=NULL WHERE id=1");
$root->exec("UPDATE amb_trips SET pickup_date='2026-10-05',pickup_time='09:00:00',drop_date='2026-10-05',drop_time='09:30:00' WHERE id=2");
$root->exec("ALTER TABLE cad_incidents ADD caller_name VARCHAR(255), ADD caller_phone VARCHAR(255), ADD patient_name VARCHAR(255), ADD pickup_address TEXT, ADD notes TEXT");
$root->exec("INSERT INTO cad_incidents (id,incident_number,incident_type,priority,from_phc_id,to_phc_id,status,dispatch_time,created_at,updated_at,caller_name,caller_phone,patient_name,pickup_address,notes) VALUES ('1','DEMO-INC-001','Medical','P2','PHC-A','PHC-B','en_route','2026-10-05 10:00:00','2026-10-05 09:58:00','2026-10-05 10:00:00','DEMO PRIVATE CALLER','DEMO PRIVATE PHONE','DEMO PRIVATE PATIENT','DEMO PRIVATE ADDRESS','DEMO PRIVATE NOTES')");
$root->exec("INSERT INTO cad_dispatch_log VALUES ('1','1','1','1','1','1','2026-10-05 10:00:00','10','en_route','2026-10-05 10:00:00')");
$root->exec("INSERT INTO cad_status_updates VALUES ('1','1','1','en_route','2026-10-05 10:00:00')");
$v2Digest=function()use($root,$v2Tables){$all=[];foreach(array_unique(array_values($v2Tables)) as $t)$all[$t]=$root->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);return hash('sha256',S::json($all));};
$beforeV2=$v2Digest();$v2=S::export($db,'Asia/Kolkata',S::CONTRACT_V2);
check($v2Digest()===$beforeV2,'expanded export leaves all operational and patient source rows unchanged');
check($v2['contract']===S::CONTRACT_V2&&$v2['source_timezone']==='Asia/Kolkata'&&$v2['capabilities']===S::V2_CAPABILITIES,'v2 version timezone and explicit capabilities');
check(!str_contains(S::json($v2),'DEMO PRIVATE'),'v2 CAD and trip summaries exclude patient caller address and free text');
check($v2['datasets']['ambulances'][0]['mandal_id']==='M-DEMO'&&(float)$v2['datasets']['ambulances'][0]['latitude']===18.3,'administrative hierarchy and base coordinate preserved');
check((int)$v2['datasets']['daily_status'][0]['is_closed']===1&&(float)$v2['datasets']['daily_status'][0]['closing_odo']===125.0,'closed session and closing odometer exported');
check($v2['datasets']['repairs'][0]['status']==='Pending'&&$v2['datasets']['repair_items'][0]['component_name']==='DEMO tyre','repair state place estimate and components retained');
check($v2['datasets']['cad_incidents'][0]['status']==='en_route'&&$v2['datasets']['cad_dispatches'][0]['trip_id']==='1','source owned incident dispatch and trip keys preserved');
check($v2['datasets']['trip_activity'][0]['drop_time']===null&&$v2['datasets']['trip_activity'][1]['drop_time']==='09:30:00','open and completed source trip row activity preserved without invented stages');
check($v2['capabilities']['dispatch_reservation']===false&&$v2['capabilities']['patient_records']===false,'snapshot never claims reservation or patient record access');
$compat=S::export($db,'Asia/Kolkata');
check($compat['contract']===S::CONTRACT&&!isset($compat['capabilities'])&&array_keys($compat['datasets'])===array_keys(S::FIELDS),'default export remains original v1 after schema expansion');
check(array_keys($compat['datasets']['ambulances'][0])===S::FIELDS['ambulances'],'v1 row fields remain byte-contract compatible');
check(\MapaSource\SourceCommands::revision('ambulances',$compat['datasets']['ambulances'][0])===\MapaSource\SourceCommands::revision('ambulances',$v2['datasets']['ambulances'][0]),'legacy profile revision stable for expanded v2 read row');
$bodyV2=S::json($v2);$nonceV2=bin2hex(random_bytes(16));
check(S::receive($bodyV2,S::responseSignature($secret,$nonceV2,$bodyV2),$nonceV2,$secret,time())['contract']===S::CONTRACT_V2,'receiver verifies signed v2 snapshot');
foreach(['cad_incidents','cad_dispatches','cad_status_updates','trip_activity','repair_items'] as $dataset){
 $bad=$v2;unset($bad['datasets'][$dataset]);rejects(fn()=>S::validate($bad,time()),'v2 rejects partial '.$dataset);
}
foreach(['capabilities'=>[],'source_timezone'=>'not-a-timezone','contract'=>'mapa-asr-source-v3'] as $field=>$value){
 $bad=$v2;$bad[$field]=$value;rejects(fn()=>S::validate($bad,time()),'v2 rejects invalid '.$field);
}
$bad=$v2;$bad['datasets']['cad_incidents'][0]['caller_phone']='DEMO PRIVATE';rejects(fn()=>S::validate($bad,time()),'v2 rejects unallowlisted caller field');
$bad=$v2;$bad['datasets']['cad_dispatches'][]=$bad['datasets']['cad_dispatches'][0];rejects(fn()=>S::validate($bad,time()),'v2 rejects duplicate source dispatch identity');
foreach(['latitude'=>91,'longitude'=>181] as $field=>$value){$bad=$v2;$bad['datasets']['ambulances'][0][$field]=$value;rejects(fn()=>S::validate($bad,time()),'v2 rejects invalid base '.$field);}
$bad=$v2;$bad['datasets']['daily_status'][0]['is_closed']='unknown';rejects(fn()=>S::validate($bad,time()),'v2 unknown closure fails closed');
$bad=$v2;$bad['datasets']['repairs'][0]['estimated_cost']='bad';rejects(fn()=>S::validate($bad,time()),'v2 invalid repair amount fails closed');
$versionState='/tmp/mapa-v2-state.json';S::save($versionState,$compat,time());$v2['generated_at']=microtime(true)+.01;S::save($versionState,$v2,time());
check(json_decode(file_get_contents($versionState),true)['snapshot']['contract']===S::CONTRACT_V2,'state upgrades from v1 to v2 atomically');
$oldVersionState=file_get_contents($versionState);
$root->exec('RENAME TABLE cad_status_updates TO cad_status_updates_missing');
rejects(fn()=>S::save($versionState,S::export($db,'Asia/Kolkata',S::CONTRACT_V2),time()),'v2 incomplete source schema does not promote partial snapshot');
check(file_get_contents($versionState)===$oldVersionState,'v2 schema failure preserves previous verified snapshot');
$root->exec('RENAME TABLE cad_status_updates_missing TO cad_status_updates');
// Real HTTP configuration opt-in; no original ASR bootstrap or outbound provider connection.
$web2='/tmp/source-public-v2';mkdir($web2);copy(__DIR__.'/source-export.php',$web2.'/mapa-source-export.php');copy(__DIR__.'/SourceSync.php',$web2.'/SourceSync.php');
$config2='/tmp/source-private-v2.json';file_put_contents($config2,S::json(['enabled'=>true,'contract'=>S::CONTRACT_V2,'source_timezone'=>'Asia/Kolkata','dsn'=>"mysql:host=$host;dbname=mapa_source_qa;charset=utf8mb4",'username'=>'mirror_reader','password'=>'DEMO-read-only','secret'=>$secret]));
putenv('MAPA_SOURCE_EXPORT_CONFIG='.$config2);file_put_contents('/tmp/mapa-source-export.lock','0');
$proc2=proc_open(['php','-S','127.0.0.1:18792','-t',$web2],[['pipe','r'],['file','/tmp/source-http-v2.log','a'],['file','/tmp/source-http-v2.log','a']],$pipes2);
try{
 for($i=0;$i<30;$i++){$sock=@fsockopen('127.0.0.1',18792);if($sock){fclose($sock);break;}usleep(100000);}
 $ts2=(string)time();$nonce2=bin2hex(random_bytes(16));$h=curl_init('http://127.0.0.1:18792/mapa-source-export.php');$sig2='';
 curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_HTTPHEADER=>['X-Mapa-Timestamp: '.$ts2,'X-Mapa-Nonce: '.$nonce2,'X-Mapa-Signature: '.S::signature($secret,$ts2,$nonce2)],CURLOPT_HEADERFUNCTION=>static function($h,$line)use(&$sig2){if(stripos($line,'X-Mapa-Signature:')===0)$sig2=trim(substr($line,17));return strlen($line);}]);
 $body2=curl_exec($h);$status2=curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
 check($status2===200&&S::receive($body2,$sig2,$nonce2,$secret,time())['contract']===S::CONTRACT_V2,'HTTP exporter opt-in and signed receiver support v2 end to end');
}finally{proc_terminate($proc2);proc_close($proc2);}
$root->exec('CREATE TABLE mandals (mandal_id VARCHAR(64) PRIMARY KEY,name VARCHAR(200)) ENGINE=InnoDB');
$root->exec("INSERT INTO mandals VALUES ('DEMO-M','DEMO Mandal')");
$root->exec('CREATE TABLE secretariat (secretariat_id VARCHAR(64) PRIMARY KEY,name VARCHAR(200),phc_id VARCHAR(64)) ENGINE=InnoDB');
$root->exec('CREATE TABLE villages (village_id VARCHAR(64) PRIMARY KEY,name VARCHAR(200),secretariat_id VARCHAR(64)) ENGINE=InnoDB');
$root->exec("INSERT INTO secretariat VALUES ('SEC-DEMO','DEMO Secretariat','PHC-A')");
$root->exec("INSERT INTO villages VALUES ('VIL-DEMO','DEMO Village','SEC-DEMO')");
$v3=S::export($db,'Asia/Kolkata',S::CONTRACT_V3);
check($v3['capabilities']['location_directory']===true&&$v3['datasets']['villages'][0]['secretariat_id']==='SEC-DEMO','v3 exports exact geographic hierarchy');
check(!str_contains(S::json($v3),'DEMO PRIVATE'),'geographic extension does not export patient or caller records');
$bad=$v3;$bad['datasets']['villages'][0]['secretariat_id']='FOREIGN-DEMO';rejects(fn()=>S::validate($bad,time()),'orphan village rejected');
$bad=$v3;$bad['datasets']['secretariats'][0]['phc_id']='FOREIGN-DEMO';rejects(fn()=>S::validate($bad,time()),'orphan secretariat rejected');
$nonce3=bin2hex(random_bytes(16));$body3=S::json($v3);check(S::receive($body3,S::responseSignature($secret,$nonce3,$body3),$nonce3,$secret,time())['contract']===S::CONTRACT_V3,'signed receiver accepts v3 geography');

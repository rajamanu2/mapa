<?php
declare(strict_types=1);
require_once __DIR__.'/SourceCommands.php';
require_once __DIR__.'/SourceCoordinationPolicy.php';
use MapaSource\SourceCommands as C;
use MapaSource\SourceSync as S;
use MapaSource\CommandRejected;
$host=getenv('QA_DB_HOST');
if(!str_starts_with($host?:'','mapa-source-qa-')) throw new RuntimeException('Isolated source QA host required.');
$count=0;
function commandCheck(bool $ok,string $label):void {global $count;if(!$ok)throw new RuntimeException($label);$count++;echo "PASS $label\n";}
function commandReject(callable $fn,int $status,string $label):void {try{$fn();}catch(CommandRejected $e){commandCheck($e->status===$status,$label);return;}throw new RuntimeException($label.' was accepted');}
$root=new PDO("mysql:host=$host;charset=utf8mb4",'root','DEMO-source-qa-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$root->exec('CREATE DATABASE mapa_commands_qa');$root->exec('USE mapa_commands_qa');
foreach(['drivers'=>'amb_drivers','ambulances'=>'ambulances'] as $dataset=>$table) {
    $fields=[];foreach(S::FIELDS[$dataset] as $f)$fields[]="`$f` VARCHAR(255) NULL";
    $root->exec("CREATE TABLE $table (".implode(',',$fields).', UNIQUE KEY (id)) ENGINE=InnoDB');
}
$root->exec('CREATE TABLE phc (phc_id VARCHAR(64) PRIMARY KEY,name VARCHAR(255),type VARCHAR(64)) ENGINE=InnoDB');
$root->exec('CREATE TABLE institute_type (id VARCHAR(64) PRIMARY KEY,type VARCHAR(255)) ENGINE=InnoDB');
$root->exec('CREATE TABLE type_ambulance (id VARCHAR(64) PRIMARY KEY,type VARCHAR(255)) ENGINE=InnoDB');
$root->exec('CREATE TABLE amb_trips (id INT PRIMARY KEY,patient_name VARCHAR(255),daily_status_id INT,driver_id INT,drop_date DATE,drop_time TIME) ENGINE=InnoDB');
$root->exec('CREATE TABLE amb_daily_status (id INT PRIMARY KEY,ambulance_id INT) ENGINE=InnoDB');
$root->exec(file_get_contents(__DIR__.'/dispatch-reservations.sql'));
$root->exec(file_get_contents(__DIR__.'/manual-command-ledger.sql'));
foreach(['amb_repairs','amb_repair_items','cad_incidents','cad_dispatch_log','cad_status_updates'] as $table)$root->exec("CREATE TABLE $table(id INT PRIMARY KEY) ENGINE=InnoDB");
$root->exec("CREATE TABLE mapa_source_commands (request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,request_hash CHAR(64) NOT NULL,actor_id CHAR(36) NOT NULL,action_name VARCHAR(40) NOT NULL,record_id VARCHAR(64) NOT NULL,expected_revision CHAR(64) NOT NULL,receipt JSON NULL,changes_json JSON NULL,created_at DATETIME(6) NOT NULL,completed_at DATETIME(6) NULL) ENGINE=InnoDB");
$root->exec("INSERT INTO phc VALUES ('PHC-A','DEMO source A','1'),('PHC-B','DEMO source B','1'),('PHC-C','DEMO restricted C','2')");
$root->exec("INSERT INTO institute_type VALUES ('1','PHC'),('2','Hospital')");
$root->exec("INSERT INTO type_ambulance VALUES ('amb_01','Special'),('amb_02','108')");
$root->exec("INSERT INTO amb_drivers (id,name,phc_id,vehicle_type,mobile_number) VALUES ('1','DEMO driver','PHC-A','Special','9000000000'),('2','DEMO shared phone','PHC-A','108','9000000000')");
$root->exec("INSERT INTO ambulances (id,vehicle_number,phc_id,institute_type_id,type_id,vehicle_model,model_year) VALUES ('1','DEMO-001','PHC-A','1','amb_01','DEMO model','2020')");
$root->exec("INSERT INTO amb_daily_status VALUES(1,1); INSERT INTO amb_trips VALUES (1,'DEMO PRIVATE PATIENT',1,1,'2026-10-05','12:00:00')");
$root->exec("CREATE USER 'command_writer'@'%' IDENTIFIED BY 'DEMO-command-only'");
foreach(['ambulances','amb_drivers','phc','institute_type','type_ambulance'] as $table)$root->exec("GRANT SELECT ON mapa_commands_qa.$table TO 'command_writer'@'%'");
$root->exec("GRANT UPDATE (vehicle_model,model_year,phc_id,institute_type_id) ON mapa_commands_qa.ambulances TO 'command_writer'@'%'");
$root->exec("GRANT UPDATE (phc_id) ON mapa_commands_qa.amb_drivers TO 'command_writer'@'%'");
$root->exec("GRANT SELECT,INSERT,UPDATE ON mapa_commands_qa.mapa_source_commands TO 'command_writer'@'%'");
$root->exec("GRANT SELECT ON mapa_commands_qa.mapa_dispatch_reservations TO 'command_writer'@'%'; GRANT SELECT(id,ambulance_id) ON mapa_commands_qa.amb_daily_status TO 'command_writer'@'%'; GRANT SELECT(id,daily_status_id,driver_id,drop_date,drop_time) ON mapa_commands_qa.amb_trips TO 'command_writer'@'%'");
foreach(['amb_repairs','amb_repair_items','cad_incidents','cad_dispatch_log','cad_status_updates'] as $table)$root->exec("GRANT SELECT(id) ON mapa_commands_qa.$table TO 'command_writer'@'%'");
$root->exec("GRANT SELECT(request_id) ON mapa_commands_qa.mapa_manual_commands TO 'command_writer'@'%'");
$db=new PDO("mysql:host=$host;dbname=mapa_commands_qa;charset=utf8mb4",'command_writer','DEMO-command-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$actor='10000000-0000-4000-8000-000000000001';$grants=[$actor=>['actions'=>C::ACTIONS,'vehicle_types'=>['Special'],'phc_ids'=>['PHC-A','PHC-B']]];
$seq=0;
$command=function(string $action,array $values,string $id='1')use(&$seq,$actor,$root) {
    $dataset=$action==='driver.transfer'?'drivers':'ambulances';$table=$dataset==='drivers'?'amb_drivers':'ambulances';
    $q=$root->prepare("SELECT * FROM $table WHERE id=?");$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    return ['contract'=>C::CONTRACT,'company_id'=>S::COMPANY,'request_id'=>sprintf('20000000-0000-4000-8000-%012d',++$seq),'actor_id'=>$actor,'action'=>$action,'record_id'=>$id,'expected_revision'=>C::revision($dataset,$row),'values'=>$values];
};
$cmd=$command('ambulance.profile',['vehicle_model'=>'DEMO updated','model_year'=>2025]);
$secret=str_repeat('s',64);$ts=(string)time();$nonce=str_repeat('a',32);$body=S::json($cmd);
$server=['REQUEST_METHOD'=>'POST','QUERY_STRING'=>'','CONTENT_TYPE'=>'application/json','HTTP_X_MAPA_TIMESTAMP'=>$ts,'HTTP_X_MAPA_NONCE'=>$nonce,'HTTP_X_MAPA_SIGNATURE'=>C::signature($secret,$ts,$nonce,$body)];
commandCheck(C::authorized($server,$secret,$body,time()),'body-bound signed POST accepted');
commandCheck(!C::authorized($server,$secret,$body.' ',time()),'modified signed body denied');
commandCheck(!C::authorized($server,$secret,$body,time()+31),'expired signature denied');
foreach(['REQUEST_METHOD'=>'GET','QUERY_STRING'=>'url=https://other.invalid','CONTENT_TYPE'=>'text/plain','HTTP_X_MAPA_NONCE'=>'x'] as $key=>$value) {$bad=$server;$bad[$key]=$value;commandCheck(!C::authorized($bad,$secret,$body,time()),'reject header '.$key);}
commandCheck(!C::authorized($server,'short',$body,time()),'weak command secret denied');
commandCheck(C::responseSignature($secret,$nonce,'a')!==C::responseSignature($secret,$nonce,'b'),'response signature binds exact response');
$receipt=C::execute($db,$cmd,$grants);
commandCheck($root->query('SELECT vehicle_model FROM ambulances WHERE id=1')->fetchColumn()==='DEMO updated','source profile updated');
commandCheck($receipt['refresh_required']===true&&$receipt['request_id']===$cmd['request_id'],'receipt requires authoritative refresh');
commandCheck(C::execute($db,$cmd,$grants)==$receipt,'same request replay returns committed receipt');
commandCheck((int)$root->query('SELECT COUNT(*) FROM mapa_source_commands')->fetchColumn()===1,'replay creates one audit receipt');
$bad=$cmd;$bad['values']['vehicle_model']='DEMO different';commandReject(fn()=>C::execute($db,$bad,$grants),409,'same request different body conflicts');
$bad=$cmd;$bad['request_id']='30000000-0000-4000-8000-000000000001';commandReject(fn()=>C::execute($db,$bad,$grants),409,'stale revision conflicts');
commandCheck((int)$root->query('SELECT COUNT(*) FROM mapa_source_commands')->fetchColumn()===1,'rejected command rolls back ledger reservation');
commandReject(fn()=>C::execute($db,$cmd,[]),403,'actor revoked even on idempotent retry');
$cmd=$command('driver.transfer',['phc_id'=>'PHC-B']);C::execute($db,$cmd,$grants);
commandCheck($root->query('SELECT phc_id FROM amb_drivers WHERE id=1')->fetchColumn()==='PHC-B','driver PHC transfer succeeds');
commandCheck($root->query('SELECT phc_id FROM amb_drivers WHERE id=2')->fetchColumn()==='PHC-A','shared phone identity is untouched');
commandCheck($root->query('SELECT phc_id FROM ambulances WHERE id=1')->fetchColumn()==='PHC-A','driver transfer does not move ambulance');
$cmd=$command('ambulance.transfer',['phc_id'=>'PHC-B','institute_type_id'=>'1']);C::execute($db,$cmd,$grants);
commandCheck($root->query('SELECT phc_id FROM ambulances WHERE id=1')->fetchColumn()==='PHC-B','ambulance PHC transfer succeeds');
$cmd=$command('ambulance.transfer',['phc_id'=>'PHC-A','institute_type_id'=>'2']);commandReject(fn()=>C::execute($db,$cmd,$grants),422,'institute and PHC mismatch denied');
$cmd=$command('driver.transfer',['phc_id'=>'PHC-C']);commandReject(fn()=>C::execute($db,$cmd,$grants),403,'destination PHC outside actor scope denied');
$cmd=$command('driver.transfer',['phc_id'=>'PHC-A'],'2');commandReject(fn()=>C::execute($db,$cmd,$grants),403,'coordinator fleet category boundary enforced');
$narrow=$grants;$narrow[$actor]['phc_ids']=['PHC-A'];$cmd=$command('driver.transfer',['phc_id'=>'PHC-A']);commandReject(fn()=>C::execute($db,$cmd,$narrow),403,'current PHC outside scope denied');
$none=$grants;$none[$actor]['actions']=[];commandReject(fn()=>C::execute($db,$cmd,$none),403,'module action permission required');
$global=$grants;$global[$actor]['phc_ids']=['*'];$cmd=$command('driver.transfer',['phc_id'=>'MISSING']);commandReject(fn()=>C::execute($db,$cmd,$global),422,'nonexistent destination denied');
$cmd=$command('ambulance.profile',['vehicle_model'=>'DEMO model','model_year'=>2020]);
foreach(['record_id'=>'1 OR 1=1','company_id'=>'foreign','action'=>'ambulance.delete','expected_revision'=>'bad'] as $k=>$v) {$bad=$cmd;$bad[$k]=$v;commandReject(fn()=>C::execute($db,$bad,$grants),422,'invalid '.$k.' denied');}
$bad=$cmd;$bad['values']['patient_name']='not allowed';commandReject(fn()=>C::execute($db,$bad,$grants),422,'unapproved extra fields denied');
$bad=$cmd;$bad['values']['model_year']='2020';commandReject(fn()=>C::execute($db,$bad,$grants),422,'year type checked');
$bad=$cmd;$bad['values']['vehicle_model']=str_repeat('x',101);commandReject(fn()=>C::execute($db,$bad,$grants),422,'model length bounded');
$bad=$cmd;$bad['record_id']='missing';commandReject(fn()=>C::execute($db,$bad,$grants),404,'deleted source record denied');
$q=$root->query('SELECT * FROM ambulances WHERE id=1');$row=$q->fetch(PDO::FETCH_ASSOC);$typed=$row;$typed['model_year']=(int)$typed['model_year'];
commandCheck(C::revision('ambulances',$row)===C::revision('ambulances',$typed),'revision stable across numeric transport types');
$cmd=$command('ambulance.profile',['vehicle_model'=>'DEMO model','model_year'=>null]);$root->exec("UPDATE ambulances SET parking_place_name='DEMO concurrent edit' WHERE id=1");commandReject(fn()=>C::execute($db,$cmd,$grants),409,'unrelated original record change blocks stale edit');
foreach(["DELETE FROM ambulances WHERE id='1'","UPDATE amb_drivers SET mobile_number='changed' WHERE id='1'",'SELECT * FROM amb_trips','CREATE TABLE should_not_exist (id INT)'] as $sql) {try{$db->exec($sql);throw new RuntimeException('Restricted writer unexpectedly allowed operation');}catch(PDOException $e){commandCheck(true,'restricted writer rejects '.strtok($sql,' '));}}
commandCheck($root->query('SELECT patient_name FROM amb_trips WHERE id=1')->fetchColumn()==='DEMO PRIVATE PATIENT','patient data unchanged');
commandCheck(!str_contains($root->query('SELECT changes_json FROM mapa_source_commands LIMIT 1')->fetchColumn(),'patient'),'audit contains changed fields only');
$cmd=$command('ambulance.profile',['vehicle_model'=>'DEMO rollback','model_year'=>null]);
$root->exec("CREATE TRIGGER demo_reject_receipt BEFORE UPDATE ON mapa_source_commands FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DEMO receipt failure'");
$before=$root->query('SELECT vehicle_model FROM ambulances WHERE id=1')->fetchColumn();$ledgers=(int)$root->query('SELECT COUNT(*) FROM mapa_source_commands')->fetchColumn();
try {C::execute($db,$cmd,$grants);throw new RuntimeException('Audit failure unexpectedly succeeded');}catch(PDOException $e){commandCheck(true,'audit failure surfaced');}
commandCheck($root->query('SELECT vehicle_model FROM ambulances WHERE id=1')->fetchColumn()===$before,'audit failure rolls back source update');
commandCheck((int)$root->query('SELECT COUNT(*) FROM mapa_source_commands')->fetchColumn()===$ledgers,'audit failure rolls back idempotency insertion');
$root->exec('DROP TRIGGER demo_reject_receipt');
$root->exec("INSERT INTO mapa_dispatch_reservations(company_id,journey_id,ambulance_id,driver_id,status,version,created_at) VALUES ('".S::COMPANY."','DEMO-active-journey',1,1,'reserved',1,UTC_TIMESTAMP(6))");
$blocked=$command('ambulance.profile',['vehicle_model'=>'DEMO blocked model','model_year'=>2020]);commandReject(fn()=>C::execute($db,$blocked,$grants),409,'master profile cannot alter MAPA-reserved ambulance');$blocked=$command('driver.transfer',['phc_id'=>'PHC-A']);commandReject(fn()=>C::execute($db,$blocked,$grants),409,'driver transfer cannot alter MAPA reservation');
$root->exec("UPDATE mapa_dispatch_reservations SET terminal_at=UTC_TIMESTAMP(6),status='cancelled',version=2 WHERE journey_id='DEMO-active-journey'; UPDATE amb_trips SET drop_date=NULL,drop_time=NULL WHERE id=1");$blocked=$command('ambulance.transfer',['phc_id'=>'PHC-A','institute_type_id'=>'1']);commandReject(fn()=>C::execute($db,$blocked,$grants),409,'ambulance transfer cannot alter active original CAD trip');$root->exec("UPDATE amb_trips SET drop_date='2026-10-05',drop_time='12:00:00' WHERE id=1");
// Exercise the actual source endpoint, isolated loopback only, with no original bootstrap.
$web='/tmp/command-public';mkdir($web);foreach(['SourceSync.php','SourceCommands.php','SourceDispatchCoordinator.php','SourceCoordinationPolicy.php'] as $file)copy(__DIR__.'/'.$file,$web.'/'.$file);copy(__DIR__.'/source-command.php',$web.'/mapa-source-command.php');
$config='/tmp/command-private.json';file_put_contents($config,S::json(['enabled'=>true,'secret'=>$secret,'dsn'=>"mysql:host=$host;dbname=mapa_commands_qa;charset=utf8mb4",'username'=>'command_writer','password'=>'DEMO-command-only','actors'=>$grants]));putenv('MAPA_SOURCE_COMMAND_CONFIG='.$config);
$policyPath='/tmp/command-coordination-policy.json';$policy=['enabled'=>true,'maintenance_mode'=>false,'schema_sha256'=>MapaSource\SourceCoordinationPolicy::schemaReceipt($root),'command_schema_sha256'=>MapaSource\SourceCoordinationPolicy::schemaReceipt($db)];file_put_contents($policyPath,S::json($policy));putenv('MAPA_SOURCE_COORDINATION_CONFIG='.$policyPath);
$proc=proc_open(['php','-S','127.0.0.1:18792','-t',$web],[['pipe','r'],['file','/tmp/command-http.log','a'],['file','/tmp/command-http.log','a']],$pipes);
try {
    for($i=0;$i<30;$i++){$sock=@fsockopen('127.0.0.1',18792);if($sock){fclose($sock);break;}usleep(100000);}
    $request=function(string $body,bool $signed=true)use($secret,$nonce) {
        $h=curl_init('http://127.0.0.1:18792/mapa-source-command.php');$signature='';$ts=(string)time();$headers=['Content-Type: application/json'];
        if($signed)$headers=[...$headers,'X-Mapa-Timestamp: '.$ts,'X-Mapa-Nonce: '.$nonce,'X-Mapa-Signature: '.C::signature($secret,$ts,$nonce,$body)];
        curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_HTTPHEADER=>$headers,CURLOPT_HEADERFUNCTION=>static function($h,$line)use(&$signature){if(stripos($line,'X-Mapa-Signature:')===0)$signature=trim(substr($line,17));return strlen($line);}]);
        $response=curl_exec($h);$code=curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);return [$code,$response,$signature];
    };
    commandCheck($request(S::json($cmd),false)[0]===401,'HTTP unsigned command denied');
    $badPolicy=$policy;$badPolicy['enabled']=false;file_put_contents($policyPath,S::json($badPolicy));commandCheck($request(S::json($cmd))[0]===503,'HTTP command blocked while coordination disabled');
    $badPolicy['maintenance_mode']=true;file_put_contents($policyPath,S::json($badPolicy));commandCheck($request(S::json($cmd))[0]===503,'HTTP command blocked during maintenance');
    $badPolicy=$policy;unset($badPolicy['command_schema_sha256']);file_put_contents($policyPath,S::json($badPolicy));commandCheck($request(S::json($cmd))[0]===503,'HTTP command blocked without account schema attestation');
    $badPolicy=$policy;$badPolicy['command_schema_sha256']=str_repeat('0',64);file_put_contents($policyPath,S::json($badPolicy));commandCheck($request(S::json($cmd))[0]===503,'HTTP command blocked on schema drift');
    unlink($policyPath);commandCheck($request(S::json($cmd))[0]===503,'HTTP command blocked without private coordination policy');file_put_contents($policyPath,S::json($policy));
    [$code,$body,$signature]=$request(S::json($cmd));
    commandCheck($code===200,'HTTP signed source command succeeds');
    commandCheck(hash_equals(C::responseSignature($secret,$nonce,$body),$signature),'HTTP receipt signed and nonce-bound');
    commandCheck(json_decode($body,true)['request_id']===$cmd['request_id'],'HTTP receipt bound to command ID');
    [$code,$replay]=$request(S::json($cmd));commandCheck($code===200&&json_decode($body,true)==json_decode($replay,true),'HTTP retry is idempotent');
    $bad=$cmd;$bad['values']['vehicle_model']='DEMO conflicting retry';[$code,$error,$signature]=$request(S::json($bad));$err=json_decode($error,true);
    commandCheck($code===409&&$err['outcome']==='rejected'&&$err['request_id']===$cmd['request_id'],'HTTP conflict identifies rejected command');
    commandCheck(hash_equals(C::responseSignature($secret,$nonce,$error),$signature),'HTTP rejection is signed');
    commandCheck(!str_contains($error,'DEMO-command-only')&&!str_contains($error,'mysql:'),'HTTP errors omit credentials');
} finally {if(is_resource($proc)){proc_terminate($proc);proc_close($proc);}}
echo "SOURCE COMMANDS PASS $count checks\n";

<?php
declare(strict_types=1);
require __DIR__.'/SourceSync.php';
require __DIR__.'/SourceReceiver.php';
use MapaSource\SourceSync as S;
$count=0;
function check($condition,$label) {global $count;if(!$condition)throw new RuntimeException($label);$count++;echo "PASS $label\n";}
function rejects(callable $fn,string $label) {try{$fn();}catch(Throwable $e){check(true,$label);return;}check(false,$label);}
$host=getenv('QA_DB_HOST');if(!str_starts_with($host?:'','mapa-source-qa-'))throw new RuntimeException('Isolated QA host required.');
$root=new PDO("mysql:host=$host;dbname=mapa_source_qa;charset=utf8mb4",'root','DEMO-source-qa-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
check((int)$root->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()===0,'fresh empty QA schema');
$tables=['drivers'=>'amb_drivers','ambulances'=>'ambulances','hospitals'=>'phc','types'=>'type_ambulance','institute_types'=>'institute_type','daily_status'=>'amb_daily_status','repairs'=>'amb_repairs'];
foreach($tables as $dataset=>$table) {
    $cols=array_map(fn($f)=>"`$f` ".(in_array($f,['id','ambulance_id','driver_id','type_id','institute_type_id','age','model_year'])?'INT':(in_array($f,['odo_reading'])?'DOUBLE':'VARCHAR(255)')).' NULL',S::FIELDS[$dataset]);
    $root->exec("CREATE TABLE `$table` (".implode(',',$cols).") ENGINE=InnoDB");
}
$root->exec("CREATE TABLE amb_trips (id INT,daily_status_id INT,driver_id INT,trip_type VARCHAR(50),purpose VARCHAR(100),distance_kms DOUBLE,patient_name VARCHAR(255),phone_number VARCHAR(20)) ENGINE=InnoDB");
$root->exec("INSERT INTO amb_drivers VALUES (1,'DEMO driver',30,'Male','1996-01-01','2026-10-02','PHC-A','9000000000','Special'),(2,'DEMO same phone',NULL,NULL,NULL,NULL,'PHC-B','9000000000','104')");
$root->exec("INSERT INTO ambulances VALUES (1,'DEMO-001',1,'PHC-A',1,'DEMO model',2020,'hospital',NULL)");
$root->exec("INSERT INTO phc VALUES ('PHC-A','DEMO hospital A','1'),('PHC-B','DEMO hospital B','1')");
$root->exec("INSERT INTO type_ambulance VALUES (1,'Special'),(2,'104')");
$root->exec("INSERT INTO institute_type VALUES (1,'PHC')");
$root->exec("INSERT INTO amb_daily_status VALUES (1,1,'PHC-A','2026-10-02','On Road',100),(2,1,'PHC-A','2026-10-03','Off Road',120)");
$root->exec("INSERT INTO amb_repairs VALUES (1,1,'2026-10-03',NULL,'DEMO repair',120)");
$root->exec("INSERT INTO amb_trips VALUES (1,1,1,'patient','Delivery',20,'DEMO PRIVATE PATIENT','DEMO PRIVATE PHONE'),(2,1,1,'deadbody',NULL,5,'DEMO PRIVATE PATIENT','DEMO PRIVATE PHONE')");
$root->exec("CREATE USER 'mirror_reader'@'%' IDENTIFIED BY 'DEMO-read-only'");
$root->exec("GRANT SELECT ON mapa_source_qa.* TO 'mirror_reader'@'%'");
$db=new PDO("mysql:host=$host;dbname=mapa_source_qa;charset=utf8mb4",'mirror_reader','DEMO-read-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$digest=function()use($root,$tables){$all=[];foreach([...array_values($tables),'amb_trips'] as $t)$all[$t]=$root->query("SELECT * FROM $t")->fetchAll(PDO::FETCH_ASSOC);return hash('sha256',S::json($all));};
$before=$digest();$s=S::export($db);
check($digest()===$before,'export leaves every source row unchanged');
check(count($s['datasets']['drivers'])===2,'shared phone does not merge driver identities');
check(count($s['datasets']['vehicle_statistics'])===2,'patient and deceased counts remain separate');
check(!str_contains(S::json($s),'DEMO PRIVATE'),'patient names and contacts excluded');
check(count($s['datasets']['allocations'])===1,'daily allocation identities deduplicated');
rejects(fn()=>$db->exec("UPDATE amb_drivers SET name='changed' WHERE id=1"),'read-only account cannot alter original drivers');
$secret=str_repeat('q',64);$ts=(string)time();$nonce=str_repeat('a',32);
$server=['REQUEST_METHOD'=>'GET','QUERY_STRING'=>'','HTTP_X_MAPA_TIMESTAMP'=>$ts,'HTTP_X_MAPA_NONCE'=>$nonce,'HTTP_X_MAPA_SIGNATURE'=>S::signature($secret,$ts,$nonce)];
check(S::authorized($server,$secret,time()),'signed GET accepted');
foreach(['HTTP_X_MAPA_SIGNATURE'=>'','HTTP_X_MAPA_TIMESTAMP'=>(string)(time()-31),'HTTP_X_MAPA_NONCE'=>'x','REQUEST_METHOD'=>'POST','QUERY_STRING'=>'token=bad'] as $k=>$v) {
    $bad=$server;$bad[$k]=$v;check(!S::authorized($bad,$secret,time()),'reject '.$k);
}
check(!S::authorized($server,'short',time()),'reject weak configuration');
$body=S::json($s);$sig=S::responseSignature($secret,$nonce,$body);
check(S::json(S::receive($body,$sig,$nonce,$secret,time()))===$body,'signed response accepted');
rejects(fn()=>S::receive($body.' ',$sig,$nonce,$secret,time()),'modified response rejected');
rejects(fn()=>S::receive($body,$sig,str_repeat('b',32),$secret,time()),'response cannot be replayed to another nonce');
foreach(['company_id'=>'foreign','complete'=>false,'source'=>'https://other.invalid','generated_at'=>time()-301] as $k=>$v) {
    $bad=$s;$bad[$k]=$v;rejects(fn()=>S::validate($bad,time()),'reject snapshot '.$k);
}
$bad=$s;$bad['datasets']['drivers'][]=$bad['datasets']['drivers'][0];rejects(fn()=>S::validate($bad,time()),'reject duplicate IDs');
$bad=$s;unset($bad['datasets']['repairs']);rejects(fn()=>S::validate($bad,time()),'reject partial dataset');
$bad=$s;$bad['datasets']['drivers'][0]['secret']='hidden';rejects(fn()=>S::validate($bad,time()),'reject extra fields');
$bad=$s;$bad['datasets']['drivers'][0]['id']='../../x';rejects(fn()=>S::validate($bad,time()),'reject malformed ID');
$path='/tmp/mapa-source-state.json';$receipt=S::save($path,$s,time());
check($receipt['changed_records']===3,'first sync records additions without native writes');
$prior=file_get_contents($path);rejects(fn()=>S::save($path,$s,time()),'out-of-order receipt rejected');check(file_get_contents($path)===$prior,'failed sync preserves previous state');
$root->exec("UPDATE amb_drivers SET phc_id='PHC-B' WHERE id=1");
$next=S::export($db);$next['generated_at']=$s['generated_at']+.01;
$receipt=S::save($path,$next,time());$state=json_decode(file_get_contents($path),true);
check($receipt['changed_records']===1 && $state['changes'][0]['fields']===['phc_id'],'source transfer recorded as review change');
check($root->query('SELECT phc_id FROM ambulances WHERE id=1')->fetchColumn()==='PHC-A','driver transfer does not move ambulance');
$root->exec('DELETE FROM amb_drivers WHERE id=2');$last=S::export($db);$last['generated_at']=$next['generated_at']+.01;
S::save($path,$last,time());$state=json_decode(file_get_contents($path),true);
check($state['changes'][0]['kind']==='removed_at_source','source deletion recorded without deleting native account');
$attendance=$s;$attendance['source_date']='2026-10-05';$rows=S::attendance($attendance,'2026-10');
check($rows[0]['present']===2 && $rows[0]['absent']===2,'attendance matches PHC session rule including off-road');
check(explode(' ',$rows[0]['daily_marks'])[0]==='-' && explode(' ',$rows[0]['daily_marks'])[5]==='-','before joining and future days excluded');
check($rows[1]['present']===0 && $rows[1]['absent']===5,'other PHC is not marked present');
rejects(fn()=>S::attendance($attendance,'2026-13'),'invalid attendance month rejected');
// Exercise actual HTTP entry point with no original application bootstrap.
$web='/tmp/source-public';mkdir($web);copy(__DIR__.'/source-export.php',$web.'/mapa-source-export.php');copy(__DIR__.'/SourceSync.php',$web.'/SourceSync.php');
$config='/tmp/source-private.json';file_put_contents($config,S::json(['enabled'=>true,'source_timezone'=>'Asia/Kolkata','dsn'=>"mysql:host=$host;dbname=mapa_source_qa;charset=utf8mb4",'username'=>'mirror_reader','password'=>'DEMO-read-only','secret'=>$secret]));
putenv('MAPA_SOURCE_EXPORT_CONFIG='.$config);
$proc=proc_open(['php','-S','127.0.0.1:18791','-t',$web],[['pipe','r'],['file','/tmp/source-http.log','a'],['file','/tmp/source-http.log','a']],$pipes);
try {
    for($i=0;$i<30;$i++){ $sock=@fsockopen('127.0.0.1',18791);if($sock){fclose($sock);break;}usleep(100000);}
    $request=function($headers=[]) {
        $h=curl_init('http://127.0.0.1:18791/mapa-source-export.php');$sig='';
        curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_HTTPHEADER=>$headers,CURLOPT_HEADERFUNCTION=>static function($h,$line)use(&$sig){if(stripos($line,'X-Mapa-Signature:')===0)$sig=trim(substr($line,17));return strlen($line);}]);
        $b=curl_exec($h);$status=curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);return [$status,$b,$sig];
    };
    check($request()[0]===401,'HTTP anonymous export denied');
    $nonce=bin2hex(random_bytes(16));$ts=(string)time();$headers=['X-Mapa-Timestamp: '.$ts,'X-Mapa-Nonce: '.$nonce,'X-Mapa-Signature: '.S::signature($secret,$ts,$nonce)];
    $unchanged=$digest();[$status,$body,$sig]=$request($headers);
    check($status===200,'HTTP authorized export succeeds');
    check(S::receive($body,$sig,$nonce,$secret,time())['complete'],'HTTP response verifies end-to-end');
    check(json_decode($body,true)['source_date']===(new DateTimeImmutable('now',new DateTimeZone('Asia/Kolkata')))->format('Y-m-d'),'attendance date uses explicitly configured source PHP timezone');
    check($digest()===$unchanged,'HTTP export preserves source records');
    check($request($headers)[0]===429,'HTTP frequent reads are throttled');
} finally {proc_terminate($proc);proc_close($proc);}
check((int)$root->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()===8,'no source migrations or new application tables');
// The receiver transport is replaced only inside this isolated test. No production requests.
$receiver=new class($s,$secret) extends \MapaSource\SourceReceiver {
    public int $calls=0;public string $mode='valid';public bool $signed=false;
    public function __construct(private array $fixture,private string $secret){}
    protected function fetch(string $url,array $headers): array {
        $this->calls++;$h=[];foreach($headers as $line){[$key,$value]=explode(': ',$line,2);$h[$key]=$value;}
        $this->signed=$url==='https://asrhealth.in/mapa-source-export.php' && $h['Accept']==='application/json'
            && hash_equals(S::signature($this->secret,$h['X-Mapa-Timestamp'],$h['X-Mapa-Nonce']),$h['X-Mapa-Signature']);
        if($this->mode==='offline')throw new RuntimeException('DEMO transport unavailable');
        $fixture=$this->fixture;$fixture['generated_at']=microtime(true);$body=S::json($fixture);
        $signature=S::responseSignature($this->secret,$h['X-Mapa-Nonce'],$body);
        if($this->mode==='tamper')$body.=' ';
        return ['status'=>$this->mode==='status'?503:200,'type'=>$this->mode==='html'?'text/html':'application/json; charset=utf-8','body'=>$body,'signature'=>$signature];
    }
};
$receiverDir='/tmp/mapa-receiver-qa';mkdir($receiverDir,0700);$receiverConfig='/tmp/mapa-receiver-qa.json';
$goodConfig=['enabled'=>true,'url'=>'https://asrhealth.in/mapa-source-export.php','secret'=>$secret,'state_directory'=>$receiverDir];
$configure=fn($c)=>file_put_contents($receiverConfig,S::json($c));
rejects(fn()=>$receiver->run('/tmp/DEMO-missing-receiver.json'),'receiver missing config rejected');
file_put_contents($receiverConfig,'{');rejects(fn()=>$receiver->run($receiverConfig),'receiver malformed config rejected');
foreach(['enabled'=>false,'url'=>'https://other.invalid/export','secret'=>'short','state_directory'=>'/tmp/DEMO-missing-state'] as $key=>$value){
    $configure(array_replace($goodConfig,[$key=>$value]));rejects(fn()=>$receiver->run($receiverConfig),'receiver rejects '.$key);
}
mkdir('/tmp/public');$configure(array_replace($goodConfig,['state_directory'=>'/tmp/public']));
rejects(fn()=>$receiver->run($receiverConfig),'receiver public state directory rejected');
$publicConfig='/tmp/public/receiver.json';file_put_contents($publicConfig,S::json($goodConfig));
rejects(fn()=>$receiver->run($publicConfig),'receiver public configuration file rejected');
$configure($goodConfig);rejects(fn()=>$receiver->run($receiverConfig,'/tmp'),'receiver expected-directory mismatch rejected');
check($receiver->calls===0,'invalid receiver config never reaches transport');
$receiver->run($receiverConfig,$receiverDir);
check($receiver->calls===1 && $receiver->signed,'receiver signs fixed-origin requests');
$receiverState=$receiverDir.'/state.json';$preserved=file_get_contents($receiverState);
check(S::json(json_decode($preserved,true)['snapshot']['datasets'])===S::json($s['datasets']),'receiver persists verified snapshot');
$busy=false;try{$receiver->run($receiverConfig);}catch(\MapaSource\SourceSyncBusy $e){$busy=true;}
check($busy && $receiver->calls===1,'receiver cooldown prevents repeated transport');
foreach(['tamper','html','status','offline'] as $mode){
    file_put_contents($receiverDir.'/sync.lock','0');$receiver->mode=$mode;
    rejects(fn()=>$receiver->run($receiverConfig),'receiver rejects '.$mode.' response');
    check(file_get_contents($receiverState)===$preserved,'receiver '.$mode.' keeps previous snapshot');
}
file_put_contents($receiverDir.'/sync.lock','0');$held=fopen($receiverDir.'/sync.lock','c+');flock($held,LOCK_EX);
$calls=$receiver->calls;$busy=false;try{$receiver->run($receiverConfig);}catch(\MapaSource\SourceSyncBusy $e){$busy=true;}
finally{flock($held,LOCK_UN);fclose($held);}
check($busy && $receiver->calls===$calls && file_get_contents($receiverState)===$preserved,'receiver in-flight lock prevents concurrent fetch and state changes');
require __DIR__.'/test-v2.php';

echo "SOURCE SYNC PASS $count checks\n";

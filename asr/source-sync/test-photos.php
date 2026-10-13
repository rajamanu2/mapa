<?php
declare(strict_types=1);
require_once __DIR__.'/SourceManualTransactions.php';
use MapaSource\SourceCoordinationPolicy as P;
$host=getenv('QA_DB_HOST');if(!str_starts_with($host?:'','mapa-source-qa-'))throw new RuntimeException('Isolated QA only.');
$db=new PDO("mysql:host=$host;dbname=mapa_workflow_qa;charset=utf8mb4",'root','DEMO-source-qa-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("ALTER TABLE amb_repairs MODIFY id INT AUTO_INCREMENT,ADD nature_of_repair TEXT,ADD repair_photos TEXT,ADD created_by VARCHAR(255)");
$base='/tmp/mapa-photo-qa';mkdir($base);mkdir($base.'/public');mkdir($base.'/public/ambulance');mkdir($base.'/public/uploads');
$policy=['enabled'=>true,'maintenance_mode'=>false,'schema_sha256'=>P::schemaReceipt($db),'form_hmac_secret'=>str_repeat('q',64)];file_put_contents($base.'/policy.json',json_encode($policy));
$s=file_get_contents(__DIR__.'/planning/original-writer-patch-v9/patched/off_road_ambulances.php');$a=strpos($s,'// Handle Actions');$b=strpos($s,"if (isset(\$_GET['msg']) && \$_GET['msg'] === 'repaired')",$a);$body=substr($s,$a,$b-$a);$body=str_replace("header('Location: '.\$mapaResult['redirect']); exit;","\$redirect = \$mapaResult['redirect'];",$body);
// Test-only process interruption after immutable files are flushed, before original INSERT.
$body=str_replace('$photos_json = !empty($uploaded_photos)',"if ((\$_GET['crash'] ?? '') === '1') { exit; }\n        \$photos_json = !empty(\$uploaded_photos)",$body);
$bootstrap=<<<'PHP'
<?php
require_once '/work/SourceManualTransactions.php';
$pdo=new PDO('mysql:host='.getenv('QA_DB_HOST').';dbname=mapa_workflow_qa;charset=utf8mb4','root','DEMO-source-qa-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET time_zone='+05:30'");
putenv('MAPA_SOURCE_COORDINATION_CONFIG=/tmp/mapa-photo-qa/policy.json');
$_SESSION=['user_id'=>'demo-photo-operator','mapa_form_csrf'=>str_repeat('a',64)];
function hasPermission($module,$verb){return ($_GET['deny']??'')!=='1';}
$error='';$success='';$redirect=null;
PHP;
file_put_contents($base.'/public/ambulance/photo.php',$bootstrap."\n".$body."\nif((\$_GET['lostresponse']??'')==='1')exit; header('Content-Type: application/json');echo json_encode(['error'=>\$error,'success'=>\$success,'redirect'=>\$redirect]);");
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j9H0AAAAASUVORK5CYII=');file_put_contents($base.'/demo.png',$png);file_put_contents($base.'/changed.png',$png.'DEMO');file_put_contents($base.'/bad.png','<?php echo "DEMO";');
$proc=proc_open(['php','-S','127.0.0.1:18794','-t',$base.'/public'],[['pipe','r'],['file',$base.'/http.log','a'],['file',$base.'/http.log','a']],$pipes);if(!is_resource($proc))throw new RuntimeException('QA server unavailable');
$n=0;function ok($yes,$label){global $n;if(!$yes)throw new RuntimeException($label);echo 'PASS '.(++$n).' '.$label."\n";}
function send(array $post,string $file='',string $query=''):array {global $base;$h=curl_init('http://127.0.0.1:18794/ambulance/photo.php'.$query);if($file!=='')$post['repair_photos[0]']=new CURLFile($base.'/'.$file,'image/png',$file);curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8]);$body=curl_exec($h);$status=curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);return [$status,$body,json_decode($body?:'null',true)];}
$seq=1000;$form=function(array $p)use(&$seq){return $p+['mapa_form_csrf'=>str_repeat('a',64),'mapa_request_id'=>sprintf('%032x',++$seq)];};$files=fn()=>glob($base.'/public/uploads/ambulance_repairs/*')?:[];
try{
 usleep(250000);
 $init=$form(['action'=>'init_repair','ambulance_id'=>'3','daily_status_id'=>'','nature_of_repair'=>'DEMO repair','place_of_repair'=>'DEMO workshop','odo_reading'=>'103']);
 [$status,$raw,$r]=send($init,'demo.png');ok($status===200&&$r&&$r['error']===''&&str_starts_with($r['redirect']??'','off_road_ambulances.php?repair_id='),'multipart original repair init preserves redirect');$rid=substr($r['redirect'],strlen('off_road_ambulances.php?repair_id='));$saved=$db->query('SELECT repair_photos FROM amb_repairs WHERE id='.(int)$rid)->fetchColumn();$names=json_decode($saved,true);ok(count($names)===1&&count($files())===1&&hash_file('sha256',$files()[0])===hash('sha256',$png),'validated photo saved with original public URL folder');
 send($init,'demo.png');ok(count($files())===1&&(int)$db->query("SELECT COUNT(*) FROM amb_repairs WHERE nature_of_repair='DEMO repair'")->fetchColumn()===1,'same multipart retry creates neither duplicate repair nor file');
 [,, $r]=send($init,'changed.png');ok($r['error']!==''&&count($files())===1,'content change bound to request HMAC conflicts');
 $upload=$form(['action'=>'upload_photos','repair_id'=>$rid]);[,, $r]=send($upload,'changed.png');$current=json_decode($db->query('SELECT repair_photos FROM amb_repairs WHERE id='.(int)$rid)->fetchColumn(),true);ok($r['error']===''&&count($current)===2&&$current[0]===$names[0],'upload appends and preserves existing photos');send($upload,'changed.png');ok(count($files())===2,'append retry never appends same photo twice');
 $bad=$form(['action'=>'upload_photos','repair_id'=>$rid]);[,, $r]=send($bad,'bad.png');ok($r['error']!==''&&count($files())===2,'disguised executable is rejected before moving files');
 $bad['mapa_form_csrf']='bad';[,, $r]=send($bad,'demo.png');ok($r['error']!==''&&count($files())===2,'invalid CSRF cannot store files');
 [,, $r]=send($form(['action'=>'upload_photos','repair_id'=>$rid]),'demo.png','?deny=1');ok(count($files())===2,'existing form edit permission still blocks multipart writes');
 $db->exec("CREATE TRIGGER demo_photo_fail BEFORE UPDATE ON mapa_manual_commands FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DEMO receipt failure'");$fail=$form(['action'=>'upload_photos','repair_id'=>$rid]);[,, $r]=send($fail,'demo.png');ok($r['error']!==''&&count($files())===2,'confirmed rollback removes only new transaction-owned photos');ok($db->query('SELECT repair_photos FROM amb_repairs WHERE id='.(int)$rid)->fetchColumn()===json_encode($current),'rollback keeps prior photo references intact');$db->exec('DROP TRIGGER demo_photo_fail');
 $crash=$form(['action'=>'init_repair','ambulance_id'=>'3','daily_status_id'=>'','nature_of_repair'=>'DEMO interrupted','place_of_repair'=>'DEMO','odo_reading'=>'103']);send($crash,'demo.png','?crash=1');ok(count($files())===3&&(int)$db->query("SELECT COUNT(*) FROM amb_repairs WHERE nature_of_repair='DEMO interrupted'")->fetchColumn()===0,'pre-commit interruption leaves immutable orphan but no database row');
 [,, $r]=send($crash,'demo.png');ok($r['error']===''&&count($files())===3&&(int)$db->query("SELECT COUNT(*) FROM amb_repairs WHERE nature_of_repair='DEMO interrupted'")->fetchColumn()===1,'retry reuses verified interrupted file and commits once');
 $lost=$form(['action'=>'upload_photos','repair_id'=>$rid]);send($lost,'demo.png','?lostresponse=1');[,, $r]=send($lost,'demo.png');ok($r['error']===''&&count($files())===4,'lost response after commit replays without deleting committed photo');
 $empty=$form(['action'=>'init_repair','ambulance_id'=>'3','daily_status_id'=>'','nature_of_repair'=>'DEMO no photos','place_of_repair'=>'DEMO','odo_reading'=>'103']);[,, $r]=send($empty);ok($r['error']===''&&count($files())===4,'repair initialization still allows no photos');
 $audit=json_encode($db->query('SELECT * FROM mapa_manual_commands')->fetchAll(PDO::FETCH_ASSOC));ok(!str_contains($audit,'demo.png')&&!str_contains($audit,hash('sha256',$png)),'receipts expose neither filenames nor plain content digests');
 echo "SOURCE REPAIR PHOTOS PASS $n checks\n";
}finally{proc_terminate($proc);proc_close($proc);}

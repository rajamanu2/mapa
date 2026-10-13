<?php
declare(strict_types=1);
require_once __DIR__.'/SourceDispatchCommands.php';
require_once __DIR__.'/SourceCoordinationPolicy.php';
use MapaSource\SourceDispatchCommands as D;
use MapaSource\SourceSync;
use MapaSource\CommandRejected;
ini_set('display_errors','0');header('Content-Type: application/json');header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
$authenticated=false;$command=null;$status=503;
try{
    $path=getenv('MAPA_SOURCE_DISPATCH_CONFIG');$actual=is_string($path)?realpath($path):false;$web=realpath($_SERVER['DOCUMENT_ROOT']??'');
    if(!$actual||!is_file($actual)||filesize($actual)>65536||preg_match('~/(public|public_html|public-console)(/|$)~i',str_replace('\\','/',$actual))||($web&&($actual===$web||str_starts_with($actual,$web.DIRECTORY_SEPARATOR))))throw new RuntimeException('Private dispatch configuration required.');
    $config=json_decode(file_get_contents($actual),true,16,JSON_THROW_ON_ERROR);
    if(($config['enabled']??false)!==true||($config['original_writers_coordinated']??false)!==true)throw new RuntimeException('Dispatch coordination not commissioned.');
    $body=file_get_contents('php://input',false,null,0,D::MAX_BYTES+1);
    if(!is_string($body)||!D::authorized($_SERVER,(string)($config['secret']??''),$body,time()))throw new CommandRejected(401,'Unauthorized.');$authenticated=true;
    $command=json_decode($body,true,16,JSON_THROW_ON_ERROR);if(!is_array($command))throw new CommandRejected(422,'Dispatch command object required.');D::validate($command);
    if(!str_starts_with($config['dsn']??'','mysql:'))throw new RuntimeException('MySQL required.');
    $db=new PDO($config['dsn'],$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5]);$db->exec('SET SESSION innodb_lock_wait_timeout=5');
    if(!is_string($config['source_timezone']??null)||$config['source_timezone']==='')throw new RuntimeException('Original source timezone required.');
    $zone=new DateTimeZone($config['source_timezone']);$offset=(new DateTimeImmutable('now',$zone))->format('P');$db->exec('SET SESSION time_zone='.$db->quote($offset));
    $result=\MapaSource\SourceCoordinationPolicy::operation($db,'dispatch',fn()=>D::execute($db,$command,$config['actors']??[]));$status=200;
}catch(CommandRejected $e){$status=$e->status;$result=['error'=>$e->getMessage(),'outcome'=>'rejected'];}
catch(JsonException $e){$status=$authenticated?422:503;$result=['error'=>$authenticated?'Invalid dispatch JSON.':'Source dispatch unavailable.','outcome'=>$authenticated?'rejected':'unknown'];}
catch(Throwable $e){$status=503;$result=['error'=>'Source dispatch unavailable.','outcome'=>'unknown'];error_log('MAPA source dispatch failed: '.get_class($e));}
if($authenticated&&isset($result['error'])){$identity=[];foreach(D::KEYS as $key)$identity[$key]=is_array($command)?($command[$key]??null):null;$result=$identity+$result;}
http_response_code($status);$response=SourceSync::json($result);if($authenticated)header('X-Mapa-Signature: '.D::responseSignature($config['secret'],$_SERVER['HTTP_X_MAPA_NONCE'],$status,$response));echo $response;

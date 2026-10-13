<?php
declare(strict_types=1);
require_once __DIR__.'/SourceMasterCommands.php';
require_once __DIR__.'/SourceCoordinationPolicy.php';
use MapaSource\SourceMasterCommands as W;
use MapaSource\SourceSync;
use MapaSource\CommandRejected;
ini_set('display_errors','0');header('Content-Type: application/json');header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
$authenticated=false;
try{
    $path=getenv('MAPA_SOURCE_MASTER_CONFIG')?:dirname(__DIR__,2).'/private/mapa-source-master.json';$actual=realpath($path);$root=realpath($_SERVER['DOCUMENT_ROOT']??'');
    if(!$actual||filesize($actual)>65536||($root&&($actual===$root||str_starts_with($actual,$root.DIRECTORY_SEPARATOR))))throw new RuntimeException('Private workflow configuration required.');
    $config=json_decode(file_get_contents($actual),true,16,JSON_THROW_ON_ERROR);
    // Original forms/CAD do not currently share our lock/reservation boundary. Fail closed until coordinated.
    if(($config['enabled']??false)!==true||($config['original_writers_coordinated']??false)!==true)throw new RuntimeException('Workflow not commissioned.');
    $body=file_get_contents('php://input',false,null,0,W::MAX_BYTES+1);
    if(!is_string($body)||!W::authorized($_SERVER,(string)($config['secret']??''),$body,time()))throw new CommandRejected(401,'Unauthorized.');$authenticated=true;
    $command=json_decode($body,true,16,JSON_THROW_ON_ERROR);if(!is_array($command))throw new CommandRejected(422,'Workflow command object required.');W::validate($command);
    if(!str_starts_with($config['dsn']??'','mysql:'))throw new RuntimeException('MySQL required.');
    $db=new PDO($config['dsn'],$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5]);$db->exec('SET SESSION innodb_lock_wait_timeout=5');
    $result=\MapaSource\SourceCoordinationPolicy::operation($db,'command',fn()=>W::execute($db,$command,$config['actors']??[],(string)($config['ledger_secret']??''),(string)($config['source_timezone']??'Asia/Kolkata')));
}catch(CommandRejected $e){http_response_code($e->status);$result=['error'=>$e->getMessage(),'outcome'=>'rejected'];}
catch(JsonException $e){http_response_code($authenticated?422:503);$result=['error'=>$authenticated?'Invalid workflow JSON.':'Source workflow unavailable.','outcome'=>$authenticated?'rejected':'unknown'];}
catch(Throwable $e){http_response_code(503);$result=['error'=>'Source workflow unavailable.','outcome'=>'unknown'];error_log('MAPA source workflow failed: '.get_class($e));}
if($authenticated&&isset($result['error'])){$identity=['contract'=>W::CONTRACT,'company_id'=>SourceSync::COMPANY];foreach(['request_id','action','record_id'] as $f)$identity[$f]=is_string($command[$f]??null)?$command[$f]:null;$result=$identity+$result;}
$response=SourceSync::json($result);if($authenticated)header('X-Mapa-Signature: '.W::responseSignature($config['secret'],$_SERVER['HTTP_X_MAPA_NONCE'],$response));echo $response;

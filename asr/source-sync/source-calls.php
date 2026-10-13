<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/mapa-source-private/SourceCallFeed.php';
require_once dirname(__DIR__).'/mapa-source-private/SourceSync.php';
use MapaSource\SourceCallFeed;
use MapaSource\SourceSync;
ini_set('display_errors','0');header('Content-Type: application/json');header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
try{
    $path=getenv('MAPA_SOURCE_EXPORT_CONFIG')?:dirname(__DIR__).'/mapa-source-private/config.json';
    $actual=realpath($path);$root=realpath($_SERVER['DOCUMENT_ROOT']??'');
    if(!$actual||($root&&str_starts_with($actual,$root.DIRECTORY_SEPARATOR)))throw new RuntimeException('Private configuration required.');
    $c=json_decode(file_get_contents($actual),true,16,JSON_THROW_ON_ERROR);
    if(($c['enabled']??false)!==true||($c['caller_details_enabled']??false)!==true)throw new RuntimeException('Caller feed disabled.');
    if(!SourceCallFeed::authorized($_SERVER,(string)($c['secret']??''),time())){http_response_code(401);echo '{"error":"Unauthorized."}';exit;}
    if(!str_starts_with($c['dsn']??'','mysql:'))throw new RuntimeException('Unsupported database.');
    $db=new PDO($c['dsn'],$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5]);
    $body=SourceSync::json(SourceCallFeed::export($db,SourceSync::COMPANY));
    header('X-Mapa-Signature: '.SourceCallFeed::responseSignature($c['secret'],$_SERVER['HTTP_X_MAPA_NONCE'],$body));echo $body;
}catch(Throwable $e){http_response_code(503);echo '{"error":"Caller feed unavailable."}';error_log('MAPA caller feed failed: '.get_class($e));}

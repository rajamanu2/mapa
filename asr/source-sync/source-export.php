<?php
declare(strict_types=1);
require_once __DIR__.'/SourceSync.php';
use MapaSource\SourceSync;
ini_set('display_errors','0');
header('Content-Type: application/json');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
try {
    // Explicit independent config outside public_html; no original config.php/self-healing includes.
    $path=getenv('MAPA_SOURCE_EXPORT_CONFIG') ?: dirname(__DIR__,2).'/private/mapa-source-export.json';
    $root=realpath($_SERVER['DOCUMENT_ROOT']??''); $actual=realpath($path);
    if(!$actual || ($root && str_starts_with($actual,$root.DIRECTORY_SEPARATOR))) throw new RuntimeException('Private configuration required.');
    $c=json_decode(file_get_contents($actual),true,16,JSON_THROW_ON_ERROR);
    if(($c['enabled']??false)!==true) throw new RuntimeException('Disabled.');
    if(!SourceSync::authorized($_SERVER,(string)($c['secret']??''),time())) {
        http_response_code(401); echo '{"error":"Unauthorized."}'; exit;
    }
    if(!str_starts_with($c['dsn']??'','mysql:')) throw new RuntimeException('Unsupported source database.');
    // Prevent overlapping full-source reads; ordinary site requests never take this lock.
    $lock=fopen(dirname($actual).'/mapa-source-export.lock','c+');
    if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { http_response_code(429); echo '{"error":"Retry later."}'; exit; }
    $last=(int)stream_get_contents($lock);
    if(time()-$last<10) { http_response_code(429); echo '{"error":"Retry later."}'; exit; }
    rewind($lock); ftruncate($lock,0); fwrite($lock,(string)time()); fflush($lock);
    $db=new PDO($c['dsn'],$c['username'],$c['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_TIMEOUT=>5,
    ]);
    if(!is_string($c['source_timezone']??null) || $c['source_timezone']==='') throw new RuntimeException('Original PHP timezone must be configured.');
    $body=SourceSync::json(SourceSync::export($db,$c['source_timezone'],(string)($c['contract']??SourceSync::CONTRACT)));
    header('X-Mapa-Signature: '.SourceSync::responseSignature($c['secret'],$_SERVER['HTTP_X_MAPA_NONCE'],$body));
    echo $body;
} catch(Throwable $e) {
    http_response_code(503); echo '{"error":"Source sync unavailable."}';
    error_log('MAPA source export failed: '.get_class($e));
} finally { if(isset($lock)&&is_resource($lock)) { flock($lock,LOCK_UN); fclose($lock); } }

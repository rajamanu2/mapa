<?php
declare(strict_types=1);
namespace MapaSource;
require_once __DIR__.'/SourceSync.php';
final class SourceSyncBusy extends \RuntimeException {}
/** Private receiver shared by scheduled CLI and authorized manual requests. */
class SourceReceiver
{
    public function run(string $configPath,?string $expectedDirectory=null): array
    {
        if(!is_file($configPath)||filesize($configPath)>16384 || preg_match('~/(public|public_html|public-console)(/|$)~i',str_replace('\\','/',realpath($configPath))))throw new \RuntimeException('Receiver is not configured.');
        $c=json_decode(file_get_contents($configPath),true,16,JSON_THROW_ON_ERROR);
        if(($c['enabled']??false)!==true || ($c['url']??'')!=='https://asrhealth.in/mapa-source-export.php'
            || !is_string($c['secret']??null) || strlen($c['secret'])<32)throw new \RuntimeException('Invalid receiver configuration.');
        $dir=realpath($c['state_directory']??'');
        if(!$dir || !is_writable($dir) || preg_match('~/(public|public_html|public-console)(/|$)~i',str_replace('\\','/',$dir))
            || ($expectedDirectory!==null && $dir!==realpath($expectedDirectory)))throw new \RuntimeException('Private state directory required.');
        $lock=fopen($dir.'/sync.lock','c+');if(!$lock)throw new \RuntimeException('Receiver lock unavailable.');
        try {
            if(!flock($lock,LOCK_EX|LOCK_NB))throw new SourceSyncBusy('Sync already running.');
            if(time()-(int)stream_get_contents($lock)<30)throw new SourceSyncBusy('Wait 30 seconds.');
            rewind($lock);ftruncate($lock,0);fwrite($lock,(string)time());fflush($lock);chmod($dir.'/sync.lock',0600);
            $timestamp=(string)time();$nonce=bin2hex(random_bytes(16));
            $r=$this->fetch($c['url'],['Accept: application/json','X-Mapa-Timestamp: '.$timestamp,'X-Mapa-Nonce: '.$nonce,
                'X-Mapa-Signature: '.SourceSync::signature($c['secret'],$timestamp,$nonce)]);
            if($r['status']!==200 || !preg_match('~^application/json(?:;|$)~i',$r['type']))throw new \RuntimeException('Source request failed.');
            return SourceSync::save($dir.'/state.json',SourceSync::receive($r['body'],$r['signature'],$nonce,$c['secret'],time()),time());
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    protected function fetch(string $url,array $headers): array
    {
        $body='';$signature='';$type='';$ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'MAPA-ASR-Source-Sync/1',
            CURLOPT_HEADERFUNCTION=>static function($h,$line)use(&$signature,&$type){
                if(stripos($line,'X-Mapa-Signature:')===0)$signature=trim(substr($line,17));
                if(stripos($line,'Content-Type:')===0)$type=trim(substr($line,13));return strlen($line);},
            CURLOPT_WRITEFUNCTION=>static function($h,$chunk)use(&$body){
                if(strlen($body)+strlen($chunk)>SourceSync::MAX_BYTES)return 0;$body.=$chunk;return strlen($chunk);}]);
        try {$ok=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);}finally {curl_close($ch);}
        if($ok===false)throw new \RuntimeException('Source request unavailable.');
        return ['status'=>$status,'body'=>$body,'signature'=>$signature,'type'=>$type];
    }
}

<?php
namespace App\MapaMobile;
require_once __DIR__.'/SourceCallFeed.php';
require_once __DIR__.'/SourceReceiver.php';
use MapaSource\SourceCallFeed;
use MapaSource\SourceReceiver;

/** Read-only signed source calls. No native dispatch or caller messaging here. */
class SourceCallStore extends SourceReceiver
{
    public function read(string $company):array {
        if(!config('mapa_mobile.source_sync_enabled'))MobileService::fail('ASR sync is disabled.',503);
        $dir=storage_path('app/mapa-source-sync');$config=$dir.'/receiver.json';
        if(!is_file($config)||filesize($config)>16384)MobileService::fail('Source receiver unavailable.',503);
        $c=json_decode(file_get_contents($config),true,16,JSON_THROW_ON_ERROR);
        if(($c['enabled']??false)!==true||($c['caller_details_enabled']??false)!==true||($c['url']??null)!=='https://asrhealth.in/mapa-source-export.php'
            ||!is_string($c['secret']??null)||strlen($c['secret'])<32)MobileService::fail('ASR caller access has not been activated.',503);
        $path=$dir.'/calls.json';$cached=null;
        if(is_file($path)&&filesize($path)<=SourceCallFeed::MAX_BYTES){$cached=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);SourceCallFeed::validate($cached,$company,(int)($cached['generated_at']??0));if($cached['generated_at']>time()+30)throw new \RuntimeException('Invalid caller cache time.');}
        if($cached&&time()-$cached['generated_at']<30)return $cached+['stale'=>false];
        $lock=fopen($dir.'/calls.lock','c+');if(!$lock)MobileService::fail('Caller cache unavailable.',503);chmod($dir.'/calls.lock',0600);
        try{
            if(!flock($lock,LOCK_EX|LOCK_NB)){if($cached)return $cached+['stale'=>time()-$cached['generated_at']>120];MobileService::fail('Caller refresh running.',503);}
            $ts=(string)time();$nonce=bin2hex(random_bytes(16));
            $r=$this->fetch('https://asrhealth.in/mapa-source-calls.php',['Accept: application/json','X-Mapa-Timestamp: '.$ts,'X-Mapa-Nonce: '.$nonce,'X-Mapa-Signature: '.SourceCallFeed::signature($c['secret'],$ts,$nonce)]);
            if($r['status']!==200||!preg_match('~^application/json(?:;|$)~i',$r['type'])||strlen($r['body'])>SourceCallFeed::MAX_BYTES||!hash_equals(SourceCallFeed::responseSignature($c['secret'],$nonce,$r['body']),$r['signature']))throw new \RuntimeException('Invalid caller response.');
            $feed=json_decode($r['body'],true,32,JSON_THROW_ON_ERROR);SourceCallFeed::validate($feed,$company,time());
            $tmp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';$handle=fopen($tmp,'x');if(!$handle)throw new \RuntimeException('Caller cache unavailable.');chmod($tmp,0600);
            try{if(fwrite($handle,$r['body'])!==strlen($r['body']))throw new \RuntimeException('Incomplete caller cache.');}finally{fclose($handle);}if(!rename($tmp,$path))throw new \RuntimeException('Caller cache unavailable.');
            return $feed+['stale'=>false];
        }catch(\Throwable $e){if($cached)return $cached+['stale'=>true];MobileService::fail('ASR caller feed unavailable.',503);}
        finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function assertCall(object $call):void {
        if(($call->provider??'')!=='asrhealth')return;
        $feed=(new self)->read($call->company_uuid);if($feed['stale']||$feed['truncated'])MobileService::fail('A fresh complete ASR call feed is required.',503);
        $draft=json_decode($call->draft?:'{}',true);
        foreach($feed['calls'] as $row)if((string)$row['id']===(string)$call->provider_call_id){
            try{$sourcePhone=CallerPhone::normalize($row['caller_phone']);$storedPhone=CallerPhone::normalize($draft['callback_number']??'');}catch(\InvalidArgumentException $e){MobileService::fail('Review the ASR caller number.',409);}
            if($sourcePhone===''||!hash_equals($sourcePhone,$storedPhone))MobileService::fail('ASR caller number changed. Open the current ASR call and create a new link.',409);return;
        }MobileService::fail('ASR incident has ended or is unavailable.',410);
    }
    public function summary(string $company):array {
        $feed=$this->index($company);$feed['calls']=array_map(fn($row)=>array_intersect_key($row,array_flip(['id','incident_number','caller_name','pickup_address','incident_type','priority','status','created_at','updated_at','source_revision'])),$feed['calls']);return $feed;
    }
    public function index(string $company):array {
        $feed=$this->read($company);
        $feed['calls']=array_map(fn($row)=>$row+['source_revision'=>SourceCallFeed::revision($row)],$feed['calls']);return $feed;
    }
    public function current(string $company,string $id,string $revision):array {
        $feed=$this->read($company);if($feed['stale']||$feed['truncated'])MobileService::fail('Fetch a complete fresh caller feed before this action.',409);
        foreach($feed['calls'] as $row)if((string)$row['id']===$id){if(!hash_equals(SourceCallFeed::revision($row),$revision))MobileService::fail('ASR caller details changed. Review again.',409);return $row;}
        MobileService::fail('ASR incident ended or is unavailable.',410);
    }
}

<?php
declare(strict_types=1);
namespace App\MapaMobile;
require_once __DIR__.'/SourceCommands.php';
use MapaSource\SourceCommands;
use MapaSource\SourceSync;

final class SourceCommandUnavailable extends \RuntimeException {}
final class SourceCommandUncertain extends \RuntimeException {}
final class SourceCommandRefused extends \RuntimeException {
    public function __construct(public readonly int $status,string $message){parent::__construct($message);}
}

/** Private server-to-server adapter. A lost response is never treated as a failed write. */
class SourceCommandClient
{
    public const URL='https://asrhealth.in/mapa-source-command.php';
    public function __construct(private readonly string $configPath){}
    protected function protocol():string{return SourceCommands::class;}
    public function configured():bool {try{$this->configuration();return true;}catch(\Throwable $e){return false;}}
    public function allowedActions(string $actor):array
    {
        $c=$this->configuration();$protocol=$this->protocol();
        if(!array_key_exists('actors',$c))return $protocol::ACTIONS;
        $actions=$c['actors'][$actor]??[];
        if(!is_array($actions))return [];
        return array_values(array_intersect($protocol::ACTIONS,$actions));
    }
    private function configuration():array
    {
        $path=realpath($this->configPath);
        if(!$path || preg_match('~/(public|public_html|public-console)(/|$)~i',str_replace('\\','/',$path))
            || !is_file($path) || filesize($path)>16384) throw new SourceCommandUnavailable('Source editing is not configured.');
        try{$c=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);}catch(\Throwable $e){throw new SourceCommandUnavailable('Source editing is not configured.');}
        if(($c['enabled']??false)!==true || ($c['url']??null)!==static::URL || !is_string($c['secret']??null)
            || strlen($c['secret'])<32 || strlen($c['secret'])>1024)throw new SourceCommandUnavailable('Source editing is not configured.');
        return $c;
    }
    public function send(array $command):array
    {
        $c=$this->configuration();$protocol=$this->protocol();$protocol::validate($command);
        if(!in_array($command['action'],$this->allowedActions($command['actor_id']),true))
            throw new SourceCommandRefused(403,'ASR Health has not granted this edit.');
        $body=SourceSync::json($command);
        if(strlen($body)>$protocol::MAX_BYTES)throw new SourceCommandUnavailable('Source command is too large.');
        $timestamp=(string)time();$nonce=bin2hex(random_bytes(16));
        try {
            $r=$this->transmit(static::URL,['Accept: application/json','Content-Type: application/json',
                'X-Mapa-Timestamp: '.$timestamp,'X-Mapa-Nonce: '.$nonce,
                'X-Mapa-Signature: '.$protocol::signature($c['secret'],$timestamp,$nonce,$body)],$body);
            if(!is_int($r['status']??null)||!is_string($r['body']??null)||strlen($r['body'])>$protocol::MAX_BYTES
                || !preg_match('~^application/json(?:;|$)~i',$r['type']??'')
                || !is_string($r['signature']??null) || !preg_match('/^[a-f0-9]{64}$/D',$r['signature'])
                || !hash_equals($protocol::responseSignature($c['secret'],$nonce,$r['body']),$r['signature']))
                throw new SourceCommandUncertain('The source response could not be verified.');
            $receipt=json_decode($r['body'],true,16,JSON_THROW_ON_ERROR);
            if(!is_array($receipt))throw new SourceCommandUncertain('Invalid source response.');
            foreach(['contract','company_id','request_id','action','record_id'] as $key)
                if(($receipt[$key]??null)!==$command[$key])throw new SourceCommandUncertain('Source response scope mismatch.');
            if($r['status']===200) {
                $this->validateReceipt($receipt,$command);
                return $receipt;
            }
            if(in_array($r['status'],[403,404,409,422,429],true) && ($receipt['outcome']??null)==='rejected') {
                // Only known fixed messages leave the adapter; no remote errors or private details are echoed.
                $message=match($r['status']){403=>'ASR Health has not granted this edit.',404=>'The source record no longer exists.',
                    409=>'The source record changed or this request ID was already used. Fetch and review before saving.',
                    422=>'ASR Health rejected the edit. Check the selected record and destination.',default=>'ASR Health is busy. Retry this same request later.'};
                throw new SourceCommandRefused($r['status'],$message);
            }
            throw new SourceCommandUncertain('The source outcome could not be confirmed.');
        } catch(SourceCommandRefused|SourceCommandUncertain $e){throw $e;}
        catch(\Throwable $e){throw new SourceCommandUncertain('The source outcome could not be confirmed.');}
    }
    protected function validateReceipt(array $receipt,array $command):void {
        $keys=array_keys($receipt);sort($keys);$expected=['contract','company_id','request_id','action','record_id','revision','applied_at','refresh_required'];sort($expected);
        if($keys!==$expected || !is_string($receipt['revision']) || !preg_match('/^[a-f0-9]{64}$/D',$receipt['revision'])
            || !is_string($receipt['applied_at']) || !preg_match('/^20\d{2}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D',$receipt['applied_at'])
            || $receipt['refresh_required']!==true)throw new SourceCommandUncertain('Invalid source receipt.');
    }
    protected function transmit(string $url,array $headers,string $body):array
    {
        $protocol=$this->protocol();$reply='';$signature='';$type='';$ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_USERAGENT=>'MAPA-ASR-Source-Command/1',
            CURLOPT_HEADERFUNCTION=>static function($h,$line)use(&$signature,&$type){
                if(stripos($line,'X-Mapa-Signature:')===0)$signature=trim(substr($line,17));
                if(stripos($line,'Content-Type:')===0)$type=trim(substr($line,13));return strlen($line);},
            CURLOPT_WRITEFUNCTION=>static function($h,$chunk)use(&$reply,$protocol){if(strlen($reply)+strlen($chunk)>$protocol::MAX_BYTES)return 0;$reply.=$chunk;return strlen($chunk);}]);
        try{$ok=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);}finally{curl_close($ch);}
        if($ok===false)throw new SourceCommandUncertain('The source outcome could not be confirmed.');
        return ['status'=>$status,'body'=>$reply,'signature'=>$signature,'type'=>$type];
    }
}

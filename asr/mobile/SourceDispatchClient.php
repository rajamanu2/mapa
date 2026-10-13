<?php
declare(strict_types=1);
namespace App\MapaMobile;
if(!class_exists(\MapaSource\SourceDispatchCommands::class,false))require_once __DIR__.'/SourceDispatchCommands.php';
use MapaSource\SourceDispatchCommands as D;
use MapaSource\SourceSync;

final class SourceDispatchUnavailable extends \RuntimeException {}
final class SourceDispatchUncertain extends \RuntimeException {}
final class SourceDispatchRefused extends \RuntimeException {
    public function __construct(public readonly int $status,string $message){parent::__construct($message);}
}

/** No retry, release or fail-open side effect: caller retains the same journey on ambiguity. */
class SourceDispatchClient {
    public const URL='https://asrhealth.in/mapa-source-dispatch-command.php';
    public function __construct(private readonly string $configPath){}
    public function configured():bool {try{$this->configuration();return true;}catch(\Throwable $e){return false;}}
    private function configuration():array {
        $path=realpath($this->configPath);
        if(!$path||!is_file($path)||filesize($path)>16384||preg_match('~/(public|public_html|public-console)(/|$)~i',str_replace('\\','/',$path)))throw new SourceDispatchUnavailable('Source dispatch is not configured.');
        try{$c=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);}catch(\Throwable $e){throw new SourceDispatchUnavailable('Source dispatch is not configured.');}
        if(($c['enabled']??false)!==true||($c['original_writers_coordinated']??false)!==true||($c['company_id']??null)!==SourceSync::COMPANY||($c['url']??null)!==self::URL||!is_string($c['secret']??null)||strlen($c['secret'])<32||strlen($c['secret'])>1024)throw new SourceDispatchUnavailable('Source dispatch is not configured.');
        return $c;
    }
    public function send(array $command):array {
        $c=$this->configuration();D::validate($command);$body=SourceSync::json($command);
        if(strlen($body)>D::MAX_BYTES)throw new SourceDispatchUnavailable('Source dispatch request is too large.');
        $ts=(string)time();$nonce=bin2hex(random_bytes(16));
        try{
            $r=$this->transmit(self::URL,['Accept: application/json','Content-Type: application/json','X-Mapa-Timestamp: '.$ts,'X-Mapa-Nonce: '.$nonce,'X-Mapa-Signature: '.D::signature($c['secret'],$ts,$nonce,$body)],$body);
            if(!is_int($r['status']??null)||!is_string($r['body']??null)||strlen($r['body'])>D::MAX_BYTES||!is_string($r['type']??null)||!preg_match('~^application/json(?:;|$)~i',$r['type'])||!is_string($r['signature']??null)||!preg_match('/^[a-f0-9]{64}$/D',$r['signature'])||!hash_equals(D::responseSignature($c['secret'],$nonce,$r['status'],$r['body']),$r['signature']))throw new SourceDispatchUncertain('Source ownership response could not be verified.');
            $reply=json_decode($r['body'],true,16,JSON_THROW_ON_ERROR);if(!is_array($reply))throw new SourceDispatchUncertain('Invalid ownership response.');
            foreach(D::KEYS as $key)if(!array_key_exists($key,$reply)||$reply[$key]!==$command[$key])throw new SourceDispatchUncertain('Source ownership response scope mismatch.');
            if($r['status']===200){
                $v=$reply['reservation']??null;
                if(!D::exactKeys($reply,[...D::KEYS,'outcome','reservation'])||$reply['outcome']!=='confirmed'||!is_array($v)||!D::exactKeys($v,['status','version','created_at','terminal_at'])||!in_array($v['status'],D::STATES,true)||!is_int($v['version'])||$v['version']<1||!self::timestamp($v['created_at'])||($v['terminal_at']!==null&&!self::timestamp($v['terminal_at'])))throw new SourceDispatchUncertain('Invalid ownership receipt.');
                $terminal=in_array($v['status'],['completed','cancelled'],true);
                if($terminal!==($v['terminal_at']!==null)||($command['action']==='reserve'&&$terminal)||($command['action']==='transition'&&($v['status']!==$command['next_status']||$v['version']!==$command['expected_version']+1)))throw new SourceDispatchUncertain('Inconsistent ownership receipt.');
                return $reply;
            }
            if(D::exactKeys($reply,[...D::KEYS,'error','outcome'])&&is_string($reply['error'])&&strlen($reply['error'])<=500&&$reply['outcome']==='rejected'&&in_array($r['status'],[401,403,404,409,422,429],true))throw new SourceDispatchRefused($r['status'],match($r['status']){401,403=>'Source dispatch access was refused.',404=>'Source reservation was not found.',409=>'Source ownership conflicts with this request.',422=>'Source rejected the dispatch request.',default=>'Source dispatch is busy; retry the same request.'});
            throw new SourceDispatchUncertain('Source ownership outcome is unknown; retain the same journey identity.');
        }catch(SourceDispatchRefused|SourceDispatchUncertain $e){throw $e;}catch(\Throwable $e){throw new SourceDispatchUncertain('Source ownership outcome is unknown; retain the same journey identity.');}
    }
    private static function timestamp($value):bool {
        if(!is_string($value)||!preg_match('/^20\d{2}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D',$value))return false;
        $d=\DateTimeImmutable::createFromFormat(str_contains($value,'.')?'!Y-m-d H:i:s.u':'!Y-m-d H:i:s',$value,new \DateTimeZone('UTC'));$errors=\DateTimeImmutable::getLastErrors();return $d!==false&&($errors===false||($errors['warning_count']===0&&$errors['error_count']===0));
    }
    protected function transmit(string $url,array $headers,string $body):array {
        $reply='';$signature='';$type='';$ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'MAPA-ASR-Dispatch/1',
            CURLOPT_HEADERFUNCTION=>static function($h,$line)use(&$signature,&$type){if(stripos($line,'X-Mapa-Signature:')===0)$signature=trim(substr($line,17));if(stripos($line,'Content-Type:')===0)$type=trim(substr($line,13));return strlen($line);},
            CURLOPT_WRITEFUNCTION=>static function($h,$chunk)use(&$reply){if(strlen($reply)+strlen($chunk)>D::MAX_BYTES)return 0;$reply.=$chunk;return strlen($chunk);}]);
        try{$ok=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);}finally{curl_close($ch);}
        if($ok===false)throw new SourceDispatchUncertain('Source ownership outcome is unknown.');return ['status'=>$status,'body'=>$reply,'signature'=>$signature,'type'=>$type];
    }
}

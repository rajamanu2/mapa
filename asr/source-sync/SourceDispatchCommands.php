<?php
declare(strict_types=1);
namespace MapaSource;
require_once __DIR__.'/SourceCommands.php';

/** Signed ownership protocol; contains no patient facts or caller-supplied grants. */
final class SourceDispatchCommands {
    public const CONTRACT='mapa-asr-dispatch-v1';
    public const MAX_BYTES=8192;
    public const KEYS=['contract','company_id','request_id','actor_id','action','journey_id','ambulance_id','driver_id','expected_version','next_status'];
    public const ACTIONS=['reserve','lookup','transition'];
    public const STATES=['reserved','en_route','arrived_scene','transporting','completed','cancelled'];
    public static function signature(string $secret,string $ts,string $nonce,string $body):string {return hash_hmac('sha256',"POST\n".self::CONTRACT."\n$ts\n$nonce\n".hash('sha256',$body),$secret);}
    public static function responseSignature(string $secret,string $nonce,int $status,string $body):string {return hash_hmac('sha256',self::CONTRACT."\n$nonce\n$status\n".$body,$secret);}
    public static function authorized(array $server,string $secret,string $body,int $now):bool {
        $ts=$server['HTTP_X_MAPA_TIMESTAMP']??'';$nonce=$server['HTTP_X_MAPA_NONCE']??'';$sig=$server['HTTP_X_MAPA_SIGNATURE']??'';
        return strlen($secret)>=32&&strlen($secret)<=1024&&strlen($body)<=self::MAX_BYTES&&($server['REQUEST_METHOD']??'')==='POST'&&($server['QUERY_STRING']??'')===''
            &&strtolower(trim(explode(';',$server['CONTENT_TYPE']??'')[0]))==='application/json'&&preg_match('/^[0-9]{10}$/D',$ts)&&abs($now-(int)$ts)<=30
            &&preg_match('/^[a-f0-9]{32}$/D',$nonce)&&preg_match('/^[a-f0-9]{64}$/D',$sig)&&hash_equals(self::signature($secret,$ts,$nonce,$body),$sig);
    }
    public static function exactKeys(array $value,array $keys):bool {$actual=array_keys($value);sort($actual);sort($keys);return $actual===$keys;}
    public static function validate(array $c):void {
        if(!self::exactKeys($c,self::KEYS)||$c['contract']!==self::CONTRACT||$c['company_id']!==SourceSync::COMPANY)throw new CommandRejected(422,'Invalid dispatch envelope.');
        foreach(['request_id','actor_id','journey_id'] as $key)if(!is_string($c[$key])||!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$c[$key]))throw new CommandRejected(422,'Native UUID required.');
        foreach(['ambulance_id','driver_id'] as $key)if(!is_string($c[$key])||!preg_match('/^[1-9][0-9]{0,9}$/D',$c[$key]))throw new CommandRejected(422,'Source identity required.');
        if(!in_array($c['action'],self::ACTIONS,true))throw new CommandRejected(422,'Unsupported dispatch action.');
        if($c['action']==='reserve'&&($c['expected_version']!==0||$c['next_status']!==null))throw new CommandRejected(422,'Invalid reservation request.');
        if($c['action']==='lookup'&&($c['expected_version']!==null||$c['next_status']!==null))throw new CommandRejected(422,'Invalid lookup request.');
        if($c['action']==='transition'&&(!is_int($c['expected_version'])||$c['expected_version']<1||$c['expected_version']>2147483646||!in_array($c['next_status'],array_slice(self::STATES,1),true)))throw new CommandRejected(422,'Invalid transition request.');
    }
    private static function grant(array $c,array $actors,string $vehicle,string $driver):void {
        $g=$actors[$c['actor_id']]??null;
        if(!is_array($g)||($g['company_id']??null)!==$c['company_id']||!is_array($g['actions']??null)||!in_array($c['action'],$g['actions'],true)
            ||!is_array($g['ambulance_ids']??null)||!in_array($vehicle,$g['ambulance_ids'],true)||!is_array($g['driver_ids']??null)||!in_array($driver,$g['driver_ids'],true)
            ||($c['action']==='transition'&&(!is_array($g['transition_states']??null)||!in_array($c['next_status'],$g['transition_states'],true))))throw new CommandRejected(403,'Dispatch scope is not granted.');
    }
    public static function execute(\PDO $db,array $c,array $actors):array {
        self::validate($c);self::grant($c,$actors,$c['ambulance_id'],$c['driver_id']);
        require_once __DIR__.'/SourceDispatchCoordinator.php';
        return SourceDispatchCoordinator::withLock($db,function()use($db,$c,$actors){
            $q=$db->prepare('SELECT * FROM mapa_dispatch_reservations WHERE company_id=? AND journey_id=?');$q->execute([$c['company_id'],$c['journey_id']]);$old=$q->fetch(\PDO::FETCH_ASSOC);
            if($old){
                // Lookup/transition must authorize the actual reserved identities, not just input IDs.
                self::grant($c,$actors,(string)$old['ambulance_id'],(string)$old['driver_id']);
                if((string)$old['ambulance_id']!==$c['ambulance_id']||(string)$old['driver_id']!==$c['driver_id'])throw new CommandRejected(409,'Journey identity does not match its reservation.');
            }
            if($c['action']==='reserve'){
                if($old&&$old['terminal_at']!==null)throw new CommandRejected(409,'Terminal journey cannot receive a new reservation.');
                $r=SourceDispatchCoordinator::reserve($db,$c['company_id'],$c['journey_id'],$c['ambulance_id'],$c['driver_id']);
            }else{
                if(!$old)throw new CommandRejected(404,'Journey reservation is unavailable.');
                $r=$c['action']==='lookup'?$old:SourceDispatchCoordinator::transition($db,$c['company_id'],$c['journey_id'],$c['expected_version'],$c['next_status']);
            }
            return $c+['outcome'=>'confirmed','reservation'=>['status'=>$r['status'],'version'=>(int)$r['version'],'created_at'=>$r['created_at'],'terminal_at'=>$r['terminal_at']]];
        });
    }
}

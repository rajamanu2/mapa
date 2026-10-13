<?php
declare(strict_types=1);
namespace MapaSource;
require_once __DIR__.'/SourceSync.php';

final class CommandRejected extends \RuntimeException {
    public function __construct(public readonly int $status, string $message) { parent::__construct($message); }
}

/** Explicit source-owned writes. No original bootstrap, migrations, dispatch or patient writes. */
final class SourceCommands {
    public const CONTRACT = 'mapa-asr-command-v1';
    public const MAX_BYTES = 8192;
    public const ACTIONS = ['ambulance.profile','ambulance.transfer','driver.transfer'];

    public static function signature(string $secret,string $timestamp,string $nonce,string $body):string {
        return hash_hmac('sha256',"POST\n".self::CONTRACT."\n".$timestamp."\n".$nonce."\n".hash('sha256',$body),$secret);
    }
    public static function authorized(array $server,string $secret,string $body,int $now):bool {
        $ts=$server['HTTP_X_MAPA_TIMESTAMP']??'';$nonce=$server['HTTP_X_MAPA_NONCE']??'';$sig=$server['HTTP_X_MAPA_SIGNATURE']??'';
        return strlen($secret)>=32 && strlen($body)<=self::MAX_BYTES && ($server['REQUEST_METHOD']??'')==='POST'
            && ($server['QUERY_STRING']??'')==='' && strtolower(trim(explode(';',$server['CONTENT_TYPE']??'')[0]))==='application/json'
            && preg_match('/^[0-9]{10}$/D',$ts) && abs($now-(int)$ts)<=30 && preg_match('/^[a-f0-9]{32}$/D',$nonce)
            && preg_match('/^[a-f0-9]{64}$/D',$sig) && hash_equals(self::signature($secret,$ts,$nonce,$body),$sig);
    }
    public static function responseSignature(string $secret,string $nonce,string $body):string {
        return hash_hmac('sha256',self::CONTRACT."\n".$nonce."\n".$body,$secret);
    }
    public static function revision(string $dataset,array $row):string {
        if(!in_array($dataset,['drivers','ambulances'],true)) throw new \InvalidArgumentException('Unsupported revision dataset.');
        $clean=[];foreach(SourceSync::FIELDS[$dataset] as $field) {
            if(!array_key_exists($field,$row)) throw new \InvalidArgumentException('Incomplete source record.');
            $clean[$field]=$row[$field]===null?null:(string)$row[$field];
        }
        return hash('sha256',SourceSync::json(['dataset'=>$dataset,'record'=>$clean]));
    }
    private static function keys(array $value,array $keys):void {
        $a=array_keys($value);sort($a);sort($keys);if($a!==$keys) throw new CommandRejected(422,'Unexpected or missing command fields.');
    }
    private static function id($value):bool {return is_string($value)&&preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$value)===1;}
    public static function validate(array $command):void {
        self::keys($command,['contract','company_id','request_id','actor_id','action','record_id','expected_revision','values']);
        if(($command['contract']??null)!==self::CONTRACT||($command['company_id']??null)!==SourceSync::COMPANY) throw new CommandRejected(422,'Wrong command scope.');
        foreach(['request_id','actor_id'] as $key) if(!is_string($command[$key])||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$command[$key])) throw new CommandRejected(422,'UUID required.');
        if(!in_array($command['action'],self::ACTIONS,true)||!self::id($command['record_id'])||!is_string($command['expected_revision'])||!preg_match('/^[a-f0-9]{64}$/D',$command['expected_revision'])||!is_array($command['values'])) throw new CommandRejected(422,'Invalid command.');
        $v=$command['values'];
        if($command['action']==='ambulance.profile') {
            self::keys($v,['vehicle_model','model_year']);
            if(!is_string($v['vehicle_model'])||strlen($v['vehicle_model'])>100||preg_match('/[\x00-\x1F\x7F]/',$v['vehicle_model'])||($v['model_year']!==null&&(!is_int($v['model_year'])||$v['model_year']<1900||$v['model_year']>(int)gmdate('Y')+1))) throw new CommandRejected(422,'Invalid model or year.');
        } else {
            self::keys($v,$command['action']==='driver.transfer'?['phc_id']:['phc_id','institute_type_id']);
            foreach($v as $id) if(!self::id($id)) throw new CommandRejected(422,'Invalid destination.');
        }
    }
    private static function allowed($values,string $value):bool {
        return is_array($values)&&(in_array('*',$values,true)||in_array($value,$values,true));
    }
    /** Caller authenticates signed request first. ACL is private source config, never supplied by browser. */
    public static function execute(\PDO $db,array $command,array $actors):array {
        self::validate($command);
        $grant=$actors[$command['actor_id']]??null;
        if(!is_array($grant)||!in_array($command['action'],$grant['actions']??[],true)) throw new CommandRejected(403,'Source permission not granted.');
        require_once __DIR__.'/SourceDispatchCoordinator.php';
        return SourceDispatchCoordinator::withLock($db,fn()=>self::executeLocked($db,$command,$actors));
    }
    private static function executeLocked(\PDO $db,array $command,array $actors):array {
        self::validate($command);
        $grant=$actors[$command['actor_id']]??null;
        if(!is_array($grant)||!in_array($command['action'],$grant['actions']??[],true)) throw new CommandRejected(403,'Source permission not granted.');
        if($db->getAttribute(\PDO::ATTR_DRIVER_NAME)!=='mysql'||$db->inTransaction()) throw new \RuntimeException('Independent MySQL transaction required.');
        $engines=$db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('ambulances','amb_drivers','phc','institute_type','type_ambulance','mapa_source_commands')")->fetchAll(\PDO::FETCH_KEY_PAIR);
        foreach(['ambulances','amb_drivers','phc','institute_type','type_ambulance','mapa_source_commands'] as $t) if(strtoupper($engines[$t]??'')!=='INNODB') throw new \RuntimeException('Transactional source schema required.');
        $dataset=$command['action']==='driver.transfer'?'drivers':'ambulances';$table=$dataset==='drivers'?'amb_drivers':'ambulances';
        // Stable canonical hash: JSON object key order cannot defeat idempotency.
        $canonical=$command;ksort($canonical);ksort($canonical['values']);$hash=hash('sha256',SourceSync::json($canonical));
        $db->beginTransaction();
        try {
            try {
                $q=$db->prepare('INSERT INTO mapa_source_commands (request_id,request_hash,actor_id,action_name,record_id,expected_revision,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6))');
                $q->execute([$command['request_id'],$hash,$command['actor_id'],$command['action'],$command['record_id'],$command['expected_revision']]);
            } catch(\PDOException $e) {if((int)($e->errorInfo[1]??0)!==1062) throw $e;}
            $q=$db->prepare('SELECT request_hash,receipt FROM mapa_source_commands WHERE request_id=? FOR UPDATE');$q->execute([$command['request_id']]);$entry=$q->fetch(\PDO::FETCH_ASSOC);
            if(!$entry||!hash_equals($entry['request_hash'],$hash)) throw new CommandRejected(409,'Request ID was already used for another command.');
            if($entry['receipt']!==null) {$receipt=json_decode($entry['receipt'],true,16,JSON_THROW_ON_ERROR);$db->commit();return $receipt;}
            $fields=implode(',',SourceSync::FIELDS[$dataset]);$q=$db->prepare("SELECT $fields FROM $table WHERE id=? FOR UPDATE");$q->execute([$command['record_id']]);$row=$q->fetch(\PDO::FETCH_ASSOC);
            if(!$row) throw new CommandRejected(404,'Source record no longer exists.');
            if(!self::allowed($grant['phc_ids']??null,(string)$row['phc_id'])) throw new CommandRejected(403,'Source PHC is outside permission.');
            if($dataset==='drivers') $category=(string)$row['vehicle_type'];
            else {$q=$db->prepare('SELECT type FROM type_ambulance WHERE id=? LOCK IN SHARE MODE');$q->execute([$row['type_id']]);$category=$q->fetchColumn();if($category===false) throw new CommandRejected(422,'Source ambulance category is missing.');}
            if(!self::allowed($grant['vehicle_types']??null,(string)$category)) throw new CommandRejected(403,'Fleet category is outside permission.');
            // All source master writers share the source mutex. A posting cannot change during either system's journey.
            SourceDispatchCoordinator::assertAvailable($db,$dataset==='ambulances'?$command['record_id']:'0',$dataset==='drivers'?$command['record_id']:null);
            if(!hash_equals(self::revision($dataset,$row),$command['expected_revision'])) throw new CommandRejected(409,'ASR record changed. Fetch and review before saving again.');
            $v=$command['values'];
            if($command['action']!=='ambulance.profile') {
                if(!self::allowed($grant['phc_ids']??null,$v['phc_id'])) throw new CommandRejected(403,'Destination PHC is outside permission.');
                $q=$db->prepare('SELECT type FROM phc WHERE phc_id=? LOCK IN SHARE MODE');$q->execute([$v['phc_id']]);$type=$q->fetchColumn();
                if($type===false) throw new CommandRejected(422,'Destination PHC does not exist.');
                if($command['action']==='ambulance.transfer') {
                    $q=$db->prepare('SELECT id FROM institute_type WHERE id=? LOCK IN SHARE MODE');$q->execute([$v['institute_type_id']]);
                    if($q->fetchColumn()===false||(string)$type!==$v['institute_type_id']) throw new CommandRejected(422,'Destination PHC and institute type do not match.');
                }
            }
            $sql=match($command['action']) {
                'driver.transfer'=>'UPDATE amb_drivers SET phc_id=? WHERE id=?',
                'ambulance.transfer'=>'UPDATE ambulances SET phc_id=?,institute_type_id=? WHERE id=?',
                'ambulance.profile'=>'UPDATE ambulances SET vehicle_model=?,model_year=? WHERE id=?',
            };
            $args=match($command['action']) {
                'driver.transfer'=>[$v['phc_id']], 'ambulance.transfer'=>[$v['phc_id'],$v['institute_type_id']],
                'ambulance.profile'=>[$v['vehicle_model'],$v['model_year']],
            };$args[]=$command['record_id'];$db->prepare($sql)->execute($args);
            $q=$db->prepare("SELECT $fields FROM $table WHERE id=?");$q->execute([$command['record_id']]);$updated=$q->fetch(\PDO::FETCH_ASSOC);
            $receipt=['contract'=>self::CONTRACT,'company_id'=>SourceSync::COMPANY,'request_id'=>$command['request_id'],'action'=>$command['action'],'record_id'=>$command['record_id'],'revision'=>self::revision($dataset,$updated),'applied_at'=>gmdate('c'),'refresh_required'=>true];
            $q=$db->prepare('UPDATE mapa_source_commands SET receipt=?,changes_json=?,completed_at=UTC_TIMESTAMP(6) WHERE request_id=?');
            $changes=[];foreach($v as $key=>$value)$changes[$key]=['before'=>$row[$key],'after'=>$updated[$key]];
            $q->execute([SourceSync::json($receipt),SourceSync::json($changes),$command['request_id']]);$db->commit();return $receipt;
        } catch(\Throwable $e) {if($db->inTransaction())$db->rollBack();throw $e;}
    }
}

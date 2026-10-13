<?php
declare(strict_types=1);
namespace MapaSource;
require_once __DIR__.'/SourceCommands.php';
require_once __DIR__.'/SourceDispatchCoordinator.php';
/** Reviewed roster creation and deletion of unused records only. Never cascades history. */
final class SourceMasterCommands {
    public const CONTRACT='mapa-asr-master-command-v1';public const MAX_BYTES=8192;
    public const ACTIONS=['driver.create','driver.delete','ambulance.create','ambulance.delete'];
    public static function signature(string $secret,string $ts,string $nonce,string $body):string{return hash_hmac('sha256',"POST\n".self::CONTRACT."\n$ts\n$nonce\n".hash('sha256',$body),$secret);}
    public static function responseSignature(string $secret,string $nonce,string $body):string{return hash_hmac('sha256',self::CONTRACT."\n$nonce\n$body",$secret);}
    public static function authorized(array $s,string $secret,string $body,int $now):bool {
        $ts=$s['HTTP_X_MAPA_TIMESTAMP']??'';$nonce=$s['HTTP_X_MAPA_NONCE']??'';$signature=$s['HTTP_X_MAPA_SIGNATURE']??'';
        return strlen($secret)>=32&&strlen($body)<=self::MAX_BYTES&&($s['REQUEST_METHOD']??'')==='POST'&&($s['QUERY_STRING']??'')===''&&strtolower(trim(explode(';',$s['CONTENT_TYPE']??'')[0]))==='application/json'
            &&preg_match('/^[0-9]{10}$/D',$ts)&&abs($now-(int)$ts)<=30&&preg_match('/^[a-f0-9]{32}$/D',$nonce)&&preg_match('/^[a-f0-9]{64}$/D',$signature)&&hash_equals(self::signature($secret,$ts,$nonce,$body),$signature);
    }
    private static function deny(string $message,int $status=422):never{throw new CommandRejected($status,$message);}
    private static function keys(array $value,array $keys):void{$actual=array_keys($value);sort($actual);sort($keys);if($actual!==$keys)self::deny('Unexpected master fields.');}
    private static function id(mixed $v):bool{return is_string($v)&&preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$v)===1;}
    private static function text(mixed $v,int $max,bool $empty=false):bool{return is_string($v)&&strlen($v)<=$max&&($empty||trim($v)!=='')&&!preg_match('/[\x00-\x1f\x7f]/u',$v);}
    private static function date(mixed $v):bool{if($v===null)return true;if(!is_string($v)||!preg_match('/^20\d{2}-\d{2}-\d{2}$|^19\d{2}-\d{2}-\d{2}$/D',$v))return false;$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);return $d&&$d->format('Y-m-d')===$v;}
    private static function allowed(mixed $list,string $value):bool{return is_array($list)&&(in_array('*',$list,true)||in_array($value,$list,true));}
    public static function recordRevision(string $dataset,array $row):string {
        $values=[];foreach(SourceSync::fieldsFor(SourceSync::CONTRACT_V2)[$dataset] as $key){if(!array_key_exists($key,$row))throw new \RuntimeException('Incomplete master record.');$values[$key]=$row[$key]===null?null:(string)$row[$key];}
        return hash('sha256',SourceSync::json(['dataset'=>$dataset,'record'=>$values]));
    }
    public static function collectionRevision(string $dataset,array $rows):string {usort($rows,static fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);return hash('sha256',SourceSync::json(array_map(static fn($row)=>self::recordRevision($dataset,$row),$rows)));}
    public static function validate(array $c):void {
        self::keys($c,['contract','company_id','request_id','actor_id','action','record_id','expected_revision','values']);
        if($c['contract']!==self::CONTRACT||$c['company_id']!==SourceSync::COMPANY||!in_array($c['action'],self::ACTIONS,true)||!is_array($c['values'])||!is_string($c['expected_revision'])||!preg_match('/^[a-f0-9]{64}$/D',$c['expected_revision']))self::deny('Invalid master scope.');
        foreach(['actor_id','request_id'] as $key)if(!is_string($c[$key])||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$c[$key]))self::deny('Master UUID required.');
        $create=str_ends_with($c['action'],'.create');if($create?$c['record_id']!=='new':(!is_string($c['record_id'])||!preg_match('/^[1-9][0-9]{0,9}$/D',$c['record_id'])))self::deny('Canonical source identity required.');
        $v=$c['values'];if(!$create){self::keys($v,['confirmed_label']);if(!self::text($v['confirmed_label'],255))self::deny('Read and confirm the record label.');return;}
        if($c['action']==='driver.create'){
            self::keys($v,['name','age','gender','dob','doj','phc_id','mobile_number','vehicle_type']);
            if(!self::text($v['name'],200)||($v['age']!==null&&(!is_int($v['age'])||$v['age']<0||$v['age']>120))||!in_array($v['gender'],['','Male','Female','Other'],true)||!self::date($v['dob'])||!self::date($v['doj'])||!self::id($v['phc_id'])||!is_string($v['mobile_number'])||!preg_match('/^\+?[0-9]{10,15}$/D',$v['mobile_number'])||!self::text($v['vehicle_type'],64))self::deny('Invalid driver details.');
        }else{
            self::keys($v,['vehicle_number','type_id','institute_type_id','phc_id','mandal_id','secretariat_id','parking_type','parking_place_name','latitude','longitude']);
            if(!is_string($v['vehicle_number'])||!preg_match('/^[A-Z0-9]{4,20}$/D',$v['vehicle_number'])||!is_string($v['type_id'])||($v['type_id']!==''&&!self::id($v['type_id']))||!in_array($v['parking_type'],['hospital','outside'],true)||($v['parking_place_name']!==null&&!self::text($v['parking_place_name'],200)))self::deny('Invalid ambulance mapping.');
            foreach(['institute_type_id','phc_id','mandal_id','secretariat_id'] as $key)if($v[$key]!==null&&!self::id($v[$key]))self::deny('Invalid mapping identity.');
            foreach(['latitude'=>90,'longitude'=>180] as $key=>$max)if($v[$key]!==null&&(!is_int($v[$key])&&!is_float($v[$key])||!is_finite((float)$v[$key])||abs((float)$v[$key])>$max))self::deny('Invalid parking coordinate.');
            if(($v['latitude']===null)!==($v['longitude']===null))self::deny('Both parking coordinates are required together.');
        }
        if(strlen(SourceSync::json($c))>self::MAX_BYTES)self::deny('Master command too large.');
    }
    private static function rows(\PDO $db,string $sql,array $args=[]):array{$q=$db->prepare($sql);$q->execute($args);return $q->fetchAll(\PDO::FETCH_ASSOC);}
    public static function execute(\PDO $db,array $c,array $actors,string $secret,string $timezone='Asia/Kolkata'):array {
        self::validate($c);$grant=$actors[$c['actor_id']]??null;if(!is_array($grant)||!in_array($c['action'],$grant['actions']??[],true))self::deny('Master action not granted.',403);
        if(strlen($secret)<32||$db->getAttribute(\PDO::ATTR_DRIVER_NAME)!=='mysql'||$db->inTransaction())throw new \RuntimeException('Private independent master transaction required.');
        return SourceDispatchCoordinator::withLock($db,function()use($db,$c,$grant,$secret){
            $dataset=str_starts_with($c['action'],'driver.')?'drivers':'ambulances';$table=$dataset==='drivers'?'amb_drivers':'ambulances';$create=str_ends_with($c['action'],'.create');$v=$c['values'];
            $tables=['ambulances','amb_drivers','phc','type_ambulance','institute_type','mandals','secretariat','amb_daily_status','amb_trips','amb_repairs','cad_dispatch_log','mapa_source_commands'];
            $engines=$db->query('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(\PDO::FETCH_KEY_PAIR);foreach($tables as $name)if(strtoupper($engines[$name]??'')!=='INNODB')throw new \RuntimeException('Transactional master schema required.');
            $canonical=$c;ksort($canonical);ksort($canonical['values']);$hash=hash_hmac('sha256',SourceSync::json($canonical),$secret);$db->beginTransaction();
            try {
                try{$q=$db->prepare('INSERT INTO mapa_source_commands(request_id,request_hash,actor_id,action_name,record_id,expected_revision,created_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))');$q->execute([$c['request_id'],$hash,$c['actor_id'],$c['action'],$c['record_id'],$c['expected_revision']]);}catch(\PDOException $e){if((int)($e->errorInfo[1]??0)!==1062)throw $e;}
                $entry=self::rows($db,'SELECT request_hash,receipt FROM mapa_source_commands WHERE request_id=? FOR UPDATE',[$c['request_id']])[0]??null;if(!$entry||!hash_equals($entry['request_hash'],$hash))self::deny('Master request already has different values.',409);
                if($entry['receipt']!==null){$receipt=json_decode($entry['receipt'],true,16,JSON_THROW_ON_ERROR);$db->commit();return $receipt;}
                $fields=implode(',',SourceSync::fieldsFor(SourceSync::CONTRACT_V2)[$dataset]);$rows=self::rows($db,"SELECT $fields FROM $table ORDER BY id FOR UPDATE");$record=null;foreach($rows as $row)if((string)$row['id']===$c['record_id'])$record=$row;
                if(!$create&&!$record)self::deny('Source record is missing.',404);
                if(!hash_equals($create?self::collectionRevision($dataset,$rows):self::recordRevision($dataset,$record),$c['expected_revision']))self::deny('Source roster changed. Fetch and review again.',409);
                $phc=$create?($v['phc_id']??null):$record['phc_id'];if($phc!==null&&!self::allowed($grant['phc_ids']??null,(string)$phc))self::deny('Source base outside permission.',403);
                if($dataset==='drivers')$category=$create?$v['vehicle_type']:$record['vehicle_type'];else{$type=self::rows($db,'SELECT type FROM type_ambulance WHERE id=?',[$create?$v['type_id']:$record['type_id']])[0]??null;if(!$type)self::deny('Source vehicle category missing.');$category=$type['type'];}
                if(!self::allowed($grant['vehicle_types']??null,(string)$category))self::deny('Source category outside permission.',403);
                $created=null;
                if($create){
                    if($phc!==null&&!self::rows($db,'SELECT phc_id FROM phc WHERE phc_id=?',[$phc]))self::deny('Source base missing.');
                    if($dataset==='drivers'){$exists=self::rows($db,'SELECT id FROM type_ambulance WHERE type=?',[$category]);if(!$exists)self::deny('Source category missing.');$cols=['name','age','gender','dob','doj','phc_id','mobile_number','vehicle_type'];}
                    else{
                        if(self::rows($db,'SELECT id FROM ambulances WHERE vehicle_number=?',[$v['vehicle_number']]))self::deny('Registration is already mapped.',409);
                        if($v['parking_type']==='outside'){if(!$v['parking_place_name']||$v['latitude']===null)self::deny('Custom parking needs a name and coordinates.');}
                        elseif($category==='108'){if(!$v['mandal_id']||!self::allowed($grant['mandal_ids']??null,$v['mandal_id'])||!self::rows($db,'SELECT mandal_id FROM mandals WHERE mandal_id=?',[$v['mandal_id']]))self::deny('Select an approved source mandal.');}
                        else{if(!$phc||!$v['institute_type_id']||!self::rows($db,'SELECT phc_id FROM phc WHERE phc_id=? AND type=?',[$phc,$v['institute_type_id']]))self::deny('Institute and base must match.');if($category==='Feeder/Bike'&&(!$v['secretariat_id']||!self::rows($db,'SELECT secretariat_id FROM secretariat WHERE secretariat_id=? AND phc_id=?',[$v['secretariat_id'],$phc])))self::deny('Select a secretariat within the base.');}
                        $cols=['institute_type_id','phc_id','mandal_id','secretariat_id','parking_type','parking_place_name','latitude','longitude','vehicle_number','type_id'];
                    }
                    $q=$db->prepare("INSERT INTO $table (".implode(',',$cols).') VALUES('.implode(',',array_fill(0,count($cols),'?')).')');$q->execute(array_map(static fn($key)=>$v[$key],$cols));$created=(string)$db->lastInsertId();$updated=self::rows($db,"SELECT $fields FROM $table WHERE id=?",[$created])[0];$revision=self::recordRevision($dataset,$updated);
                }else{
                    $label=(string)$record[$dataset==='drivers'?'name':'vehicle_number'];if($v['confirmed_label']!==$label)self::deny('Confirm the exact source label.');
                    SourceDispatchCoordinator::assertAvailable($db,$dataset==='ambulances'?$c['record_id']:'0',$dataset==='drivers'?$c['record_id']:null);
                    $references=$dataset==='drivers'?['SELECT id FROM amb_trips WHERE driver_id=? LIMIT 1','SELECT id FROM cad_dispatch_log WHERE driver_id=? LIMIT 1']:['SELECT id FROM amb_daily_status WHERE ambulance_id=? LIMIT 1','SELECT id FROM amb_repairs WHERE ambulance_id=? LIMIT 1','SELECT id FROM cad_dispatch_log WHERE ambulance_id=? LIMIT 1'];
                    foreach($references as $sql)if(self::rows($db,$sql,[$c['record_id']]))self::deny('Record has source history and cannot be deleted. Preserve it for audit.',409);
                    $db->prepare("DELETE FROM $table WHERE id=?")->execute([$c['record_id']]);$revision=hash('sha256',SourceSync::json(['dataset'=>$dataset,'deleted_id'=>$c['record_id']]));
                }
                $receipt=['contract'=>self::CONTRACT,'company_id'=>SourceSync::COMPANY,'request_id'=>$c['request_id'],'action'=>$c['action'],'record_id'=>$c['record_id'],'revision'=>$revision,'applied_at'=>gmdate('c'),'refresh_required'=>true];if($created!==null)$receipt['created_id']=$created;
                $q=$db->prepare('UPDATE mapa_source_commands SET receipt=?,changes_json=?,completed_at=UTC_TIMESTAMP(6) WHERE request_id=?');$q->execute([SourceSync::json($receipt),SourceSync::json(['action'=>$c['action'],'fields'=>array_keys($v)]),$c['request_id']]);$db->commit();return $receipt;
            }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        });
    }
}

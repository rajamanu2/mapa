<?php
declare(strict_types=1);
namespace MapaSource;
require_once __DIR__.'/SourceCommands.php';
require_once __DIR__.'/SourceDispatchCoordinator.php';

/** Source administrative records only. Never starts/completes a live CAD/MAPA journey. */
final class SourceWorkflowCommands {
    public const CONTRACT='mapa-asr-workflow-command-v1';
    public const MAX_BYTES=32768;
    public const ACTIONS=['session.start','session.close','trip.record','repair.complete'];
    private const TABLES=['ambulances','type_ambulance','amb_drivers','phc','secretariat','villages','users','amb_daily_status','amb_trips','amb_repairs','mapa_source_workflow_commands'];
    public static function signature(string $secret,string $ts,string $nonce,string $body):string {return hash_hmac('sha256',"POST\n".self::CONTRACT."\n$ts\n$nonce\n".hash('sha256',$body),$secret);}
    public static function responseSignature(string $secret,string $nonce,string $body):string {return hash_hmac('sha256',self::CONTRACT."\n$nonce\n$body",$secret);}
    public static function authorized(array $s,string $secret,string $body,int $now):bool {
        $ts=$s['HTTP_X_MAPA_TIMESTAMP']??'';$nonce=$s['HTTP_X_MAPA_NONCE']??'';$sig=$s['HTTP_X_MAPA_SIGNATURE']??'';
        return strlen($secret)>=32&&strlen($body)<=self::MAX_BYTES&&($s['REQUEST_METHOD']??'')==='POST'&&($s['QUERY_STRING']??'')===''
            &&strtolower(trim(explode(';',$s['CONTENT_TYPE']??'')[0]))==='application/json'&&preg_match('/^[0-9]{10}$/D',$ts)&&abs($now-(int)$ts)<=30
            &&preg_match('/^[a-f0-9]{32}$/D',$nonce)&&preg_match('/^[a-f0-9]{64}$/D',$sig)&&hash_equals(self::signature($secret,$ts,$nonce,$body),$sig);
    }
    private static function deny(string $message,int $status=422):never {throw new CommandRejected($status,$message);}
    private static function keys(array $a,array $keys):void {$actual=array_keys($a);sort($actual);sort($keys);if($actual!==$keys)self::deny('Unexpected or missing workflow fields.');}
    private static function id(mixed $v):bool {return is_string($v)&&preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$v)===1;}
    private static function date(mixed $v):bool {if(!is_string($v)||!preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/D',$v))return false;$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);return $d&&$d->format('Y-m-d')===$v;}
    private static function text(mixed $v,int $max,bool $empty=false):bool {return is_string($v)&&strlen($v)<=$max&&($empty||trim($v)!=='')&&!preg_match('/[\x00-\x1f\x7f]/u',$v);}
    /** Decimal kilometre inputs use strings; arithmetic uses integer hundredths. */
    private static function units(mixed $v):int {if(!is_string($v)||!preg_match('/^(0|[1-9][0-9]{0,7})(\.[0-9]{1,2})?$/D',$v))self::deny('A nonnegative decimal kilometre string is required.');$p=explode('.',$v);return (int)$p[0]*100+(int)str_pad($p[1]??'',2,'0');}
    private static function decimal(int $v):string {return intdiv($v,100).'.'.str_pad((string)($v%100),2,'0',STR_PAD_LEFT);}
    private static function dbUnits(mixed $v):int {if($v===null||!is_numeric($v)||(float)$v<0||!is_finite((float)$v))self::deny('Source odometer needs review.',409);return (int)round((float)$v*100);}
    private static function allows(mixed $list,string $v):bool {return is_array($list)&&(in_array('*',$list,true)||in_array($v,$list,true));}
    public static function validate(array $c):void {
        self::keys($c,['contract','company_id','request_id','actor_id','action','record_id','expected_revision','values']);
        if($c['contract']!==self::CONTRACT||$c['company_id']!==SourceSync::COMPANY||!in_array($c['action'],self::ACTIONS,true)||!self::id($c['record_id'])||!is_array($c['values']))self::deny('Invalid workflow scope.');
        foreach(['actor_id','request_id'] as $k)if(!is_string($c[$k])||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$c[$k]))self::deny('Workflow UUID required.');
        if(!is_string($c['expected_revision'])||!preg_match('/^[a-f0-9]{64}$/D',$c['expected_revision']))self::deny('Reviewed workflow revision required.');
        if(!preg_match('/^[1-9][0-9]{0,9}$/D',$c['record_id']))self::deny('Canonical numeric source ambulance ID required.');
        $v=$c['values'];
        foreach(['daily_status_id','driver_id','repair_id'] as $field)if(array_key_exists($field,$v)&&(!is_string($v[$field])||!preg_match('/^[1-9][0-9]{0,9}$/D',$v[$field])))self::deny('Canonical numeric source record ID required.');
        switch($c['action']){
            case 'session.start':
                self::keys($v,['record_date','status','odo_reading']);
                if(!self::date($v['record_date'])||!in_array($v['status'],['On Road','Off Road'],true))self::deny('Invalid day or road status.');
                if($v['status']==='On Road')self::units($v['odo_reading']);elseif($v['odo_reading']!==null)self::deny('Off Road opening odometer must be null.');break;
            case 'session.close':
                self::keys($v,['daily_status_id','closing_odo']);if(!self::id($v['daily_status_id']))self::deny('Session identity required.');self::units($v['closing_odo']);break;
            case 'repair.complete':
                self::keys($v,['repair_id','end_date','return_to_road','odo_reading']);if(!self::id($v['repair_id'])||!self::date($v['end_date'])||$v['return_to_road']!==true)self::deny('Review and confirm the repair return to road.');self::units($v['odo_reading']);break;
            case 'trip.record':
                self::keys($v,['daily_status_id','driver_id','trip_type','pickup_date','pickup_time','drop_date','drop_time','distance_kms','from_phc_id','from_secretariat_id','from_village_id','to_phc_id','to_secretariat_id','to_village_id','patients']);
                foreach(['daily_status_id','driver_id','from_phc_id','to_phc_id'] as $k)if(!self::id($v[$k]))self::deny('Trip references are required.');
                if(!in_array($v['trip_type'],['patient_village','referral','deadbody','other'],true))self::deny('Unsupported source trip type.');
                foreach(['pickup','drop'] as $k)if(!self::date($v[$k.'_date'])||!is_string($v[$k.'_time'])||!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/D',$v[$k.'_time']))self::deny('Valid pickup and drop date/time required.');
                $distance=self::units($v['distance_kms']);if($distance<=0||$distance>50000)self::deny('Trip distance must be greater than zero and at most 500 km.');
                foreach(['from','to'] as $side){$village=in_array($v['trip_type'],$side==='from'?['patient_village','other']:['deadbody','other'],true);foreach(['secretariat_id','village_id'] as $k){$value=$v[$side.'_'.$k];if($village?!self::id($value):$value!==null)self::deny('Location hierarchy does not match this trip type.');}}
                if(!array_is_list($v['patients'])||count($v['patients'])<1||count($v['patients'])>10)self::deny('One to ten patient/log rows required.');
                foreach($v['patients'] as $p){if(!is_array($p))self::deny('Invalid patient/log entry.');self::keys($p,['name','age','gender','phone','purpose']);if(!self::text($p['name'],255))self::deny('Patient/log name required.');
                    if($v['trip_type']==='other'){if($p['age']!==null||$p['gender']!==''||$p['phone']!==''||$p['purpose']!=='')self::deny('Other trip must not contain patient demographic fields.');}
                    else{if(!is_int($p['age'])||$p['age']<0||$p['age']>120||!in_array($p['gender'],['Male','Female','Other'],true)||!is_string($p['phone'])||!preg_match('/^\+?[0-9]{10,15}$/D',$p['phone']))self::deny('Valid patient age, gender and contact required.');
                        if($v['trip_type']==='deadbody'?$p['purpose']!=='':!in_array($p['purpose'],['Delivery','Accident','General Emergency'],true))self::deny('Invalid source trip purpose.');}}
        }
        if(strlen(SourceSync::json($c))>self::MAX_BYTES)self::deny('Workflow request too large.');
    }
    private static function all(\PDO $db,string $sql,array $args=[]):array {$q=$db->prepare($sql);$q->execute($args);return $q->fetchAll(\PDO::FETCH_ASSOC);}
    private static function one(\PDO $db,string $sql,array $args=[]):?array {return self::all($db,$sql,$args)[0]??null;}
    private static function update(\PDO $db,string $sql,array $args):void {$q=$db->prepare($sql);$q->execute($args);}
    private static function schema(\PDO $db):void {
        if($db->getAttribute(\PDO::ATTR_DRIVER_NAME)!=='mysql'||$db->inTransaction())throw new \RuntimeException('Independent MySQL transaction required.');
        $rows=self::all($db,'SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()');$engines=array_column($rows,'ENGINE','TABLE_NAME');foreach(self::TABLES as $t)if(strtoupper($engines[$t]??'')!=='INNODB')throw new \RuntimeException('Workflow requires reviewed InnoDB schema.');
        $unique=self::all($db,"SELECT INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='amb_daily_status' AND NON_UNIQUE=0 GROUP BY INDEX_NAME");
        if(!in_array('ambulance_id,record_date',array_column($unique,'cols'),true))throw new \RuntimeException('One-session-per-vehicle/day unique index required.');
    }
    /** Returns allowlisted source state only. No patient names, phones or location histories. */
    public static function context(\PDO $db,string $id,array $grant,bool $lock=false):array {
        $tail=$lock?' FOR UPDATE':'';
        $vehicle=self::one($db,'SELECT id,phc_id,type_id,vehicle_number FROM ambulances WHERE id=?'.$tail,[$id]);
        if(!$vehicle)self::deny('Source ambulance missing.',404);
        $type=self::one($db,'SELECT type FROM type_ambulance WHERE id=?',[$vehicle['type_id']]);
        if(!self::allows($grant['ambulance_ids']??null,$id)||!self::allows($grant['phc_ids']??null,(string)$vehicle['phc_id'])||!$type||!self::allows($grant['vehicle_types']??null,(string)$type['type']))self::deny('Source ambulance outside actor permission.',403);
        $sessions=self::all($db,'SELECT id,phc_id,ambulance_id,record_date,status,odo_reading,closing_odo,is_closed FROM amb_daily_status WHERE ambulance_id=? ORDER BY record_date DESC,id DESC'.$tail,[$id]);
        $repairs=self::all($db,'SELECT id,ambulance_id,daily_status_id,status,start_date,end_date,odo_reading FROM amb_repairs WHERE ambulance_id=? ORDER BY id'.$tail,[$id]);
        $trips=self::all($db,'SELECT t.id,t.daily_status_id,t.driver_id,t.distance_kms,t.pickup_date,t.pickup_time,t.drop_date,t.drop_time FROM amb_trips t JOIN amb_daily_status ds ON ds.id=t.daily_status_id WHERE ds.ambulance_id=? ORDER BY t.id'.$tail,[$id]);
        $private=['vehicle'=>$vehicle,'sessions'=>$sessions,'repairs'=>$repairs,'trip_boundaries'=>$trips];
        $revision=hash('sha256',SourceSync::json($private));
        return ['revision'=>$revision,'vehicle'=>$vehicle,'sessions'=>$sessions,'repairs'=>$repairs,'trip_boundaries'=>$trips,'vehicle_type'=>$type['type']];
    }
    private static function canonical(mixed $v):mixed {if(!is_array($v))return $v;if(!array_is_list($v))ksort($v);foreach($v as $k=>$a)$v[$k]=self::canonical($a);return $v;}
    private static function sourceActor(\PDO $db,array $grant):string {$id=$grant['source_user_id']??null;if(!self::id($id)||!self::one($db,'SELECT user_id FROM users WHERE user_id=?',[$id]))self::deny('Source actor mapping unavailable.',403);return $id;}
    private static function noOpenTrips(array $state):void {foreach($state['trip_boundaries'] as $t)if($t['drop_date']===null||$t['drop_time']===null)self::deny('An unfinished source/CAD trip needs control review.',409);}
    private static function session(array $state,string $id):array {foreach($state['sessions'] as $s)if((string)$s['id']===$id)return $s;self::deny('Session does not belong to this ambulance.',404);}
    private static function lastOdo(array $state):?int {foreach($state['sessions'] as $s)if($s['closing_odo']!==null)return self::dbUnits($s['closing_odo']);elseif($s['odo_reading']!==null)return self::dbUnits($s['odo_reading']);return null;}
    private static function sessionFresh(array $s,\DateTimeImmutable $now):void {$day=$now->format('Y-m-d');if($s['record_date']!==$day&& !($s['record_date']===$now->modify('-1 day')->format('Y-m-d')&&$now->format('H:i:s')<'03:00:00'))self::deny('Source session date has expired; close it and ask control to review.',409);}
    private static function hierarchy(\PDO $db,array $v,array $grant):void {foreach(['from','to'] as $side){$phc=$v[$side.'_phc_id'];if(!self::allows($grant['trip_phc_ids']??null,$phc))self::deny('Trip location outside actor permission.',403);if(!self::one($db,'SELECT phc_id FROM phc WHERE phc_id=?',[$phc]))self::deny('Trip PHC missing.');$secretariat=$v[$side.'_secretariat_id'];$village=$v[$side.'_village_id'];if($secretariat!==null&&!self::one($db,'SELECT secretariat_id FROM secretariat WHERE secretariat_id=? AND phc_id=?',[$secretariat,$phc]))self::deny('Secretariat does not belong to PHC.');if($village!==null&&!self::one($db,'SELECT village_id FROM villages WHERE village_id=? AND secretariat_id=?',[$village,$secretariat]))self::deny('Village does not belong to secretariat.');}}
    /** ACL/config are supplied privately by the server; never accept them from request JSON. */
    public static function execute(\PDO $db,array $c,array $actors,string $ledgerSecret,string $timezone='Asia/Kolkata',?\DateTimeImmutable $now=null):array {
        self::validate($c);
        $grant=$actors[$c['actor_id']]??null;if(!is_array($grant)||!in_array($c['action'],$grant['actions']??[],true))self::deny('Source workflow action not granted.',403);
        self::context($db,$c['record_id'],$grant);
        return SourceDispatchCoordinator::withLock($db,function()use($db,$c,$actors,$ledgerSecret,$timezone,$now){
            SourceDispatchCoordinator::assertNoMapaReservation($db,$c['record_id'],$c['values']['driver_id']??null);
            return self::executeLocked($db,$c,$actors,$ledgerSecret,$timezone,$now);
        });
    }
    private static function executeLocked(\PDO $db,array $c,array $actors,string $ledgerSecret,string $timezone,?\DateTimeImmutable $now):array {
        self::validate($c);$grant=$actors[$c['actor_id']]??null;if(!is_array($grant)||!in_array($c['action'],$grant['actions']??[],true))self::deny('Source workflow action not granted.',403);
        if(strlen($ledgerSecret)<32)throw new \RuntimeException('Private ledger HMAC secret required.');self::schema($db);
        $now=($now??new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone($timezone));$today=$now->format('Y-m-d');$hash=hash_hmac('sha256',SourceSync::json(self::canonical($c)),$ledgerSecret);
        $db->beginTransaction();try{
            $actor=self::sourceActor($db,$grant);
            // Lock source vehicle before ledger/session/trip rows. All coordinated writers must use this order.
            $s=self::context($db,$c['record_id'],$grant,true);
            try{self::update($db,'INSERT INTO mapa_source_workflow_commands (request_id,request_hash,actor_id,action_name,record_id,created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(6))',[$c['request_id'],$hash,$c['actor_id'],$c['action'],$c['record_id']]);}catch(\PDOException $e){if((int)($e->errorInfo[1]??0)!==1062)throw $e;}
            $ledger=self::one($db,'SELECT request_hash,receipt FROM mapa_source_workflow_commands WHERE request_id=? FOR UPDATE',[$c['request_id']]);
            if(!$ledger||!hash_equals($ledger['request_hash'],$hash))self::deny('Request ID already has another workflow payload.',409);
            if($ledger['receipt']!==null){$receipt=json_decode($ledger['receipt'],true,16,JSON_THROW_ON_ERROR);$db->commit();return $receipt;}
            if(!hash_equals($s['revision'],$c['expected_revision']))self::deny('Source workflow changed. Fetch and review again.',409);
            $v=$c['values'];$result=[];$expectedOdo=null;$phc=(string)$s['vehicle']['phc_id'];self::noOpenTrips($s);
            if($c['action']==='session.start'){
                if($v['record_date']!==$today)self::deny('New sessions must use the source current date.');
                foreach($s['sessions'] as $day)if($day['record_date']===$today||(!(bool)$day['is_closed']&&$day['status']==='On Road'))self::deny('A daily session already exists or needs closing.',409);
                if($v['status']==='On Road'){
                    if(($s['sessions'][0]['status']??null)==='Off Road')self::deny('Control must return the ambulance to road first.',409);
                    foreach($s['repairs'] as $repair)if($repair['status']!=='Completed')self::deny('An open repair prevents On Road.',409);
                    $last=self::lastOdo($s);if($last!==null&&self::units($v['odo_reading'])!==$last)self::deny('Opening odometer must match the last source closing reading.',409);
                }
                self::update($db,'INSERT INTO amb_daily_status (phc_id,ambulance_id,record_date,status,odo_reading,closing_odo,is_closed,created_by) VALUES (?,?,?,?,?,?,?,?)',[$phc,$c['record_id'],$today,$v['status'],$v['odo_reading'],$v['odo_reading'],$v['status']==='Off Road'?1:0,$actor]);$result=['daily_status_id'=>(string)$db->lastInsertId()];$expectedOdo=$v['odo_reading']===null?null:self::units($v['odo_reading']);
            }elseif($c['action']==='session.close'){
                $day=self::session($s,$v['daily_status_id']);if((bool)$day['is_closed']||$day['status']!=='On Road')self::deny('Only an open On Road session can close.',409);
                $expected=self::dbUnits($day['closing_odo']??$day['odo_reading']);if(self::units($v['closing_odo'])!==$expected)self::deny('Review the calculated closing odometer; corrections require control.',409);
                self::update($db,'UPDATE amb_daily_status SET is_closed=1,closing_odo=? WHERE id=?',[self::decimal($expected),$day['id']]);$result=['daily_status_id'=>(string)$day['id']];$expectedOdo=$expected;
            }elseif($c['action']==='trip.record'){
                $day=self::session($s,$v['daily_status_id']);if((bool)$day['is_closed']||$day['status']!=='On Road'||(string)$day['phc_id']!==$phc)self::deny('Trip requires the current open On Road source session.',409);self::sessionFresh($day,$now);
                foreach($s['repairs'] as $repair)if($repair['status']!=='Completed')self::deny('An open repair prevents a trip record.',409);
                $driver=self::one($db,'SELECT id,phc_id,vehicle_type FROM amb_drivers WHERE id=? FOR UPDATE',[$v['driver_id']]);
                if(!$driver||!self::allows($grant['driver_ids']??null,$v['driver_id'])||(string)$driver['phc_id']!==$phc||(string)$driver['vehicle_type']!==$s['vehicle_type'])self::deny('Driver is not permitted for this ambulance posting/category.',403);
                if(self::one($db,'SELECT id FROM amb_trips WHERE driver_id=? AND (drop_date IS NULL OR drop_time IS NULL) LIMIT 1 FOR UPDATE',[$v['driver_id']]))self::deny('Driver has an unfinished source/CAD trip.',409);
                self::hierarchy($db,$v,$grant);
                $pickup=new \DateTimeImmutable($v['pickup_date'].' '.$v['pickup_time'],$now->getTimezone());$drop=new \DateTimeImmutable($v['drop_date'].' '.$v['drop_time'],$now->getTimezone());
                if($v['pickup_date']!==$day['record_date']||$drop<$pickup||$drop>$now||$pickup>$now||$drop->getTimestamp()-$pickup->getTimestamp()>86400)self::deny('Completed-trip times must follow the session date, be ordered and not be in the future.');
                if(self::one($db,'SELECT t.id FROM amb_trips t JOIN amb_daily_status ds ON ds.id=t.daily_status_id WHERE (ds.ambulance_id=? OR t.driver_id=?) AND t.drop_date IS NOT NULL AND TIMESTAMP(t.pickup_date,t.pickup_time) < ? AND TIMESTAMP(t.drop_date,t.drop_time) > ? LIMIT 1 FOR UPDATE',[$c['record_id'],$v['driver_id'],$drop->format('Y-m-d H:i:s'),$pickup->format('Y-m-d H:i:s')]))self::deny('An existing source trip overlaps this completed journey.',409);
                $ids=[];$distance=self::units($v['distance_kms']);foreach($v['patients'] as $i=>$p){self::update($db,'INSERT INTO amb_trips (daily_status_id,trip_type,driver_id,patient_name,age,gender,phone_number,purpose,from_phc_id,from_secretariat_id,from_village_id,to_phc_id,to_secretariat_id,to_village_id,distance_kms,pickup_date,pickup_time,drop_date,drop_time,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$day['id'],$v['trip_type'],$v['driver_id'],trim($p['name']),$p['age'],$p['gender'],$p['phone'],$p['purpose'],$v['from_phc_id'],$v['from_secretariat_id'],$v['from_village_id'],$v['to_phc_id'],$v['to_secretariat_id'],$v['to_village_id'],$i===0?self::decimal($distance):'0.00',$v['pickup_date'],$v['pickup_time'],$v['drop_date'],$v['drop_time'],$actor]);$ids[]=(string)$db->lastInsertId();}
                $closing=self::dbUnits($day['closing_odo']??$day['odo_reading'])+$distance;self::update($db,'UPDATE amb_daily_status SET closing_odo=? WHERE id=?',[self::decimal($closing),$day['id']]);$result=['daily_status_id'=>(string)$day['id'],'trip_ids'=>$ids,'rows_created'=>count($ids),'distance_kms'=>self::decimal($distance),'closing_odo'=>self::decimal($closing)];$expectedOdo=$closing;
            }else{
                if($v['end_date']!==$today)self::deny('Repair completion must use the source current date.');$repair=null;foreach($s['repairs'] as $r){if((string)$r['id']===$v['repair_id'])$repair=$r;elseif($r['status']!=='Completed')self::deny('Another open repair prevents return to road.',409);}
                if(!$repair||$repair['status']==='Completed'||$repair['start_date']>$today)self::deny('Repair is unavailable for completion.',409);
                $odo=self::units($v['odo_reading']);$last=self::lastOdo($s);if(($last!==null&&$odo<$last)||($repair['odo_reading']!==null&&$odo<self::dbUnits($repair['odo_reading'])))self::deny('Return-to-road odometer cannot go backwards.',409);
                $day=null;foreach($s['sessions'] as $candidate)if($candidate['record_date']===$today)$day=$candidate;
                if($day&&$day['status']==='On Road')self::deny('An On Road session exists; control must reconcile repair state.',409);
                if($day&&($day['odo_reading']!==null||$day['closing_odo']!==null))self::deny('Existing odometer needs control review.',409);
                self::update($db,"UPDATE amb_repairs SET status='Completed',end_date=? WHERE id=?",[$today,$repair['id']]);
                if($day){self::update($db,"UPDATE amb_daily_status SET status='On Road',odo_reading=?,closing_odo=?,is_closed=0 WHERE id=?",[self::decimal($odo),self::decimal($odo),$day['id']]);$id=(string)$day['id'];}
                else{self::update($db,"INSERT INTO amb_daily_status (phc_id,ambulance_id,record_date,status,odo_reading,closing_odo,is_closed,created_by) VALUES (?,?,?,'On Road',?,?,0,?)",[$phc,$c['record_id'],$today,self::decimal($odo),self::decimal($odo),$actor]);$id=(string)$db->lastInsertId();}
                $result=['repair_id'=>$v['repair_id'],'daily_status_id'=>$id,'source_status'=>'On Road','operational_readiness_changed'=>false];$expectedOdo=$odo;
            }
            $updated=self::context($db,$c['record_id'],$grant,true);
            // Legacy FLOAT columns must represent the intended hundredths; otherwise rollback, never silently round.
            if($expectedOdo!==null&&self::dbUnits(self::session($updated,$result['daily_status_id'])['closing_odo'])!==$expectedOdo)self::deny('Source odometer precision requires a decimal schema migration.',409);
            if(isset($result['trip_ids']))foreach($updated['trip_boundaries'] as $t){$i=array_search((string)$t['id'],$result['trip_ids'],true);if($i!==false&&self::dbUnits($t['distance_kms'])!==($i===0?$distance:0))self::deny('Source distance precision requires a decimal schema migration.',409);}
            $receipt=['contract'=>self::CONTRACT,'company_id'=>SourceSync::COMPANY,'request_id'=>$c['request_id'],'action'=>$c['action'],'record_id'=>$c['record_id'],'revision'=>$updated['revision'],'applied_at'=>$now->format(DATE_ATOM),'refresh_required'=>true]+$result;
            // Receipt and ledger never contain names, phone numbers, demographic facts or the request body.
            self::update($db,'UPDATE mapa_source_workflow_commands SET receipt=?,completed_at=UTC_TIMESTAMP(6) WHERE request_id=?',[SourceSync::json($receipt),$c['request_id']]);$db->commit();return $receipt;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}

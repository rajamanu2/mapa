<?php
declare(strict_types=1);
namespace MapaSource;

/** Independent read-only ASR export. Never include the original site's bootstrap. */
final class SourceSync
{
    public const CONTRACT = 'mapa-asr-source-v1';
    public const CONTRACT_V2 = 'mapa-asr-source-v2';
    public const CONTRACT_V3 = 'mapa-asr-source-v3';
    public const V2_CAPABILITIES = ['administrative_mapping'=>true,'daily_sessions'=>true,'repair_details'=>true,'repair_items'=>true,'patient_records'=>false,'active_trip_state'=>true,'cad_summary'=>true,'trip_activity'=>true,'dispatch_reservation'=>false];
    public const COMPANY = '29eff7bc-7b9b-4d8a-b1b4-55f33681bd2d';
    public const MAX_BYTES = 8388608;
    public const FIELDS = [
        'drivers'=>['id','name','age','gender','dob','doj','phc_id','mobile_number','vehicle_type'],
        'ambulances'=>['id','vehicle_number','type_id','phc_id','institute_type_id','vehicle_model','model_year','parking_type','parking_place_name'],
        'hospitals'=>['phc_id','name','type'],
        'types'=>['id','type'],
        'institute_types'=>['id','type'],
        'daily_status'=>['id','ambulance_id','phc_id','record_date','status','odo_reading'],
        'repairs'=>['id','ambulance_id','start_date','end_date','nature_of_repair','odo_reading'],
        'allocations'=>['daily_status_id','driver_id'],
        'vehicle_statistics'=>['ambulance_id','trip_type','purpose','total_kms','trip_count'],
        'driver_statistics'=>['driver_id','trip_type','purpose','total_kms','trip_count'],
    ];
    public const KEYS = [
        'drivers'=>['id'],'ambulances'=>['id'],'hospitals'=>['phc_id'],'types'=>['id'],
        'institute_types'=>['id'],'daily_status'=>['id'],'repairs'=>['id'],
        'allocations'=>['daily_status_id','driver_id'],
        'vehicle_statistics'=>['ambulance_id','trip_type','purpose'],
        'driver_statistics'=>['driver_id','trip_type','purpose'],
        'secretariats'=>['secretariat_id'],'villages'=>['village_id'],'mandals'=>['mandal_id'],
        'repair_items'=>['id'],'trip_activity'=>['id'],'cad_incidents'=>['id'],'cad_dispatches'=>['id'],'cad_status_updates'=>['id'],
    ];
    public static function supportedContracts(): array { return [self::CONTRACT,self::CONTRACT_V2,self::CONTRACT_V3]; }
    public static function fieldsFor(string $contract): array {
        if(!in_array($contract,self::supportedContracts(),true)) throw new \InvalidArgumentException('Unsupported source contract.');
        if($contract===self::CONTRACT)return self::FIELDS;
        $fields=array_replace(self::FIELDS,[
            'ambulances'=>[...self::FIELDS['ambulances'],'mandal_id','secretariat_id','latitude','longitude'],
            'hospitals'=>[...self::FIELDS['hospitals'],'mandal_id','latitude','longitude'],
            'daily_status'=>[...self::FIELDS['daily_status'],'is_closed','closing_odo'],
            'repairs'=>[...self::FIELDS['repairs'],'daily_status_id','status','place_of_repair','estimated_cost'],
            'repair_items'=>['id','repair_id','component_name','estimated_cost'],
            'trip_activity'=>['id','daily_status_id','driver_id','trip_type','distance_kms','pickup_date','pickup_time','drop_date','drop_time'],
            'cad_incidents'=>['id','incident_number','incident_type','priority','from_phc_id','from_secretariat_id','from_village_id','to_phc_id','to_secretariat_id','status','dispatch_time','completion_time','created_at','updated_at'],
            'cad_dispatches'=>['id','incident_id','trip_id','ambulance_id','driver_id','daily_status_id','dispatched_at','eta_minutes','status','created_at'],
            'cad_status_updates'=>['id','incident_id','dispatch_id','status','updated_at'],
        ]);
        if($contract===self::CONTRACT_V3)$fields+=['secretariats'=>['secretariat_id','name','phc_id'],'villages'=>['village_id','name','secretariat_id'],'mandals'=>['mandal_id','name']];
        return $fields;
    }
    public static function capabilitiesFor(string $contract): array {
        self::fieldsFor($contract);
        return $contract===self::CONTRACT?[]:($contract===self::CONTRACT_V3?self::V2_CAPABILITIES+['location_directory'=>true]:self::V2_CAPABILITIES);
    }
    public static function json($value): string {
        return json_encode($value, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    }
    public static function signature(string $secret,string $timestamp,string $nonce): string {
        return hash_hmac('sha256', "GET\n".self::CONTRACT."\n".$timestamp."\n".$nonce,$secret);
    }
    public static function authorized(array $server,string $secret,int $now): bool {
        $ts=$server['HTTP_X_MAPA_TIMESTAMP']??''; $nonce=$server['HTTP_X_MAPA_NONCE']??'';
        $sig=$server['HTTP_X_MAPA_SIGNATURE']??'';
        return strlen($secret)>=32 && ($server['REQUEST_METHOD']??'')==='GET'
            && ($server['QUERY_STRING']??'')==='' && preg_match('/^[0-9]{10}$/D',$ts)
            && abs($now-(int)$ts)<=30 && preg_match('/^[a-f0-9]{32}$/D',$nonce)
            && preg_match('/^[a-f0-9]{64}$/D',$sig)
            && hash_equals(self::signature($secret,$ts,$nonce),$sig);
    }
    public static function responseSignature(string $secret,string $nonce,string $body): string {
        return hash_hmac('sha256',self::CONTRACT."\n".$nonce."\n".$body,$secret);
    }
    public static function queries(string $contract=self::CONTRACT): array {
        self::fieldsFor($contract);
        $queries=[
            'drivers'=>'SELECT id,name,age,gender,dob,doj,phc_id,mobile_number,vehicle_type FROM amb_drivers ORDER BY id',
            'ambulances'=>'SELECT id,vehicle_number,type_id,phc_id,institute_type_id,vehicle_model,model_year,parking_type,parking_place_name FROM ambulances ORDER BY id',
            'hospitals'=>'SELECT phc_id,name,type FROM phc ORDER BY phc_id',
            'types'=>'SELECT id,type FROM type_ambulance ORDER BY id',
            'institute_types'=>'SELECT id,type FROM institute_type ORDER BY id',
            'daily_status'=>'SELECT id,ambulance_id,phc_id,record_date,status,odo_reading FROM amb_daily_status ORDER BY id',
            'repairs'=>'SELECT id,ambulance_id,start_date,end_date,nature_of_repair,odo_reading FROM amb_repairs ORDER BY id',
            'allocations'=>'SELECT DISTINCT daily_status_id,driver_id FROM amb_trips WHERE daily_status_id IS NOT NULL AND driver_id IS NOT NULL ORDER BY daily_status_id,driver_id',
            'vehicle_statistics'=>'SELECT ds.ambulance_id,t.trip_type,t.purpose,COALESCE(SUM(t.distance_kms),0) AS total_kms,COUNT(*) AS trip_count FROM amb_trips t JOIN amb_daily_status ds ON t.daily_status_id=ds.id WHERE ds.ambulance_id IS NOT NULL GROUP BY ds.ambulance_id,t.trip_type,t.purpose ORDER BY ds.ambulance_id,t.trip_type,t.purpose',
            'driver_statistics'=>'SELECT driver_id,trip_type,purpose,COALESCE(SUM(distance_kms),0) AS total_kms,COUNT(*) AS trip_count FROM amb_trips WHERE driver_id IS NOT NULL GROUP BY driver_id,trip_type,purpose ORDER BY driver_id,trip_type,purpose',
        ];
        if($contract!==self::CONTRACT)foreach(['ambulances'=>'ambulances','hospitals'=>'phc','daily_status'=>'amb_daily_status','repairs'=>'amb_repairs','repair_items'=>'amb_repair_items','trip_activity'=>'amb_trips','cad_incidents'=>'cad_incidents','cad_dispatches'=>'cad_dispatch_log','cad_status_updates'=>'cad_status_updates'] as $dataset=>$table)
            $queries[$dataset]='SELECT '.implode(',',self::fieldsFor($contract)[$dataset]).' FROM '.$table.' ORDER BY '.($dataset==='hospitals'?'phc_id':'id');
        if($contract===self::CONTRACT_V3){$queries['secretariats']='SELECT secretariat_id,name,phc_id FROM secretariat ORDER BY secretariat_id';$queries['villages']='SELECT village_id,name,secretariat_id FROM villages ORDER BY village_id';$queries['mandals']='SELECT mandal_id,name FROM mandals ORDER BY mandal_id';}
        return $queries;
    }
    public static function export(\PDO $db,string $sourceTimezone='UTC',string $contract=self::CONTRACT): array {
        self::fieldsFor($contract);
        $timezone=new \DateTimeZone($sourceTimezone);
        if($db->getAttribute(\PDO::ATTR_DRIVER_NAME)!=='mysql') throw new \RuntimeException('MySQL required.');
        $tables=['amb_drivers','ambulances','phc','type_ambulance','institute_type','amb_daily_status','amb_repairs','amb_trips'];
        if($contract!==self::CONTRACT)$tables=[...$tables,'amb_repair_items','cad_incidents','cad_dispatch_log','cad_status_updates'];
        if($contract===self::CONTRACT_V3)$tables=[...$tables,'secretariat','villages','mandals'];
        $stmt=$db->prepare('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.implode(',',array_fill(0,count($tables),'?')).')');
        $stmt->execute($tables); $engines=$stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        foreach($tables as $table) if(strtoupper($engines[$table]??'')!=='INNODB') throw new \RuntimeException('Consistent source tables required.');
        $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->exec('SET TRANSACTION READ ONLY');
        $db->beginTransaction();
        try {
            $snapshot=['contract'=>$contract,'source'=>'https://asrhealth.in','company_id'=>self::COMPANY,'complete'=>true,
                'generated_at'=>microtime(true),'source_date'=>(new \DateTimeImmutable('now',$timezone))->format('Y-m-d'),'datasets'=>[]];
            if($contract!==self::CONTRACT){$snapshot['capabilities']=self::capabilitiesFor($contract);$snapshot['source_timezone']=$sourceTimezone;}
            foreach(self::queries($contract) as $name=>$sql) {
                $rows=$db->query($sql.' LIMIT 50001')->fetchAll(\PDO::FETCH_ASSOC);
                if(count($rows)>50000) throw new \RuntimeException('Source too large; no partial snapshot accepted.');
                $snapshot['datasets'][$name]=$rows;
            }
            self::validate($snapshot, time());
            $db->commit();
            return $snapshot;
        } catch(\Throwable $e) { if($db->inTransaction()) $db->rollBack(); throw $e; }
    }
    public static function key(string $dataset,array $row): string {
        return self::json(array_map(static fn($key)=>$row[$key]===null?null:(string)$row[$key],self::KEYS[$dataset]));
    }
    public static function validate(array $s,int $now): void {
        $contract=$s['contract']??null;
        if(!is_string($contract)||!in_array($contract,self::supportedContracts(),true))throw new \RuntimeException('Unsupported source contract.');
        $fieldsByDataset=self::fieldsFor($contract);
        if($contract!==self::CONTRACT){
            if(($s['capabilities']??null)!==self::capabilitiesFor($contract) || !is_string($s['source_timezone']??null)||$s['source_timezone']==='')throw new \RuntimeException('Source capabilities and timezone required.');
            new \DateTimeZone($s['source_timezone']);
        }
        if( ($s['source']??null)!=='https://asrhealth.in'
            || ($s['company_id']??null)!==self::COMPANY || ($s['complete']??false)!==true
            || !is_numeric($s['generated_at']??null) || !is_finite((float)$s['generated_at'])
            || (float)$s['generated_at']>$now+60 || (float)$s['generated_at']<$now-300
            || !preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/D',(string)($s['source_date']??''))
            || !is_array($s['datasets']??null) || array_keys($s['datasets'])!==array_keys($fieldsByDataset))
            throw new \RuntimeException('Invalid or stale source snapshot.');
        if(strlen(self::json($s))>self::MAX_BYTES) throw new \RuntimeException('Source snapshot too large.');
        if($contract===self::CONTRACT_V3){
            $phcs=array_fill_keys(array_map(static fn($r)=>(string)$r['phc_id'],$s['datasets']['hospitals']),true);
            $secretariats=[];foreach($s['datasets']['secretariats'] as $r){if(!isset($phcs[(string)($r['phc_id']??'')]))throw new \RuntimeException('Orphan source secretariat.');$secretariats[(string)($r['secretariat_id']??'')]=true;}
            foreach($s['datasets']['villages'] as $r)if(!isset($secretariats[(string)($r['secretariat_id']??'')]))throw new \RuntimeException('Orphan source village.');
        }
        foreach($fieldsByDataset as $name=>$fields) {
            $rows=$s['datasets'][$name]; $seen=[];
            if(!is_array($rows)||!array_is_list($rows)||count($rows)>50000) throw new \RuntimeException('Invalid dataset.');
            foreach($rows as $row) {
                if(!is_array($row)||array_keys($row)!==$fields) throw new \RuntimeException('Unexpected source fields.');
                foreach($row as $key=>$v) {
                    if($v!==null && !is_string($v) && !is_int($v) && !is_float($v)) throw new \RuntimeException('Invalid field.');
                    if($v!==null && (strlen((string)$v)>4000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',(string)$v))) throw new \RuntimeException('Invalid text.');
                    if(($key==='id'||str_ends_with($key,'_id')||$key==='phc_id') && $v!==null
                        && !preg_match('/^[A-Za-z0-9_-]{1,120}$/D',(string)$v)
                        && !($v==='' && (($name==='types' && $key==='id' && (string)$row['type']==='104')
                            || ($name==='ambulances' && $key==='type_id' && count(array_filter($s['datasets']['types'],static fn($type)=>$type['id']==='' && (string)$type['type']==='104'))===1))))
                        throw new \RuntimeException('Invalid source identity: '.$name.'.'.$key);
                }
                if($contract!==self::CONTRACT){
                    foreach(['latitude'=>90,'longitude'=>180] as $field=>$max)
                        if(array_key_exists($field,$row) && $row[$field]!==null && (!is_numeric($row[$field]) || !is_finite((float)$row[$field]) || abs((float)$row[$field])>$max))throw new \RuntimeException('Invalid administrative coordinate.');
                    if($name==='daily_status' && !in_array($row['is_closed'],[0,1,'0','1'],true))throw new \RuntimeException('Invalid source session closure.');
                    foreach(['closing_odo','estimated_cost'] as $field)
                        if(array_key_exists($field,$row) && $row[$field]!==null && (!is_numeric($row[$field]) || !is_finite((float)$row[$field])))throw new \RuntimeException('Invalid source numeric detail.');
                }
                foreach(self::KEYS[$name] as $key) if($row[$key]===null && !in_array($key,['purpose','trip_type'],true)) throw new \RuntimeException('Missing source identity.');
                $key=self::key($name,$row);
                if(isset($seen[$key])) throw new \RuntimeException('Duplicate source identity.');
                $seen[$key]=true;
            }
        }
    }
    public static function receive(string $body,string $signature,string $nonce,string $secret,int $now): array {
        if(strlen($body)>self::MAX_BYTES || strlen($secret)<32 ||
            !hash_equals(self::responseSignature($secret,$nonce,$body),$signature))
            throw new \RuntimeException('Invalid source response signature.');
        $s=json_decode($body,true,32,JSON_THROW_ON_ERROR); self::validate($s,$now); return $s;
    }
    public static function changes(?array $old,array $new): array {
        $changes=[];
        foreach(['drivers','ambulances'] as $dataset) {
            $before=[]; foreach($old['datasets'][$dataset]??[] as $r) $before[self::key($dataset,$r)]=$r;
            foreach($new['datasets'][$dataset] as $row) {
                $key=self::key($dataset,$row); $prior=$before[$key]??null; unset($before[$key]);
                $fields=[]; if($prior) foreach($row as $field=>$value) if(!array_key_exists($field,$prior)||$value!==$prior[$field]) $fields[]=$field;
                if(!$prior||$fields) $changes[]=['dataset'=>$dataset,'source_id'=>(string)$row['id'],
                    'kind'=>$prior?'changed':'added','fields'=>$fields,'observed_at'=>$new['generated_at']];
            }
            foreach($before as $row) $changes[]=['dataset'=>$dataset,'source_id'=>(string)$row['id'],'kind'=>'removed_at_source','fields'=>[],'observed_at'=>$new['generated_at']];
        }
        return $changes;
    }
    /** Matches the original PHC/day-based report, not individual crew duty. */
    public static function attendance(array $snapshot,string $month): array {
        if(!preg_match('/^20[0-9]{2}-(0[1-9]|1[0-2])$/D',$month)) throw new \InvalidArgumentException('Invalid month.');
        $first=new \DateTimeImmutable($month.'-01');
        $days=(int)$first->format('t'); $present=[];
        foreach($snapshot['datasets']['daily_status'] as $r)
            if(substr($r['record_date'],0,7)===$month) $present[(string)$r['phc_id']][$r['record_date']]=true;
        $out=[];
        foreach($snapshot['datasets']['drivers'] as $driver) {
            $marks=[]; $p=0;$a=0;
            for($d=1;$d<=$days;$d++) {
                $date=$month.'-'.sprintf('%02d',$d);
                $mark=($date>$snapshot['source_date'] || ($driver['doj'] && $date<$driver['doj']))?'-'
                    :(isset($present[(string)$driver['phc_id']][$date])?'P':'A');
                $marks[]=$mark; $p+=($mark==='P');$a+=($mark==='A');
            }
            $out[]=['id'=>$driver['id'],'name'=>$driver['name'],'phc_id'=>$driver['phc_id'],
                'vehicle_type'=>$driver['vehicle_type'],'month'=>$month,'present'=>$p,'absent'=>$a,
                'daily_marks'=>implode(' ',$marks)];
        }
        return $out;
    }
    /** Caller holds a process lock throughout fetch/replace. Atomic private file only. */
    public static function save(string $path,array $snapshot,int $now): array {
        self::validate($snapshot,$now);
        $old=is_file($path)?json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR):null;
        if($old && $snapshot['generated_at']<=$old['snapshot']['generated_at']) throw new \RuntimeException('Out-of-order snapshot.');
        $changes=self::changes($old['snapshot']??null,$snapshot);
        require_once __DIR__.'/SourceJournal.php';
        $head=SourceJournal::prepare($path,$old,$snapshot,$now);
        $state=['snapshot'=>$snapshot,'received_at'=>$now,'reconciliation_head'=>$head,'changes'=>array_slice(array_merge($changes,$old['changes']??[]),0,500)];
        $tmp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            if(file_put_contents($tmp,self::json($state),LOCK_EX)===false) throw new \RuntimeException('Cannot save private mirror.');
            chmod($tmp,0600);
            if(!rename($tmp,$path)) throw new \RuntimeException('Cannot replace private mirror.');
        } finally { if(is_file($tmp)) unlink($tmp); }
        return ['changed_records'=>count($changes),'received_at'=>$now];
    }
}

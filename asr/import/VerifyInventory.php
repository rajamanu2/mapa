<?php
namespace ASR\Import;
/** Pure comparison. Receives allowlisted read-only rows; never saves or calls a database. */
final class VerifyInventory
{
    /** Explicitly invert the historical Windows capture bug. No fuzzy matching.
     * Called only after the original immutable snapshot hash has been verified.
     * Correct UTF-8 data that cannot be this legacy byte stream is rejected.
     */
    public static function decodeLegacyWindowsCapture(mixed $value):mixed {
        if(is_array($value)){foreach($value as $key=>$item)$value[$key]=self::decodeLegacyWindowsCapture($item);return $value;}
        if(!is_string($value))return $value;
        $decoded=@iconv('UTF-8','Windows-1252',$value);
        if($decoded===false || preg_match('//u',$decoded)!==1)throw new \InvalidArgumentException('Snapshot is not a reversible legacy Windows capture.');
        return $decoded;
    }
    private static function canonicalJson(mixed $value):mixed {
        if(!is_array($value))return $value;
        if(!array_is_list($value))ksort($value);
        foreach($value as $key=>$item)$value[$key]=self::canonicalJson($item);
        return $value;
    }
    private static function sameJson(mixed $a,mixed $b):bool{return self::canonicalJson($a)===self::canonicalJson($b);}
    private static function flagged(mixed $value):bool{return in_array($value,[true,1,'1','true','TRUE'],true);}
    public static function compare(array $plan,array $before,array $current): array
    {
        foreach([$plan,$before,$current] as $data)if(($data['company_uuid']??null)!==ImportPlan::COMPANY)throw new \InvalidArgumentException('Verification company mismatch.');
        if(($before['read_only']??null)!==true || ($current['read_only']??null)!==true)throw new \InvalidArgumentException('Read-only snapshots required.');
        $errors=[];$warnings=[];$counts=[];$types=[];$csv=$plan['fingerprints']['csv_sha256'];
        $issue=function(string $collection,string $code,?string $id=null)use(&$errors){$errors[]=['collection'=>$collection,'code'=>$code,'id'=>$id];};
        $fields=['vehicles'=>['public_id','company_uuid','name','plate_number','type','deleted_at','source_key','demo','simulated'],
            'places'=>['public_id','company_uuid','name','address','type','deleted_at','source_key','institution_type','source_base_name','demo','simulated','latitude','longitude'],
            'users'=>['public_id','company_uuid','name','phone','type','deleted_at','source_driver_id','profile_only'],
            'drivers'=>['public_id','company_uuid','user_uuid','vehicle_uuid','deleted_at','source_driver_id']];
        foreach($fields as $collection=>$protected){
            $old=[];$now=[];
            foreach($before[$collection]??[] as $row){if(($row['company_uuid']??null)!==ImportPlan::COMPANY)throw new \InvalidArgumentException('Foreign snapshot row.');$old[$row['uuid']]=$row;}
            foreach($current[$collection]??[] as $row){if(($row['company_uuid']??null)!==ImportPlan::COMPANY)throw new \InvalidArgumentException('Foreign current row.');if(isset($now[$row['uuid']]))throw new \InvalidArgumentException('Duplicate current row.');$now[$row['uuid']]=$row;}
            $counts['current_'.$collection]=count($now);$counts['existing_'.$collection.'_checked']=count($old);$counts['new_'.$collection.'_since_snapshot']=count(array_diff_key($now,$old));
            if(in_array($collection,['users','drivers'],true) && $counts['new_'.$collection.'_since_snapshot'])$warnings[]=['collection'=>$collection,'code'=>'concurrent_identity_rows_added','count'=>$counts['new_'.$collection.'_since_snapshot']];
            foreach($old as $uuid=>$row){
                if(!isset($now[$uuid])){$issue($collection,'existing_row_missing',$row['public_id']??null);continue;}
                $changed=[];foreach($protected as $field){$a=$row[$field]??null;$b=$now[$uuid][$field]??null;
                    $equal=in_array($field,['latitude','longitude'],true)?($a===null||$b===null?$a===$b:abs((float)$a-(float)$b)<1e-8):$a===$b;
                    if(!$equal)$changed[]=$field;}
                if($changed){$entry=['collection'=>$collection,'code'=>'existing_captured_fields_changed','id'=>$row['public_id']??null,'fields'=>$changed];
                    if(in_array($collection,['users','drivers'],true))$warnings[]=$entry;else $errors[]=$entry;}
            }
        }
        $counts['import_tagged_users']=0;$counts['import_tagged_drivers']=0;
        foreach(['users','drivers'] as $collection)foreach($current[$collection] as $row){$m=$row['meta']['asr_import']??[];
            if(($row['_import_key']??null)==='asr-source-import-v1' || (($m['origin']??null)===$plan['source_origin'] && ($m['source_csv_sha256']??null)===$csv))$counts['import_tagged_'.$collection]++;}
        foreach(['users','drivers'] as $collection)if($counts['import_tagged_'.$collection])$issue($collection,'unexpected_import_identity_marker');
        $counts['mapped_vehicles_present']=0;$counts['imported_vehicles_verified']=0;$counts['native_plate_matches_preserved']=0;$counts['source_hospitals_verified']=0;$counts['deferred_hospitals']=0;
        foreach($plan['vehicles'] as $record){
            if(!in_array($record['status'],['create','preserve_existing'],true))continue;
            $matches=array_values(array_filter($current['vehicles'],fn($v)=>ImportPlan::plate((string)$v['plate_number'])===$record['key']));
            if(count($matches)!==1){$issue('vehicles','missing_or_duplicate_normalized_plate');continue;}
            $v=$matches[0];$id=$v['public_id'];$counts['mapped_vehicles_present']++;
            if($v['deleted_at']!==null){$issue('vehicles','mapped_vehicle_deleted',$id);continue;}
            $m=$v['meta']['asr_import']??[];
            if($record['status']==='preserve_existing' || ($v['_import_key']??null)!=='asr-source-import-v1' || ($m['vehicle_key']??null)!==$record['key']){
                $counts['native_plate_matches_preserved']++;
                if(!in_array($v['uuid'],array_column($before['vehicles'],'uuid'),true))$warnings[]=['collection'=>'vehicles','code'=>'native_plate_match_added_after_snapshot','id'=>$id];
                continue;
            }
            $counts['imported_vehicles_verified']++;$types[$v['type']]=($types[$v['type']]??0)+1;
            if(self::flagged($v['demo']??null) || self::flagged($v['simulated']??null))$issue('vehicles','imported_inventory_marked_training',$id);
            foreach(['name','plate_number','type'] as $field)if($v[$field]!==$record['native'][$field])$issue('vehicles','source_'.$field.'_mismatch',$id);
            if((bool)$v['online'] || !(bool)$v['location_is_null'] || (int)($v['position_count']??0)!==0)$issue('vehicles','inventory_has_online_gps_or_position',$id);
            if(($m['origin']??null)!==$plan['source_origin'] || ($m['source_csv_sha256']??null)!==$csv || ($m['source_vehicle_type']??null)!==$record['source_vehicle_type']
                || ($m['hospital_key']??null)!==$record['hospital_key'] || ($m['source_rows']??null)!==$record['source_rows'] || !self::sameJson($m['source_context']??[],$record['context']??[]))$issue('vehicles','source_provenance_mismatch',$id);
            if(($m['import_scope']??null)!=='inventory_only' || ($m['operational_status_verified']??null)!==false || ($m['coordinate_semantics']??null)!=='no_vehicle_location_imported')$issue('vehicles','inventory_semantics_mismatch',$id);
        }
        foreach($plan['hospitals'] as $record){
            $matches=array_values(array_filter($current['places'],fn($p)=>($p['source_key']??null)===$record['key'] || (isset($record['institution_type'],$record['base_name'])
                && ImportPlan::text((string)($p['institution_type']??''))===ImportPlan::text($record['institution_type']) && ImportPlan::text((string)($p['source_base_name']??''))===ImportPlan::text($record['base_name']))));
            if(!in_array($record['status'],['create','preserve_existing'],true)){
                $counts['deferred_hospitals']++;foreach($matches as $p)if(($p['_import_key']??null)==='asr-source-import-v1' && ($p['meta']['asr_import']['source_csv_sha256']??null)===$csv)$issue('places','deferred_hospital_was_imported',$p['public_id']);continue;
            }
            if(count($matches)!==1){$issue('places','missing_or_duplicate_hospital_key');continue;}
            $p=$matches[0];$id=$p['public_id'];$m=$p['meta']['asr_import']??[];$counts['source_hospitals_verified']++;
            if($p['deleted_at']!==null)$issue('places','source_hospital_deleted',$id);
            if($record['status']==='preserve_existing')continue;
            if(self::flagged($p['demo']??null) || self::flagged($p['simulated']??null))$issue('places','imported_inventory_marked_training',$id);
            if($p['name']!==$record['native']['name'] || $p['type']!=='hospital' || $p['latitude']===null || $p['longitude']===null
                || abs((float)$p['latitude']-(float)$record['latitude'])>=1e-8 || abs((float)$p['longitude']-(float)$record['longitude'])>=1e-8)$issue('places','hospital_identity_or_coordinates_mismatch',$id);
            if(($m['origin']??null)!==$plan['source_origin'] || ($m['source_csv_sha256']??null)!==$csv || ($m['institution_type']??null)!==$record['institution_type']
                || ($m['base_name']??null)!==$record['base_name'] || ($m['source_rows']??null)!==$record['source_rows'] || !self::sameJson($m['source_context']??[],$record['context']??[])
                || ($m['coordinate_semantics']??null)!=='source_hospital_or_parking_base_not_live_gps')$issue('places','source_provenance_mismatch',$id);
        }
        ksort($types);
        return ['contract'=>'asr-source-resource-verification-v1','company_uuid'=>ImportPlan::COMPANY,'read_only'=>true,'state'=>$errors?'FAIL':'PASS','counts'=>$counts,
            'imported_vehicle_types'=>$types,'errors'=>$errors,'warnings'=>$warnings,'preservation_scope'=>'Captured inventory/identity fields only; existing online/status/vehicle-driver GPS excluded.',
            'identity_attribution'=>'New identities since snapshot are concurrent deltas. No import identity markers, combined with reviewed resource-only source and isolated native tests, support no importer identity writes.'];
    }
}

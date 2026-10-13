<?php
namespace ASR\Import;
/** Pure resource-only boundary, shared by dry-run tests and the reviewed CLI apply. */
final class ImportPlan
{
    public const COMPANY='29eff7bc-7b9b-4d8a-b1b4-55f33681bd2d';
    public static function plate(string $value): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/','',$value)); }
    public static function text(string $value): string { return mb_strtolower(preg_replace('/\s+/u',' ',trim($value))); }
    public static function hospitalName(string $institution,string $base): string {
        $institution=trim($institution);$base=trim($base);
        return preg_match('/^'.preg_quote($institution,'/').'(?:$|[\s-])/iu',$base)?$base:$institution.' '.$base;
    }
    private static function fail(string $reason): never { throw new \InvalidArgumentException($reason); }
    public static function unpack(array $input): array {
        if(($input['ack']??null)!=='REVIEWED_RESOURCE_CREATION_ONLY' || !is_string($input['plan_base64']??null))self::fail('A reviewed resource-only plan is required.');
        $raw=base64_decode($input['plan_base64'],true);
        if($raw===false || strlen($raw)>8388608 || !preg_match('/^[a-f0-9]{64}$/D',(string)($input['expected_plan_sha256']??''))
            || !hash_equals($input['expected_plan_sha256'],hash('sha256',$raw)))self::fail('The plan does not match its reviewed fingerprint.');
        $plan=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if(($plan['contract']??null)!=='asr-source-resource-plan-v1' || ($plan['mode']??null)!=='dry_run' || ($plan['company_uuid']??null)!==self::COMPANY
            || ($plan['source_origin']??null)!=='https://asrhealth.in' || ($plan['snapshot_compared']??null)!==true || ($plan['driver_import_allowed']??null)!==false)
            self::fail('Wrong scope or non-resource import plan.');
        $snapshot=$plan['fingerprints']['snapshot_sha256']??null;
        if(!is_string($snapshot) || !preg_match('/^[a-f0-9]{64}$/D',$snapshot) || !hash_equals($snapshot,(string)($input['expected_snapshot_sha256']??'')))
            self::fail('The plan needs its reviewed native snapshot.');
        foreach(['csv_sha256','drivers_sha256','staging_source_sha256'] as $field)if(!preg_match('/^[a-f0-9]{64}$/D',(string)($plan['fingerprints'][$field]??'')))self::fail('Missing source fingerprint.');
        foreach(['hospitals','vehicles'] as $kind){
            if(!is_array($plan[$kind]??null) || count($plan[$kind])>1000)self::fail('Invalid resource collection.');
            $keys=[];
            foreach($plan[$kind] as $record){
                if(!is_array($record) || !in_array($record['status']??null,['create','preserve_existing','conflict','deferred'],true))self::fail('Unreviewed resource status.');
                $key=$record['key']??null;
                if(!is_string($key) || ($kind==='hospitals'?!preg_match('/^[a-f0-9]{64}$/D',$key):!preg_match('/^[A-Z0-9]{1,50}$/D',$key)) || isset($keys[$key]))self::fail('Invalid or duplicate resource key.');
                $keys[$key]=true;
                if($record['status']!=='create')continue;
                $native=$record['native']??null;
                $allowed=$kind==='vehicles'?['name','plate_number','type','online','status']:['name','type'];
                if(!is_array($native) || array_diff(array_keys($native),$allowed) || array_diff($allowed,array_keys($native)))self::fail('Only allowlisted native resource fields are accepted.');
                if(!is_string($native['name']) || trim($native['name'])==='' || mb_strlen($native['name'])>150)self::fail('Invalid native resource name.');
                if(!is_array($record['context']??[]))self::fail('Invalid source context.');
                foreach($record['context']??[] as $context){if(!is_array($context) || array_diff(array_keys($context),['secretariat','mandal','date_mapped']))self::fail('Only source mapping context is allowed.');
                    foreach($context as $value)if(!is_string($value) || mb_strlen($value)>1000)self::fail('Invalid source context value.');}
                if($kind==='vehicles'){
                    if(($native['online']??null)!==false || ($native['status']??null)!=='available' || !is_string($native['plate_number'])
                        || !preg_match('/^[A-Za-z0-9 .-]{1,50}$/D',$native['plate_number']) || self::plate($native['plate_number'])!==$key
                        || ($native['type']??null)!==($record['source_vehicle_type']??null) || !is_string($native['type']) || trim($native['type'])==='' || mb_strlen($native['type'])>100)
                        self::fail('Vehicle must retain its source identity/type and remain offline.');
                    $base=$record['hospital_key']??null;if($base!==null && (!is_string($base)||!preg_match('/^[a-f0-9]{64}$/D',$base)))self::fail('Invalid source parking-base key.');
                }else{
                    if($native['type']!=='hospital' || !is_string($record['base_name']??null) || !is_string($record['institution_type']??null) || trim($record['institution_type'])===''
                        || $native['name']!==self::hospitalName($record['institution_type'],$record['base_name']))self::fail('Invalid typed hospital identity.');
                    $lat=$record['latitude']??null;$lng=$record['longitude']??null;
                    if(!(is_int($lat)||is_float($lat)) || !(is_int($lng)||is_float($lng)) || !is_finite((float)$lat) || !is_finite((float)$lng)
                        || $lat < -90 || $lat >90 || $lng < -180 || $lng >180 || ($lat==0 && $lng==0))self::fail('Actual source hospital coordinates are required.');
                }
            }
        }
        return $plan;
    }
}

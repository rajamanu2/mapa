<?php
namespace ASR\Import;
/** Pure policy for a separate, explicitly reviewed researched-hospital supplement. */
final class SupplementPlan
{
    public const ORIGINAL_SHA='ce53af09b138807dbefb68de562d360b0ab0659726f0a628bfa1517e53912c2d';
    public static function validate(array $plan):array {
        if(($plan['contract']??null)!=='asr-hospital-pin-supplement-v1' || ($plan['company_uuid']??null)!==ImportPlan::COMPANY
            || ($plan['source_origin']??null)!=='https://asrhealth.in' || ($plan['original_plan_sha256']??null)!==self::ORIGINAL_SHA
            || ($plan['mode']??null)!=='review_before_apply')throw new \InvalidArgumentException('Supplement scope mismatch.');
        foreach(['source_csv_sha256','evidence_sha256'] as $field)if(!preg_match('/^[a-f0-9]{64}$/',(string)($plan[$field]??'')))throw new \InvalidArgumentException('Supplement evidence fingerprint required.');
        if(!is_array($plan['hospitals']??null) || !array_is_list($plan['hospitals']) || count($plan['hospitals'])<1 || count($plan['hospitals'])>3)throw new \InvalidArgumentException('Only1 to3 reviewed hospital pins allowed.');
        $keys=[];
        foreach($plan['hospitals'] as $record){
            $key=$record['hospital_key']??null;
            if(!is_string($key) || !preg_match('/^[a-f0-9]{64}$/',$key) || isset($keys[$key]))throw new \InvalidArgumentException('Unique hospital identity required.');
            $keys[$key]=true;
            if(!in_array($record['institution_type']??null,['AH','PHC','KGH'],true))throw new \InvalidArgumentException('Unsupported supplement institution.');
            foreach(['base_name'=>150,'name'=>150,'identity'=>150,'address'=>250] as $field=>$limit){$value=$record[$field]??null;
                if(!is_string($value) || trim($value)==='' || $value!==trim($value) || mb_strlen($value)>$limit)throw new \InvalidArgumentException('Invalid hospital text.');}
            $lat=$record['latitude']??null;$lng=$record['longitude']??null;
            if(!(is_int($lat)||is_float($lat)) || !(is_int($lng)||is_float($lng)) || !is_finite((float)$lat) || !is_finite((float)$lng)
                || abs($lat)>90 || abs($lng)>180 || ($lat==0&&$lng==0))throw new \InvalidArgumentException('Verified nonzero hospital coordinates required.');
            $url=$record['google_url']??'';
            if(!is_string($url) || !str_starts_with($url,'https://www.google.com/maps/place/')
                || !preg_match('/!3d(-?[0-9.]+)!4d(-?[0-9.]+)/',$url,$point) || (float)$point[1]!== (float)$lat || (float)$point[2]!== (float)$lng)throw new \InvalidArgumentException('Named Google facility point required, not viewport center.');
            if(($record['coordinate_semantics']??null)!=='researched_hospital_facility_pin_not_live_gps' || ($record['ground_surveyed']??null)!==false)throw new \InvalidArgumentException('Coordinate provenance required.');
            if(!is_array($record['official_sources']??null) || !count($record['official_sources']))throw new \InvalidArgumentException('Official identity corroboration required.');
            foreach($record['official_sources'] as $source)if(!is_array($source) || !is_string($source['url']??null) || !str_starts_with($source['url'],'https://') || !is_string($source['supports']??null))throw new \InvalidArgumentException('Invalid citation.');
            if(!is_array($record['source_rows']??null) || !count($record['source_rows']) || !is_array($record['source_context']??null))throw new \InvalidArgumentException('Original mapping provenance required.');
            foreach($record['source_rows'] as $row)if(!is_int($row)||$row<2)throw new \InvalidArgumentException('Original source row required.');
            if(!is_array($record['collision_names']??null) || !count($record['collision_names']) || count($record['collision_names'])>10)throw new \InvalidArgumentException('Explicit native identity collision aliases required.');
            foreach($record['collision_names'] as $name)if(!is_string($name)||trim($name)===''||mb_strlen($name)>150)throw new \InvalidArgumentException('Invalid collision alias.');
        }
        if(!is_array($plan['vehicle_links']??null) || !array_is_list($plan['vehicle_links']) || count($plan['vehicle_links'])>100)throw new \InvalidArgumentException('Reviewed inventory links required.');
        $vehicles=[];
        foreach($plan['vehicle_links'] as $link){if(!is_array($link) || count($link)!==2 || !isset($link['vehicle_key'],$link['hospital_key']) || !is_string($link['vehicle_key'])
                || $link['vehicle_key']!==ImportPlan::plate($link['vehicle_key']) || !preg_match('/^[A-Z0-9]{4,50}$/',$link['vehicle_key']) || isset($vehicles[$link['vehicle_key']]) || !isset($keys[$link['hospital_key']]))throw new \InvalidArgumentException('Unrecognized or duplicate source vehicle link.');
            $vehicles[$link['vehicle_key']]=true;}
        return $plan;
    }
    public static function unpack(array $input):array {
        if(($input['ack']??null)!=='REVIEWED_HOSPITAL_PIN_SUPPLEMENT')throw new \InvalidArgumentException('Explicit supplement review acknowledgement required.');
        $raw=base64_decode($input['plan_base64']??'',true);$expected=$input['expected_plan_sha256']??'';
        if($raw===false || !is_string($expected) || !preg_match('/^[a-f0-9]{64}$/',$expected) || !hash_equals($expected,hash('sha256',$raw)))throw new \InvalidArgumentException('Exact reviewed supplement fingerprint required.');
        return self::validate(json_decode($raw,true,512,JSON_THROW_ON_ERROR));
    }
}

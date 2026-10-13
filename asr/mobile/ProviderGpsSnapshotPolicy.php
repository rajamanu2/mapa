<?php
namespace App\MapaMobile;

/** Private provider snapshot -> display-only pins. Never native GPS or dispatch evidence. */
final class ProviderGpsSnapshotPolicy
{
    public const INPUT_CONTRACT='mapa-way4track-last-known-v1';
    public const OUTPUT_CONTRACT='mapa-gps-map-v1';
    public const MAX_BYTES=1048576;
    public const MAX_DEVICES=1000;

    public static function view(array $snapshot,string $company,array $bindings,int $now): array
    {
        if(($snapshot['contract']??null)!==self::INPUT_CONTRACT||($snapshot['company_id']??null)!==$company
            ||!self::stamp($snapshot['generated_at']??null)||$snapshot['generated_at']>$now+30000
            ||!is_array($snapshot['devices']??null)||!array_is_list($snapshot['devices'])||count($snapshot['devices'])>self::MAX_DEVICES)
            throw new \UnexpectedValueException('provider_snapshot_invalid');
        $age=max(0,(int)(($now-$snapshot['generated_at'])/1000));$feedStale=$age>120;$devices=[];$seen=[];
        $counts=['total'=>0,'plotted'=>0,'fresh'=>0,'last_known'=>0,'without_coordinates'=>0,'unmapped'=>0];
        foreach($snapshot['devices'] as $row){
            if(!is_array($row)||!is_string($row['external_id']??null)||!preg_match('/^[0-9]{15}$/D',$row['external_id'])
                ||isset($seen[$row['external_id']])||($row['position_quality']??null)!=='provider_reported')
                throw new \UnexpectedValueException('provider_device_invalid');
            $id=$row['external_id'];$seen[$id]=true;
            $coordinates=self::coordinates($row['latitude']??null,$row['longitude']??null);
            $fix=self::stamp($row['recorded_at']??null)?$row['recorded_at']:null;
            $received=self::stamp($row['provider_received_at']??null)?$row['provider_received_at']:null;
            $gps=is_bool($row['gps_on']??null)?$row['gps_on']:null;
            $connected=is_bool($row['connected']??null)?$row['connected']:null;
            $speed=self::telemetry($row['speed_kmh']??null,250);
            $heading=self::telemetry($row['heading_degrees']??null,360);
            $power=self::telemetry($row['external_power_v']??null,100);
            $battery=self::telemetry($row['battery_percent']??null,100);
            $signal=self::telemetry($row['gsm_percent']??null,100);
            $providerStatus=in_array($row['provider_status']??null,['Running','Idle','Stopped','InActive'],true)?$row['provider_status']:null;
            $ignition=in_array($row['ignition']??null,['On','Off'],true)?$row['ignition']:null;
            $fixAge=$fix!==null&&$fix<=$now+30000?max(0,(int)(($now-$fix)/1000)):null;
            $receiptValid=$fix!==null&&$received!==null&&$received<=$now+30000&&$received>=$fix-30000;
            $fresh=$coordinates&&$fixAge!==null&&$fixAge<=180&&$receiptValid&&!$feedStale&&$gps===true&&$connected===true;
            $state=!$coordinates?'unavailable':($fresh?'fresh':($fixAge===null?'unknown':'last_known'));
            $binding=$bindings[$id]??null;
            // Bindings are supplied only by the tenant-scoped database reader, never by the file.
            $mapped=is_array($binding)&&is_string($binding['vehicle_uuid']??null)&&is_string($binding['vehicle_id']??null);
            $devices[]=['id'=>'way4track:'.$id,'external_id'=>$id,'provider_label'=>self::label($row['provider_label']??null,150),
                'latitude'=>$coordinates?(float)$row['latitude']:null,'longitude'=>$coordinates?(float)$row['longitude']:null,
                'recorded_at'=>$fix,'provider_received_at'=>$received,'gps_on'=>$gps,'connected'=>$connected,
                'speed_kmh'=>$coordinates&&$fix!==null?$speed:null,'heading_degrees'=>$coordinates&&$fix!==null?$heading:null,
                'address'=>self::label($row['address']??null,240),'provider_status'=>$providerStatus,
                'ignition'=>$ignition,'external_power_v'=>$power,'battery_percent'=>$battery,'gsm_percent'=>$signal,
                'source_protocol'=>'way4track','position_quality'=>'provider_reported','accuracy_m'=>null,'dispatch_eligible'=>false,
                'mapped'=>$mapped,'vehicle_uuid'=>$mapped?$binding['vehicle_uuid']:null,'vehicle_id'=>$mapped?$binding['vehicle_id']:null,
                'registration'=>$mapped?self::label($binding['registration']??null,100):null,
                'vehicle_name'=>$mapped?self::label($binding['vehicle_name']??null,150):null,
                'vehicle_type'=>$mapped?self::label($binding['vehicle_type']??null,80):null,
                'vehicle_make'=>$mapped?self::label($binding['vehicle_make']??null,80):null,
                'vehicle_model'=>$mapped?self::label($binding['vehicle_model']??null,80):null,
                'vehicle_year'=>$mapped?self::label($binding['vehicle_year']??null,8):null,
                'vehicle_color'=>$mapped?self::label($binding['vehicle_color']??null,60):null,
                'call_sign'=>$mapped?self::label($binding['call_sign']??null,80):null,
                'vehicle_status'=>$mapped?self::label($binding['vehicle_status']??null,80):null,
                'vehicle_online'=>$mapped?self::online($binding['vehicle_online']??null):null,
                'seating_capacity'=>$mapped&&is_numeric($binding['seating_capacity']??null)?(int)$binding['seating_capacity']:null,
                'driver_id'=>$mapped?self::label($binding['driver_id']??null,120):null,
                'driver_name'=>$mapped?self::label($binding['driver_name']??null,150):null,
                'stale'=>!$fresh,'age_seconds'=>$fixAge,'location_state'=>$state];
            $counts['total']++;$counts[$coordinates?'plotted':'without_coordinates']++;
            if($state==='fresh')$counts['fresh']++;elseif($coordinates)$counts['last_known']++;
            if(!$mapped)$counts['unmapped']++;
        }
        $excluded=$snapshot['excluded_count']??0;
        return ['contract'=>self::OUTPUT_CONTRACT,'company_id'=>$company,'provider'=>'way4track','generated_at'=>$snapshot['generated_at'],'server_time'=>$now,
            'feed_stale'=>$feedStale,'snapshot_age_seconds'=>$age,'read_only'=>true,'counts'=>$counts,
            'excluded_count'=>is_int($excluded)&&$excluded>=0?$excluded:0,'devices'=>$devices];
    }
    private static function stamp($value): bool {return is_int($value)&&$value>0;}
    private static function telemetry($value,int $maximum): ?float {
        return (is_int($value)||is_float($value))&&is_finite((float)$value)&&$value>=0&&$value<=$maximum?(float)$value:null;
    }
    private static function online($value): ?bool {
        if(in_array($value,[true,1,'1',"\x01"],true))return true;
        if(in_array($value,[false,0,'0',"\x00"],true))return false;
        return null;
    }
    private static function coordinates($lat,$lng): bool {
        return (is_int($lat)||is_float($lat))&&(is_int($lng)||is_float($lng))&&is_finite((float)$lat)&&is_finite((float)$lng)
            &&abs((float)$lat)<=90&&abs((float)$lng)<=180&&!((float)$lat===0.0&&(float)$lng===0.0);
    }
    private static function label($value,int $limit): ?string {
        if(!is_string($value))return null;
        $value=preg_replace('/[\x00-\x1f\x7f]/u',' ',$value);
        if($value===null)return null;
        $value=mb_substr(trim($value),0,$limit);
        return $value===''?null:$value;
    }
}

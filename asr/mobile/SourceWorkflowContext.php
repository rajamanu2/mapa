<?php
namespace App\MapaMobile;
/** Reconstructs the source workflow CAS projection from the exact signed snapshot. */
final class SourceWorkflowContext {
    public static function build(array $snapshot,string $id):?array {
        if(!in_array($snapshot['contract'],[\MapaSource\SourceSync::CONTRACT_V2,\MapaSource\SourceSync::CONTRACT_V3],true))return null;
        $d=$snapshot['datasets'];$record=null;foreach($d['ambulances'] as $r)if((string)$r['id']===$id){$record=$r;break;}if(!$record)return null;
        $project=static function($row,$keys){$out=[];foreach($keys as $key)$out[$key]=$row[$key];return $out;};
        $vehicle=$project($record,['id','phc_id','type_id','vehicle_number']);
        $sessions=[];$sessionIds=[];foreach($d['daily_status'] as $row)if((string)$row['ambulance_id']===$id){$sessions[]=$project($row,['id','phc_id','ambulance_id','record_date','status','odo_reading','closing_odo','is_closed']);$sessionIds[(string)$row['id']]=true;}
        usort($sessions,static fn($a,$b)=>strcmp($b['record_date'],$a['record_date'])?:((int)$b['id']<=>(int)$a['id']));
        $repairs=[];foreach($d['repairs'] as $row)if((string)$row['ambulance_id']===$id)$repairs[]=$project($row,['id','ambulance_id','daily_status_id','status','start_date','end_date','odo_reading']);
        usort($repairs,static fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);
        $trips=[];foreach($d['trip_activity'] as $row)if(isset($sessionIds[(string)$row['daily_status_id']]))$trips[]=$project($row,['id','daily_status_id','driver_id','distance_kms','pickup_date','pickup_time','drop_date','drop_time']);
        usort($trips,static fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);
        return ['revision'=>hash('sha256',\MapaSource\SourceSync::json(['vehicle'=>$vehicle,'sessions'=>$sessions,'repairs'=>$repairs,'trip_boundaries'=>$trips])),
            'vehicle'=>$vehicle,'sessions'=>array_slice($sessions,0,100),'repairs'=>array_slice(array_reverse($repairs),0,100),'source_date'=>$snapshot['source_date'],
            'history_truncated'=>count($sessions)>100||count($repairs)>100,'pickup_coordinates_changed'=>false];
    }
}

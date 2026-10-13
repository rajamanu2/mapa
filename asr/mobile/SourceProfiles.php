<?php
namespace App\MapaMobile;
require_once __DIR__.'/SourceMasterCommands.php';

/** Source-owned profile projections; no native duty, GPS or assignment mutations. */
final class SourceProfiles
{
    public static function build(array $snapshot,string $dataset,string $id): ?array
    {
        if(!in_array($dataset,['drivers','ambulances'],true))throw new \InvalidArgumentException('Unsupported profile.');
        $data=$snapshot['datasets'];$record=null;
        foreach($data[$dataset] as $row)if((string)$row['id']===$id){$record=$row;break;}
        if(!$record)return null;
        $hospitals=array_column($data['hospitals'],'name','phc_id');
        $vehicles=array_column($data['ambulances'],'vehicle_number','id');
        $drivers=array_column($data['drivers'],'name','id');
        $sessions=array_column($data['daily_status'],null,'id');
        $record['hospital_name']=$hospitals[$record['phc_id']]??null;
        $record['assignment_origin']=SourceAssignmentProjection::label($dataset,$record,SourceAssignmentProjection::active($snapshot));
        $record['expected_revision']=\MapaSource\SourceCommands::revision($dataset,array_intersect_key($record,array_flip(\MapaSource\SourceSync::fieldsFor($snapshot['contract'])[$dataset])));
        if($snapshot['contract']!==\MapaSource\SourceSync::CONTRACT)$record['master_revision']=\MapaSource\SourceMasterCommands::recordRevision($dataset,$record);
        $statistics=array_values(array_filter($data[$dataset==='drivers'?'driver_statistics':'vehicle_statistics'],fn($r)=>(string)$r[$dataset==='drivers'?'driver_id':'ambulance_id']===$id));
        $kpis=['total_kms'=>0.0,'source_trip_rows'=>0,'patients'=>0,'deadbodies'=>0,'delivery'=>0,'accident'=>0,'emergency'=>0];
        foreach($statistics as $r){
            $count=(int)$r['trip_count'];$kpis['source_trip_rows']+=$count;$kpis['total_kms']+=(float)$r['total_kms'];
            $kind=strtolower(trim((string)$r['trip_type']));
            // Preserve each original profile's classification rather than silently harmonizing it.
            $dead=$dataset==='drivers'?str_contains($kind,'dead'):in_array($kind,['deadbody','dead body'],true);
            if($dead)$kpis['deadbodies']+=$count;
            else{$kpis['patients']+=$count;foreach(['Delivery'=>'delivery','Accident'=>'accident','General Emergency'=>'emergency'] as $purpose=>$key)if($r['purpose']===$purpose)$kpis[$key]+=$count;}
        }
        $kpis['total_kms']=round($kpis['total_kms'],1);
        $sections=['statistics'=>$statistics];
        if($snapshot['contract']!==\MapaSource\SourceSync::CONTRACT){
            $trips=[];$allocations=[];
            foreach($data['trip_activity'] as $r){$session=$sessions[$r['daily_status_id']]??null;if(!$session)continue;
                if((string)($dataset==='drivers'?$r['driver_id']:$session['ambulance_id'])!==$id)continue;
                $r['record_date']=$session['record_date'];$r['registration']=$vehicles[$session['ambulance_id']]??null;$r['driver_name']=$drivers[$r['driver_id']]??null;$trips[]=$r;}
            foreach($data['allocations'] as $r){$session=$sessions[$r['daily_status_id']]??null;if(!$session)continue;
                if((string)($dataset==='drivers'?$r['driver_id']:$session['ambulance_id'])!==$id)continue;
                $allocations[]=$r+['record_date'=>$session['record_date'],'hospital_name'=>$hospitals[$session['phc_id']]??null,'registration'=>$vehicles[$session['ambulance_id']]??null,'driver_name'=>$drivers[$r['driver_id']]??null];}
            usort($trips,fn($a,$b)=>strcmp(($b['pickup_date']??'').($b['pickup_time']??''),($a['pickup_date']??'').($a['pickup_time']??'')));
            usort($allocations,fn($a,$b)=>strcmp($b['record_date'],$a['record_date']));
            $sections['trip_activity']=$trips;$sections['allocations']=$allocations;
        }
        if($dataset==='ambulances'){
            $sections['daily_status']=array_values(array_filter($data['daily_status'],fn($r)=>(string)$r['ambulance_id']===$id));
            usort($sections['daily_status'],fn($a,$b)=>strcmp($b['record_date'],$a['record_date']));
            $sections['repairs']=array_values(array_filter($data['repairs'],fn($r)=>(string)$r['ambulance_id']===$id));
            usort($sections['repairs'],fn($a,$b)=>strcmp($b['start_date']??'',$a['start_date']??''));
            if(isset($data['repair_items'])){$ids=array_fill_keys(array_column($sections['repairs'],'id'),true);$sections['repair_items']=array_values(array_filter($data['repair_items'],fn($r)=>isset($ids[$r['repair_id']])));}
        }else{$sections['attendance']=array_values(array_filter(\MapaSource\SourceSync::attendance($snapshot,substr($snapshot['source_date'],0,7)),fn($r)=>(string)$r['id']===$id));}
        $counts=[];$truncated=[];foreach($sections as $key=>&$rows){$counts[$key]=count($rows);$limit=$key==='trip_activity'?50:100;$truncated[$key]=count($rows)>$limit;$rows=array_slice($rows,0,$limit);}unset($rows);
        return ['dataset'=>$dataset,'record'=>$record,'kpis'=>$kpis,'sections'=>$sections,'section_counts'=>$counts,'sections_truncated'=>$truncated,
            'workflow_context'=>$dataset==='ambulances'?SourceWorkflowContext::build($snapshot,$id):null,
            'owner'=>'asrhealth.in','read_only'=>true,'statistics_unit'=>'ASR source trip/patient rows; do not add these to MAPA journey totals.'];
    }
}

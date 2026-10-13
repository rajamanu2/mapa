<?php
namespace App\MapaMobile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
/** Related native dashboards share a verified source generation, never synthetic native trips. */
class SourceDashboardController
{
    public function index(Request $request,string $mode){
        $company=null;try{
            $context=(new ConsoleEnrollmentService(new PrivateMedia))->context($request);$company=$context['company'];
            if(!in_array($mode,['operations','maintenance','analytics','resources'],true)||$request->query())MobileService::fail('Unsupported source dashboard.',422);
            $state=SourceInventoryController::verifiedState($company);$snapshot=$state['snapshot'];
            if(!in_array($snapshot['contract'],['mapa-asr-source-v2','mapa-asr-source-v3'],true))MobileService::fail('Expanded ASR source data is not enabled.',503);
            $sections=[];$counts=[];
            if($mode==='operations'){
                $active=array_values(array_filter($snapshot['datasets']['cad_incidents'],fn($r)=>!in_array($r['status'],['completed','cancelled'],true)));
                $ids=array_fill_keys(array_map(fn($r)=>(string)$r['id'],$active),true);
                $sections['source_owned_calls']=$active;
                $sections['source_assignments']=SourceAssignmentProjection::active($snapshot);
                foreach($sections['source_owned_calls'] as &$call)
                    $call['assignment_origin']=SourceAssignmentProjection::label('cad_incidents',$call,$sections['source_assignments']);
                unset($call);
                foreach($sections['source_assignments'] as &$assignment) $assignment['assignment_origin']='Assigned through asrhealth.in';
                unset($assignment);
                $sections['source_status_timeline']=array_values(array_filter($snapshot['datasets']['cad_status_updates'],fn($r)=>isset($ids[(string)$r['incident_id']])));
                $sections['source_open_trip_rows']=array_values(array_filter($snapshot['datasets']['trip_activity'],fn($r)=>$r['drop_date']===null||$r['drop_time']===null));
                $counts=['asr_active_incidents'=>count($active),'asr_dispatch_records'=>count($sections['source_assignments']),'asr_open_trip_rows'=>count($sections['source_open_trip_rows'])];
            }elseif($mode==='maintenance'){
                $sections=['asr_repairs'=>$snapshot['datasets']['repairs'],'asr_repair_components'=>$snapshot['datasets']['repair_items'],'asr_daily_sessions'=>$snapshot['datasets']['daily_status']];
                $counts=['asr_repair_records'=>count($sections['asr_repairs']),'asr_component_records'=>count($sections['asr_repair_components'])];
            }elseif($mode==='analytics'){
                $sections=['asr_vehicle_statistics'=>$snapshot['datasets']['vehicle_statistics'],'asr_driver_statistics'=>$snapshot['datasets']['driver_statistics'],'asr_attendance'=>\MapaSource\SourceSync::attendance($snapshot,substr($snapshot['source_date'],0,7))];
                $counts=['asr_vehicle_groups'=>count($sections['asr_vehicle_statistics']),'asr_driver_groups'=>count($sections['asr_driver_statistics'])];
            }else{
                $counts=['asr_ambulances'=>count($snapshot['datasets']['ambulances']),'asr_drivers'=>count($snapshot['datasets']['drivers']),'asr_hospitals'=>count($snapshot['datasets']['hospitals'])];
                $sections=[];
                foreach(['ambulances','drivers','hospitals'] as $dataset){
                    $links=DB::table('mapa_source_connections')->where(['company_uuid'=>$company,'dataset'=>$dataset,'active'=>true])->get()->keyBy('source_id');
                    $native=DB::table(SourceConnections::TABLES[$dataset])->where('company_uuid',$company)->whereNull('deleted_at')->whereIn('uuid',$links->pluck('native_uuid'))->pluck('uuid')->all();$available=array_fill_keys($native,true);
                    $rows=[];foreach($snapshot['datasets'][$dataset] as $row){$id=SourceConnections::sourceId($dataset,$row);$link=$links[$id]??null;$rows[]=['source_id'=>$id,'label'=>$row[$dataset==='ambulances'?'vehicle_number':'name'],'native_uuid'=>$link&&isset($available[$link->native_uuid])?$link->native_uuid:null,'source_owner'=>'asrhealth.in'];}
                    $sections['asr_'.$dataset]=$rows;
                }
            }
            $driverNames=array_column($snapshot['datasets']['drivers'],'name','id');
            $vehicleNames=array_column($snapshot['datasets']['ambulances'],'vehicle_number','id');
            $hospitalNames=array_column($snapshot['datasets']['hospitals'],'name','phc_id');
            $sessions=array_column($snapshot['datasets']['daily_status'],null,'id');
            $truncated=[];foreach($sections as $key=>&$rows){
                $truncated[$key]=count($rows)>100;$rows=array_slice($rows,0,100);
                foreach($rows as &$row){
                    if(isset($row['driver_id']))$row['driver_name']=$driverNames[$row['driver_id']]??null;
                    $vehicleId=$row['ambulance_id']??($sessions[$row['daily_status_id']??'']['ambulance_id']??null);
                    if($vehicleId!==null)$row['vehicle_registration']=$vehicleNames[$vehicleId]??null;
                    foreach(['phc_id'=>'base_name','from_phc_id'=>'pickup_base_name','to_phc_id'=>'receiving_hospital_name'] as $field=>$label)
                        if(isset($row[$field]))$row[$label]=$hospitalNames[$row[$field]]??null;
                }unset($row);
            }unset($rows);
            $body=['contract'=>SourceConnections::CONTRACT,'company_id'=>$company,'mode'=>$mode,'generation'=>SourceConnections::generation($state),
                'received_at'=>$state['received_at'],'stale'=>time()-(int)$state['received_at']>120,'source_contract'=>$snapshot['contract'],
                'counts'=>$counts,'source'=>null,'sections'=>$sections,'sections_truncated'=>$truncated,'read_only'=>true,'owner'=>'asrhealth.in',
                'dispatch_reservation'=>false,'statistics_unit'=>'ASR patient/trip rows and source groups. MAPA journey totals are separate; these totals must not be added together.'];$status=200;
        }catch(HttpExceptionInterface $e){$body=['error'=>$e->getMessage()];$status=$e->getStatusCode();}catch(\Throwable $e){$body=['error'=>'ASR dashboard data is unavailable.'];$status=503;}
        return response()->json(['company_id'=>$company]+$body,$status)->header('Cache-Control','no-store, private')->header('X-Content-Type-Options','nosniff');
    }
}

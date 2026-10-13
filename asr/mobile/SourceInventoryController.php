<?php
namespace App\MapaMobile;
require_once __DIR__.'/SourceSync.php';
require_once __DIR__.'/SourceReceiver.php';
require_once __DIR__.'/SourceCommands.php';
require_once __DIR__.'/SourceMasterCommands.php';
use Illuminate\Http\Request;
use MapaSource\SourceSync;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Admin-only independent mirror: never writes native vehicles/drivers/orders. */
class SourceInventoryController
{
    /** Cached signed snapshot may be stale, but must still have the exact validated schema. */
    public static function verifiedState(string $company): array {
        if(!config('mapa_mobile.source_sync_enabled',false))MobileService::fail('ASR source sync is not enabled.',503);
        $path=storage_path('app/mapa-source-sync/state.json');
        if(!is_file($path)||filesize($path)>SourceSync::MAX_BYTES+1048576)MobileService::fail('No verified ASR snapshot is available.',503);
        $state=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);$snapshot=$state['snapshot']??null;
        if(!is_array($snapshot)||($snapshot['company_id']??null)!==$company||!is_int($state['received_at']??null)
            ||$state['received_at']>time()+60||!is_numeric($snapshot['generated_at']??null)||(float)$snapshot['generated_at']>time()+60)
            MobileService::fail('Source organization or receipt mismatch.',503);
        SourceSync::validate($snapshot,(int)$snapshot['generated_at']);
        return $state;
    }
    public function index(Request $request,string $dataset='status')
    {
        try {
            $context=(new ConsoleEnrollmentService(new PrivateMedia))->context($request);
            if($dataset==='status' && (!config('mapa_mobile.source_sync_enabled',false) || !is_file(storage_path('app/mapa-source-sync/state.json')))) {
                return $this->response(['contract'=>SourceSync::CONTRACT,'company_id'=>$context['company'],
                    'source'=>'https://asrhealth.in','enabled'=>(bool)config('mapa_mobile.source_sync_enabled',false),
                    'fetch_allowed'=>$this->fetchAllowed(),'has_snapshot'=>false,'counts'=>null,'received_at'=>null,
                    'stale'=>true,'read_only'=>true]);
            }
            $state=self::verifiedState($context['company']);
            $snapshot=$state['snapshot'];
            if(($snapshot['company_id']??null)!==$context['company'] || !in_array($snapshot['contract']??null,SourceSync::supportedContracts(),true)
                || ($snapshot['source']??null)!=='https://asrhealth.in' || ($snapshot['complete']??false)!==true)
                MobileService::fail('Source organization mismatch.',503);
            $age=max(0,time()-(int)$state['received_at']);
            $base=['contract'=>$snapshot['contract'],'capabilities'=>SourceSync::capabilitiesFor($snapshot['contract']),'company_id'=>$context['company'],'source'=>'https://asrhealth.in',
                'received_at'=>$state['received_at'],'generated_at'=>$snapshot['generated_at'],
                'source_date'=>$snapshot['source_date'],'stale'=>$age>120,'age_seconds'=>$age,'read_only'=>true];
            if($dataset==='status') return $this->response($base+['enabled'=>true,'has_snapshot'=>true,'fetch_allowed'=>$this->fetchAllowed(),'counts'=>array_map('count',$snapshot['datasets']),
                'master_revisions'=>$snapshot['contract']===SourceSync::CONTRACT?null:['drivers'=>\MapaSource\SourceMasterCommands::collectionRevision('drivers',$snapshot['datasets']['drivers']),'ambulances'=>\MapaSource\SourceMasterCommands::collectionRevision('ambulances',$snapshot['datasets']['ambulances'])],
                'filter_options'=>array_intersect_key($snapshot['datasets'],array_flip(['hospitals','types','institute_types','mandals','secretariats','villages']))]);
            if($dataset==='attendance') {
                $month=(string)$request->query('month',substr($snapshot['source_date'],0,7));
                if(!preg_match('/^20[0-9]{2}-(0[1-9]|1[0-2])$/D',$month)) MobileService::fail('Invalid month.',422);
                $rows=SourceSync::attendance($snapshot,$month);
            }
            elseif($dataset==='changes') $rows=$state['changes'];
            elseif(isset(SourceSync::fieldsFor($snapshot['contract'])[$dataset])) $rows=$snapshot['datasets'][$dataset];
            else MobileService::fail('Unknown source dataset.',404);
            // Resolve source labels before filtering, always through exact source IDs.
            $sessions=array_column($snapshot['datasets']['daily_status'],null,'id');
            $vehicleRows=array_column($snapshot['datasets']['ambulances'],null,'id');
            $hospitalRows=array_column($snapshot['datasets']['hospitals'],null,'phc_id');
            foreach($rows as &$row){
                if(isset($row['daily_status_id'],$sessions[$row['daily_status_id']])){
                    $session=$sessions[$row['daily_status_id']];
                    foreach(['ambulance_id','phc_id','record_date'] as $field)if(!array_key_exists($field,$row))$row[$field]=$session[$field];
                }
                if(isset($row['ambulance_id'],$vehicleRows[$row['ambulance_id']])){
                    $vehicle=$vehicleRows[$row['ambulance_id']];
                    foreach(['phc_id','type_id'] as $field)if(!array_key_exists($field,$row))$row[$field]=$vehicle[$field];
                }
            }unset($row);
            if($request->query('institute_type_id')!==null){
                $value=$request->query('institute_type_id');if(!is_string($value)||strlen($value)>64)MobileService::fail('Invalid institute filter.',422);
                $rows=array_values(array_filter($rows,fn($r)=>(string)($hospitalRows[$r['phc_id']??'']['type']??'')===$value));
            }
            foreach(['date_from','date_to'] as $bound)if($request->query($bound)!==null){
                $date=$request->query($bound);$parsed=is_string($date)?\DateTimeImmutable::createFromFormat('!Y-m-d',$date):false;
                if(!$parsed||$parsed->format('Y-m-d')!==$date||!preg_match('/^20[0-9]{2}-/',$date))MobileService::fail('Invalid source date.',422);
                $rows=array_values(array_filter($rows,fn($r)=>isset($r['record_date'])&&($bound==='date_from'?$r['record_date']>=$date:$r['record_date']<=$date)));
            }
            if($request->query('date_from')&&$request->query('date_to')&&$request->query('date_from')>$request->query('date_to'))MobileService::fail('Invalid source date range.',422);
            $search=trim((string)$request->query('search',''));
            if(strlen($search)>100) MobileService::fail('Search is too long.',422);
            $filters=['id','phc_id','ambulance_id','driver_id','daily_status_id','vehicle_type','type_id'];
            foreach($filters as $field) if($request->query($field)!==null) {
                $value=$request->query($field);
                if(!is_string($value)||strlen($value)>120) MobileService::fail('Invalid source filter.',422);
                $rows=array_values(array_filter($rows,static fn($row)=>isset($row[$field])&&(string)$row[$field]===$value));
            }
            if($search!=='') $rows=array_values(array_filter($rows,static function($row)use($search) {
                foreach(['name','vehicle_number','mobile_number','parking_place_name'] as $f)
                    if(isset($row[$f])&&mb_stripos((string)$row[$f],$search)!==false) return true;
                return false;
            }));
            $allowed=array_values(array_unique(array_merge(SourceSync::fieldsFor($snapshot['contract'])[$dataset]??[],['record_date','ambulance_id','phc_id'])));
            if($request->query('sort')!==null){
                $sort=$request->query('sort');$direction=$request->query('direction','asc');
                if(!is_string($sort)||!in_array($sort,$allowed,true)||!in_array($direction,['asc','desc'],true))MobileService::fail('Invalid source sort.',422);
                usort($rows,function($a,$b)use($sort,$direction){$av=$a[$sort]??null;$bv=$b[$sort]??null;$cmp=is_numeric($av)&&is_numeric($bv)?(float)$av<=>(float)$bv:strnatcasecmp((string)$av,(string)$bv);return $direction==='desc'?-$cmp:$cmp;});
            }
            $raw=(string)$request->query('page','1');
            if(!preg_match('/^[1-9][0-9]{0,4}$/D',$raw)) MobileService::fail('Invalid page.',422);
            $page=(int)$raw; $total=count($rows);
            $visible=array_slice($rows,($page-1)*100,100);
            $hospitals=array_column($snapshot['datasets']['hospitals'],'name','phc_id');
            $drivers=array_column($snapshot['datasets']['drivers'],'name','id');
            $ambulances=array_column($snapshot['datasets']['ambulances'],'vehicle_number','id');
            $types=array_column($snapshot['datasets']['types'],'type','id');
            $assignments=SourceAssignmentProjection::active($snapshot);
            foreach($visible as &$row) {
                if(in_array($dataset,['cad_incidents','cad_dispatches','ambulances','drivers'],true))
                    $row['assignment_origin']=SourceAssignmentProjection::label($dataset,$row,$assignments);
                if(in_array($dataset,['drivers','ambulances'],true)) $row['expected_revision']=\MapaSource\SourceCommands::revision($dataset,$row);
                if(array_key_exists('phc_id',$row)) $row['hospital_name']=$hospitals[$row['phc_id']]??null;
                if(array_key_exists('driver_id',$row)) $row['driver_name']=$drivers[$row['driver_id']]??null;
                if(array_key_exists('ambulance_id',$row)) $row['registration']=$ambulances[$row['ambulance_id']]??null;
                if(array_key_exists('type_id',$row)) $row['vehicle_type']=$types[$row['type_id']]??null;
            }
            unset($row);
            return $this->response($base+['dataset'=>$dataset,'total'=>$total,'page'=>$page,'page_size'=>100,
                'rows'=>$visible,'sortable_fields'=>$allowed,'has_more'=>$page*100<$total]);
        } catch(HttpExceptionInterface $e) {
            return response()->json(['error'=>$e->getMessage()],$e->getStatusCode())->header('Cache-Control','no-store');
        } catch(\Throwable $e) {
            return response()->json(['error'=>'ASR source records are unavailable.'],503)->header('Cache-Control','no-store');
        }
    }
    public function refresh(Request $request)
    {
        try {
            (new ConsoleEnrollmentService(new PrivateMedia))->context($request);
            if(!$this->fetchAllowed()) MobileService::fail('The ASR source connection is not configured.',503);
            if($request->query() || $request->getContent()!=='') MobileService::fail('This action takes no parameters.',422);
            $this->runSync();
            return $this->index($request,'status');
        } catch(\MapaSource\SourceSyncBusy $e) {
            return $this->response(['error'=>'A fetch is running or was just requested. Try again in 30 seconds.'],429)->header('Retry-After','30');
        } catch(HttpExceptionInterface $e) {
            return $this->response(['error'=>$e->getMessage()],$e->getStatusCode());
        } catch(\Throwable $e) {
            return $this->response(['error'=>'Fetch failed. The last verified ASR records have been kept.'],503);
        }
    }
    protected function fetchAllowed(): bool {
        return (bool)config('mapa_mobile.source_sync_enabled',false) && is_file(storage_path('app/mapa-source-sync/receiver.json'));
    }
    protected function runSync(): void {
        $dir=storage_path('app/mapa-source-sync');
        (new \MapaSource\SourceReceiver)->run($dir.'/receiver.json',$dir);
    }
    private function response(array $body,int $status=200) {
        return response()->json($body,$status)->header('Cache-Control','no-store, private')->header('X-Content-Type-Options','nosniff');
    }
}

<?php
namespace App\MapaMobile;

use Illuminate\Support\Facades\DB;
use MapaSource\SourceSync;

/** Reviewed links and read projections; never writes operational resources. */
final class SourceConnections
{
    public const CONTRACT='mapa-asr-connections-v1';
    public const TABLES=['ambulances'=>'vehicles','drivers'=>'drivers','hospitals'=>'places'];
    public static function dataset($value): string {
        if(!is_string($value)||!isset(self::TABLES[$value])) MobileService::fail('Unsupported resource type.',422);
        return $value;
    }
    public static function uuid($value): bool {return is_string($value)&&preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/Di',$value);}
    public static function sourceId(string $dataset,array $row): string {return (string)$row[$dataset==='hospitals'?'phc_id':'id'];}
    public static function fingerprint(array $row): string {ksort($row);return hash('sha256',json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
    public static function generation(array $state): string {return hash('sha256',SourceSync::json($state['snapshot']));}
    public static function permit(array $context,string $action): void {
        if(!config('mapa_mobile.source_connections_enabled',false)) MobileService::fail('Source connections are not enabled.',503);
        $path=storage_path('app/mapa-source-sync/connections.json');
        if(!is_file($path)||filesize($path)>65536) MobileService::fail('Source connection permissions are not configured.',503);
        try {$policy=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);}catch(\Throwable $e){MobileService::fail('Source connection permissions are unavailable.',503);}
        if(($policy['enabled']??false)!==true||($policy['company_id']??null)!==$context['company']
            || !in_array($action,$policy['actors'][$context['user']->uuid]??[],true)) MobileService::fail('This account is not permitted to manage source connections.',403);
    }
    private static function nativeQuery(string $dataset,string $company) {
        return DB::table(self::TABLES[$dataset])->where('company_uuid',$company)->whereNull('deleted_at');
    }
    private static function validNative(string $dataset,$record,string $company): bool {
        if(!$record||$record->company_uuid!==$company||$record->deleted_at!==null) return false;
        $meta=is_string($record->meta??null)?json_decode($record->meta,true):(array)($record->meta??[]);
        $demo=($meta['mapa_demo']??false)===true;
        if($demo!==(bool)config('mapa_mobile.source_connections_demo_enabled',false)||(!$demo&&($meta['simulated']??false)===true)) return false;
        if($dataset==='drivers') return DB::table('users')->where(['uuid'=>$record->user_uuid,'company_uuid'=>$company,'status'=>'active'])->whereNull('deleted_at')->exists();
        // Administrative PHC links need a native hospital/base, not a pickup or customer address.
        if($dataset==='hospitals') return in_array($record->type??null,['hospital','base','depot','phc'],true);
        return !in_array($record->status??null,['inactive','disabled'],true);
    }
    private static function label(string $dataset,$record): string {
        if($dataset==='drivers') return (string)(DB::table('users')->where('uuid',$record->user_uuid)->value('name')??$record->public_id);
        return (string)($dataset==='ambulances'?($record->plate_number?:$record->name):$record->name);
    }
    public function options(array $context,string $dataset,string $search,int $page): array {
        $q=self::nativeQuery($dataset,$context['company']);
        if($dataset==='drivers') {
            $q->whereIn('user_uuid',DB::table('users')->select('uuid')->where(['company_uuid'=>$context['company'],'status'=>'active'])->whereNull('deleted_at'));
            if($search!=='') $q->where(function($q)use($search,$context){$q->where('public_id','like','%'.$search.'%')->orWhereIn('user_uuid',DB::table('users')->select('uuid')->where('company_uuid',$context['company'])->where('name','like','%'.$search.'%'));});
        } elseif($search!=='') $q->where(function($q)use($search,$dataset){$q->where('name','like','%'.$search.'%')->orWhere('public_id','like','%'.$search.'%');if($dataset==='ambulances')$q->orWhere('plate_number','like','%'.$search.'%');});
        if($dataset==='hospitals')$q->whereIn('type',['hospital','base','depot','phc']);
        $q->whereRaw("COALESCE((JSON_TYPE(JSON_EXTRACT(meta, '$.mapa_demo')) = 'BOOLEAN' AND JSON_UNQUOTE(JSON_EXTRACT(meta, '$.mapa_demo')) = 'true'), 0) = ?",[config('mapa_mobile.source_connections_demo_enabled',false)?1:0]);
        if(!config('mapa_mobile.source_connections_demo_enabled',false))$q->whereRaw("COALESCE((JSON_TYPE(JSON_EXTRACT(meta, '$.simulated')) = 'BOOLEAN' AND JSON_UNQUOTE(JSON_EXTRACT(meta, '$.simulated')) = 'true'), 0) = 0");
        if($dataset==='ambulances')$q->where(function($q){$q->whereNull('status')->orWhereNotIn('status',['inactive','disabled']);});
        $records=$q->orderBy('uuid')->offset(($page-1)*50)->limit(51)->get();
        return ['rows'=>$records->take(50)->map(fn($v)=>['uuid'=>$v->uuid,'public_id'=>$v->public_id,'label'=>self::label($dataset,$v)])->all(),'page'=>$page,'page_size'=>50,'has_more'=>$records->count()>50];
    }
    public function listing(array $context,string $dataset,string $search,int $page,?string $nativeUuid=null): array {
        $state=SourceInventoryController::verifiedState($context['company']);$snapshot=$state['snapshot'];
        $links=DB::table('mapa_source_connections')->where(['company_uuid'=>$context['company'],'dataset'=>$dataset])->get()->keyBy('source_id');
        $native=self::nativeQuery($dataset,$context['company'])->whereIn('uuid',$links->pluck('native_uuid')->filter())->get()->keyBy('uuid');
        $rows=[];$present=[];
        foreach($snapshot['datasets'][$dataset] as $source) {
            $id=self::sourceId($dataset,$source);$present[$id]=true;$link=$links[$id]??null;$record=$link&&$link->active?($native[$link->native_uuid]??null):null;
            if($nativeUuid!==null&&(!$link||$link->native_uuid!==$nativeUuid||!$link->active))continue;
            $label=(string)($source[$dataset==='ambulances'?'vehicle_number':'name']??$id);
            if($search!==''&&mb_stripos($label.' '.$id,$search)===false)continue;
            $valid=self::validNative($dataset,$record,$context['company']);
            $rows[]=['source_id'=>$id,'label'=>$label,'source_revision'=>self::fingerprint($source),'link_revision'=>$link?(int)$link->revision:0,
                'native_uuid'=>$link&&$link->active?$link->native_uuid:null,'native_label'=>$valid?self::label($dataset,$record):null,
                'connection'=>$valid?'linked':($link&&$link->active?'native_unavailable':'not_linked'),
                'changed_since_review'=>$link&&$link->active?!hash_equals($link->source_fingerprint,self::fingerprint($source)):false,'source'=>$source];
        }
        foreach($links as $id=>$link)if($link->active&&!isset($present[$id])&&($nativeUuid===null||$nativeUuid===$link->native_uuid)&&($search===''||mb_stripos((string)$id,$search)!==false))
            $rows[]=['source_id'=>(string)$id,'label'=>'Source record '.$id,'source_revision'=>null,'link_revision'=>(int)$link->revision,
                'native_uuid'=>$link->native_uuid,'native_label'=>null,'connection'=>'removed_at_source','changed_since_review'=>true,'source'=>null];
        usort($rows,fn($a,$b)=>strcmp($a['source_id'],$b['source_id']));$total=count($rows);
        return ['dataset'=>$dataset,'generation'=>self::generation($state),'received_at'=>$state['received_at'],'stale'=>time()-(int)$state['received_at']>120,
            'rows'=>array_slice($rows,($page-1)*50,50),'total'=>$total,'page'=>$page,'page_size'=>50,'has_more'=>$page*50<$total];
    }
    public function change($request,array $body): array {
        $dataset=self::dataset($body['dataset']??null);$unlink=($body['action']??'link')==='unlink';
        if(array_diff(array_keys($body),['action','dataset','source_id','native_uuid','source_revision','expected_link_revision','confirmed','reason'])
            ||!in_array($body['action']??'link',['link','unlink'],true)||!is_string($body['source_id']??null)||!preg_match('/^[A-Za-z0-9_-]{1,120}$/D',$body['source_id'])
            ||(!$unlink&&(!self::uuid($body['native_uuid']??null)||!is_string($body['source_revision']??null)||!preg_match('/^[a-f0-9]{64}$/D',$body['source_revision'])))
            ||!is_int($body['expected_link_revision']??null)||$body['expected_link_revision']<0||($body['confirmed']??false)!==true
            ||!is_string($body['reason']??null)||mb_strlen(trim($body['reason']))<3||mb_strlen($body['reason'])>500||preg_match('/[\x00-\x1f\x7f]/',$body['reason']))
            MobileService::fail('Review both identities and provide a connection reason.',422);
        return DB::transaction(function()use($request,$body,$dataset,$unlink){
            $context=(new ConsoleEnrollmentService(new PrivateMedia))->context($request,true);self::permit($context,'review');
            // Serialize all link/relink operations per company; unique indexes also enforce both identities.
            DB::table('companies')->where('uuid',$context['company'])->lockForUpdate()->first();
            $state=SourceInventoryController::verifiedState($context['company']);
            if(time()-(int)$state['received_at']>120)MobileService::fail('Fetch fresh source data before reviewing connections.',409);
            $key=['company_uuid'=>$context['company'],'dataset'=>$dataset,'source_id'=>$body['source_id']];
            $existing=DB::table('mapa_source_connections')->where($key)->lockForUpdate()->first();
            if(($existing?(int)$existing->revision:0)!==$body['expected_link_revision'])MobileService::fail('Connection changed. Review again.',409);
            $source=null;foreach($state['snapshot']['datasets'][$dataset] as $row)if(self::sourceId($dataset,$row)===$body['source_id']){$source=$row;break;}
            if(!$unlink&&(!$source||!hash_equals(self::fingerprint($source),$body['source_revision'])))MobileService::fail('Source record changed. Fetch and review again.',409);
            if(!$unlink){$sourceDemo=str_starts_with((string)($source[$dataset==='ambulances'?'vehicle_number':'name']??''),'DEMO');
                if($sourceDemo!==(bool)config('mapa_mobile.source_connections_demo_enabled',false))MobileService::fail('Training and real identities cannot be linked.',422);}
            $record=null;
            if(!$unlink){
                $record=self::nativeQuery($dataset,$context['company'])->where('uuid',$body['native_uuid'])->lockForUpdate()->first();
                if(!self::validNative($dataset,$record,$context['company']))MobileService::fail('Native identity is not eligible in this organization.',422);
                if(DB::table('mapa_source_connections')->where(['company_uuid'=>$context['company'],'dataset'=>$dataset,'native_uuid'=>$record->uuid,'active'=>true])->where('source_id','!=',$body['source_id'])->exists())MobileService::fail('Native identity already has a source connection.',409);
            }elseif(!$existing||!$existing->active)MobileService::fail('There is no active connection to unlink.',409);
            if(config('mapa_mobile.source_dispatch_coordination_enabled',false))SourceDispatchSaga::assertMappingChange($context['company'],$dataset,$body['source_id'],$existing?->native_uuid,$record?->uuid);
            $revision=($existing?(int)$existing->revision:0)+1;$generation=self::generation($state);$fingerprint=$source?self::fingerprint($source):null;
            DB::table('mapa_source_connections')->updateOrInsert($key,['native_uuid'=>$record?->uuid,'active'=>!$unlink,'reviewed_by'=>$context['user']->uuid,
                'revision'=>$revision,'source_contract'=>$state['snapshot']['contract'],'source_generation'=>$generation,'source_fingerprint'=>$fingerprint,
                'reason'=>trim($body['reason']),'created_at'=>$existing?->created_at??now(),'updated_at'=>now()]);
            DB::table('mapa_source_connection_audit')->insert($key+['native_uuid'=>$record?->uuid,'previous_native_uuid'=>$existing?->native_uuid,'reviewed_by'=>$context['user']->uuid,
                'action'=>$unlink?'unlink':($existing&&$existing->active?'relink':'link'),'revision'=>$revision,'source_generation'=>$generation,'source_fingerprint'=>$fingerprint,'reason'=>trim($body['reason']),'created_at'=>now()]);
            return ['linked'=>!$unlink,'revision'=>$revision,'native_uuid'=>$record?->uuid,'source_id'=>$body['source_id'],'generation'=>$generation];
        });
    }
    public function projection(array $context,string $dataset,string $nativeUuid): array {
        $record=self::nativeQuery($dataset,$context['company'])->where('uuid',$nativeUuid)->first();
        if(!self::validNative($dataset,$record,$context['company']))MobileService::fail('Native record is unavailable.',404);
        $state=SourceInventoryController::verifiedState($context['company']);$snapshot=$state['snapshot'];
        $link=DB::table('mapa_source_connections')->where(['company_uuid'=>$context['company'],'dataset'=>$dataset,'native_uuid'=>$nativeUuid,'active'=>true])->first();
        $base=['dataset'=>$dataset,'native_uuid'=>$nativeUuid,'native_label'=>self::label($dataset,$record),'generation'=>self::generation($state),
            'received_at'=>$state['received_at'],'source_contract'=>$snapshot['contract'],'stale'=>time()-(int)$state['received_at']>120,'owner'=>'asrhealth.in','operational_owner'=>'MAPA'];
        if(!$link)return $base+['connection'=>'not_linked','source'=>null,'sections'=>[]];
        $source=null;foreach($snapshot['datasets'][$dataset] as $row)if(self::sourceId($dataset,$row)===$link->source_id){$source=$row;break;}
        if(!$source)return $base+['connection'=>'removed_at_source','source'=>null,'source_id'=>$link->source_id,'link_revision'=>(int)$link->revision,'sections'=>[]];
        $sections=[];$id=$link->source_id;
        if($dataset==='ambulances'){
            foreach(['daily_status','repairs','vehicle_statistics'] as $name)$sections[$name]=array_values(array_filter($snapshot['datasets'][$name],fn($r)=>(string)$r['ambulance_id']===$id));
            $dailyIds=array_fill_keys(array_map(fn($r)=>(string)$r['id'],$sections['daily_status']),true);
            $sections['allocations']=array_values(array_filter($snapshot['datasets']['allocations'],fn($r)=>isset($dailyIds[(string)$r['daily_status_id']])));
            if(isset($snapshot['datasets']['repair_items'])){$repairIds=array_fill_keys(array_map(fn($r)=>(string)$r['id'],$sections['repairs']),true);$sections['repair_items']=array_values(array_filter($snapshot['datasets']['repair_items'],fn($r)=>isset($repairIds[(string)$r['repair_id']])));}
        }elseif($dataset==='drivers'){
            $sections['driver_statistics']=array_values(array_filter($snapshot['datasets']['driver_statistics'],fn($r)=>(string)$r['driver_id']===$id));
            $sections['allocations']=array_values(array_filter($snapshot['datasets']['allocations'],fn($r)=>(string)$r['driver_id']===$id));
            $sections['attendance']=array_values(array_filter(SourceSync::attendance($snapshot,substr($snapshot['source_date'],0,7)),fn($r)=>(string)($r['driver_id']??$r['id']??'')===$id));
        }else{
            foreach(['ambulances','drivers','daily_status'] as $name)$sections[$name]=array_values(array_filter($snapshot['datasets'][$name],fn($r)=>(string)($r['phc_id']??'')===$id));
        }
        $truncated=[];foreach($sections as $name=>&$rows){$truncated[$name]=count($rows)>100;$rows=array_slice($rows,0,100);}unset($rows);
        return $base+['connection'=>'linked','source_id'=>$id,'link_revision'=>(int)$link->revision,'changed_since_review'=>!hash_equals($link->source_fingerprint,self::fingerprint($source)),
            'source'=>$source,'sections'=>$sections,'sections_truncated'=>$truncated,'statistics_unit'=>'ASR patient/trip rows; distinct from MAPA journeys'];
    }
}

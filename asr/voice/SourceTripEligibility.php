<?php
namespace App\MapaVoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Source evidence can prevent availability; it never creates or completes native trips. */
final class SourceTripEligibility
{
    public function inspect(string $company,object $driver,object $vehicle): ?array {
        if(!\App\MapaMobile\DispatchAuthority::mapaControls())return null;
        $ids=[];$imported=false;$state=null;
        foreach(['drivers'=>$driver,'ambulances'=>$vehicle] as $dataset=>$resource){
            $meta=Policy::meta($resource->meta??null);
            $hasSource=AutomaticDispatchPolicy::sourceOwned($resource->meta??null);
            $links=Schema::hasTable('mapa_source_connections')?DB::table('mapa_source_connections')->where(['company_uuid'=>$company,'dataset'=>$dataset,'native_uuid'=>$resource->uuid])->get():collect();
            $hasSource=$hasSource||$links->isNotEmpty();$imported=$imported||$hasSource;
            if(!$hasSource)continue;
            if($links->contains(fn($link)=>!($link->active??false)))return self::unknown('A source resource connection was revoked; review identity before dispatch.');
            $candidates=[];
            foreach($links as $link)if(($link->active??false)&&is_scalar($link->source_id??null))$candidates[]=(string)$link->source_id;
            foreach([$meta['asr_source_id']??null,$meta['mapa_source_id']??null,$meta['asr_import']['source_id']??null] as $id)
                if(is_string($id)||is_int($id))$candidates[]=(string)$id;
            $candidates=array_values(array_unique(array_filter($candidates,fn($id)=>preg_match('/^[A-Za-z0-9_-]{1,120}$/D',$id))));
            if($dataset==='ambulances'&&$candidates===[]&&is_string($meta['asr_import']['vehicle_key']??null)){
                try{$state=\App\MapaMobile\SourceInventoryController::verifiedState($company);}
                catch(\Throwable $e){return self::unknown('Verified source trip evidence is unavailable. Control must reconcile availability.');}
                $plate=static fn($value)=>preg_replace('/[^A-Z0-9]/','',strtoupper((string)$value));
                $key=$plate($meta['asr_import']['vehicle_key']);
                if($key===''||$key!==$plate($resource->plate_number??''))return self::unknown('Imported registration changed; review the source ambulance identity.');
                $nativeMatches=DB::table('vehicles')->where('company_uuid',$company)->whereNull('deleted_at')->pluck('plate_number')->filter(fn($value)=>$plate($value)===$key)->count();
                if($nativeMatches!==1)return self::unknown('Duplicate native ambulance registrations require identity review.');
                $matched=self::exactPlateId($state['snapshot']['datasets']['ambulances']??[],$key);
                if($matched!==null)$candidates[]=$matched;
            }
            if(count($candidates)!==1)return self::unknown('Source trip identity needs an exact reviewed driver or ambulance connection.');
            $ids[$dataset]=$candidates[0];
        }
        if(!$imported)return null;
        try{$state=$state??\App\MapaMobile\SourceInventoryController::verifiedState($company);}
        catch(\Throwable $e){return self::unknown('Verified source trip evidence is unavailable. Control must reconcile availability.');}
        $vehicleMeta=Policy::meta($vehicle->meta??null);
        if(isset($ids['ambulances'])&&is_string($vehicleMeta['asr_import']['vehicle_key']??null)){
            $matched=self::exactPlateId($state['snapshot']['datasets']['ambulances']??[],$vehicleMeta['asr_import']['vehicle_key']);
            if($matched!==$ids['ambulances'])return self::unknown('The reviewed source identity conflicts with the imported ambulance registration.');
        }
        return self::evaluate($state,$ids,time());
    }
    public static function exactPlateId(array $rows,string $key): ?string {
        $plate=static fn($value)=>preg_replace('/[^A-Z0-9]/','',strtoupper((string)$value));
        $key=$plate($key);if($key==='')return null;$matches=[];
        foreach($rows as $row)if($plate($row['vehicle_number']??'')===$key)$matches[]=(string)$row['id'];
        return count($matches)===1?$matches[0]:null;
    }
    private static function unknown(string $message): array {return ['code'=>'source_trip_unknown','message'=>$message];}
    public static function evaluate(array $state,array $ids,int $now): ?array {
        $s=$state['snapshot']??[];$data=$s['datasets']??[];
        if(($s['complete']??false)!==true||!in_array($s['contract']??null,['mapa-asr-source-v2','mapa-asr-source-v3'],true)
            ||!is_numeric($state['received_at']??null)||$state['received_at']<$now-120||$state['received_at']>$now+60
            ||!is_numeric($s['generated_at']??null)||$s['generated_at']<$now-120||$s['generated_at']>$now+60)
            return self::unknown('Source trip evidence is stale or incomplete; availability needs review.');
        foreach($ids as $dataset=>$id)if(!in_array($id,array_map('strval',array_column($data[$dataset]??[],'id')),true))
            return self::unknown('The reviewed resource is missing from the current source inventory.');
        $matches=static fn($row)=>isset($ids['drivers'])&&(string)($row['driver_id']??'')===$ids['drivers']
            ||isset($ids['ambulances'])&&(string)($row['ambulance_id']??'')===$ids['ambulances'];
        $blocked=['code'=>'source_trip_unresolved','message'=>'An unfinished source trip is linked to this crew or ambulance. Control must reconcile it before dispatch.'];
        // A nonterminal dispatch remains unresolved even if its incident row disagrees.
        foreach($data['cad_dispatches']??[] as $row)if($matches($row)&&!in_array($row['status']??null,['completed','cancelled','canceled','failed'],true))return $blocked;
        $sessions=array_column($data['daily_status']??[],null,'id');
        foreach($data['trip_activity']??[] as $row){
            if(!empty($row['drop_date'])&&!empty($row['drop_time']))continue;
            $session=$sessions[$row['daily_status_id']??'']??null;
            $row['ambulance_id']=$session['ambulance_id']??null;
            if($matches($row))return $blocked;
        }
        return null;
    }
}

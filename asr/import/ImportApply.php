<?php
namespace ASR\Import;
/** Exact transactional resource-only implementation, used by CLI and isolated native tests. */
final class ImportApply
{
    public static function apply(array $plan,string $reviewedDigest): array
    {
    if(config('mapa.company_uuid')!==ImportPlan::COMPANY)throw new \RuntimeException('Target company mismatch.');
    $vehicleLocation=collect(\Illuminate\Support\Facades\DB::select('SHOW COLUMNS FROM `vehicles`'))->firstWhere('Field','location');
    if(!$vehicleLocation || $vehicleLocation->Null!=='YES')throw new \RuntimeException('Vehicle location must permit an unknown SQL NULL.');
    return \Illuminate\Support\Facades\DB::transaction(function()use($plan,$reviewedDigest){
        $company=ImportPlan::COMPANY;$counts=['created_hospitals'=>0,'created_vehicles'=>0,'preserved_hospitals'=>0,'preserved_vehicles'=>0,'deferred_hospitals'=>0,'blocked_vehicles'=>0,
            'drivers_written'=>0,'users_written'=>0,'bindings_written'=>0,'positions_written'=>0];
        \Fleetbase\Models\Company::where('uuid',$company)->lockForUpdate()->firstOrFail();
        $places=\Fleetbase\FleetOps\Models\Place::withoutGlobalScopes()->where('company_uuid',$company)->lockForUpdate()->get();
        $vehicles=\Fleetbase\FleetOps\Models\Vehicle::withoutGlobalScopes()->where('company_uuid',$company)->lockForUpdate()->get();
        $hospitalIds=[];
        foreach($plan['hospitals'] as $record){
            if(!in_array($record['status'],['create','preserve_existing'],true)){$counts['deferred_hospitals']++;continue;}
            $key=$record['key'];$matches=$places->filter(fn($p)=>data_get($p->meta,'asr_import.hospital_key')===$key
                || (ImportPlan::text((string)data_get($p->meta,'asr_import.institution_type'))===ImportPlan::text($record['institution_type'])
                    && ImportPlan::text((string)data_get($p->meta,'asr_import.base_name'))===ImportPlan::text($record['base_name'])));
            if($matches->count()>1 || $matches->contains(fn($p)=>$p->deleted_at!==null || data_get($p->meta,'mapa_demo',false) || data_get($p->meta,'simulated',false)))
                throw new \RuntimeException('Hospital collision changed; stage and review again.');
            if($matches->isNotEmpty()){$hospitalIds[$key]=$matches->first()->uuid;$counts['preserved_hospitals']++;continue;}
            if($record['status']==='preserve_existing')throw new \RuntimeException('Previously matched hospital is unavailable; stage again.');
            if($places->contains(fn($p)=>!data_get($p->meta,'asr_import.hospital_key') && in_array(ImportPlan::text((string)$p->name),[ImportPlan::text($record['base_name']),ImportPlan::text($record['native']['name'])],true)))
                throw new \RuntimeException('Existing hospital needs institution review; no duplicate was created.');
            $native=$record['native'];$place=new \Fleetbase\FleetOps\Models\Place;
            $place->forceFill(['uuid'=>(string)\Illuminate\Support\Str::uuid(),'public_id'=>'place_asr_'.bin2hex(random_bytes(12)),'_key'=>'asr-source-import-v1','company_uuid'=>$company,
                'name'=>$native['name'],'type'=>'hospital','location'=>new \Fleetbase\LaravelMysqlSpatial\Types\Point((float)$record['latitude'],(float)$record['longitude']),
                'meta'=>['mapa_demo'=>false,'asr_import'=>['origin'=>'https://asrhealth.in','hospital_key'=>$key,'institution_type'=>$record['institution_type'],'base_name'=>$record['base_name'],
                    'source_csv_sha256'=>$plan['fingerprints']['csv_sha256'],'coordinate_semantics'=>'source_hospital_or_parking_base_not_live_gps','source_rows'=>$record['source_rows'],'source_context'=>$record['context']??[]]]]);
            $place->saveQuietly();$places->push($place);$hospitalIds[$key]=$place->uuid;$counts['created_hospitals']++;
        }
        foreach($plan['vehicles'] as $record){
            if(!in_array($record['status'],['create','preserve_existing'],true)){$counts['blocked_vehicles']++;continue;}
            $key=$record['key'];$matches=$vehicles->filter(fn($v)=>ImportPlan::plate((string)$v->plate_number)===$key);
            if($matches->count()>1 || $matches->contains(fn($v)=>$v->deleted_at!==null || data_get($v->meta,'mapa_demo',false) || data_get($v->meta,'simulated',false)))throw new \RuntimeException('Vehicle collision changed; stage and review again.');
            if($matches->isNotEmpty()){$counts['preserved_vehicles']++;continue;}
            if($record['status']==='preserve_existing')throw new \RuntimeException('Previously matched vehicle is unavailable; stage again.');
            $native=$record['native'];$vehicle=new \Fleetbase\FleetOps\Models\Vehicle;
            // Omit location completely. Native Point::set(null) would invent Point(0,0).
            $vehicle->forceFill(['uuid'=>(string)\Illuminate\Support\Str::uuid(),'public_id'=>'vehicle_asr_'.bin2hex(random_bytes(12)),'_key'=>'asr-source-import-v1','company_uuid'=>$company,
                'name'=>$native['name'],'plate_number'=>$native['plate_number'],'type'=>$native['type'],'online'=>false,'status'=>'available',
                'meta'=>['mapa_demo'=>false,'asr_import'=>['origin'=>'https://asrhealth.in','vehicle_key'=>$key,'hospital_key'=>$record['hospital_key'],
                    'source_vehicle_type'=>$record['source_vehicle_type'],'source_csv_sha256'=>$plan['fingerprints']['csv_sha256'],'source_rows'=>$record['source_rows'],'source_context'=>$record['context']??[],
                    'coordinate_semantics'=>'no_vehicle_location_imported','import_scope'=>'inventory_only','operational_status_verified'=>false,
                    'source_parking_base_place_uuid'=>$hospitalIds[$record['hospital_key']]??null]]]);
            $vehicle->saveQuietly();
            if(!\Illuminate\Support\Facades\DB::table('vehicles')->where('uuid',$vehicle->uuid)->whereNull('location')->where('online',0)->exists())throw new \RuntimeException('Imported vehicle must remain offline with SQL NULL location.');
            $vehicles->push($vehicle);$counts['created_vehicles']++;
        }
        return ['contract'=>'asr-source-resource-apply-v1','company_uuid'=>$company,'plan_sha256'=>$reviewedDigest,'counts'=>$counts];
    },3);
    }
}

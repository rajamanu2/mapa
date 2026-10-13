<?php
namespace ASR\Import;
/** Shared transactional implementation; CLI catches errors, isolated tests can assert rollback. */
final class SupplementApply
{
    private static function flagged(mixed $value):bool{return in_array($value,[true,1,'1','true','TRUE'],true);}
    public static function apply(array $plan,string $reviewedDigest):array {
        $plan=SupplementPlan::validate($plan);
        if(!preg_match('/^[a-f0-9]{64}$/',$reviewedDigest) || config('mapa.company_uuid')!==ImportPlan::COMPANY)throw new \RuntimeException('Supplement runtime scope mismatch.');
        return \Illuminate\Support\Facades\DB::transaction(function()use($plan,$reviewedDigest){
            $company=ImportPlan::COMPANY;$counts=['created_hospitals'=>0,'replayed_hospitals'=>0,'linked_inventory_metadata'=>0,'replayed_inventory_links'=>0,
                'unresolved_hospitals'=>count($plan['unresolved']??[]),'vehicles_created'=>0,'drivers_written'=>0,'users_written'=>0,'bindings_written'=>0,'positions_written'=>0];
            \Fleetbase\Models\Company::where('uuid',$company)->lockForUpdate()->firstOrFail();
            $places=\Fleetbase\FleetOps\Models\Place::withoutGlobalScopes()->where('company_uuid',$company)->lockForUpdate()->get();
            $vehicles=\Fleetbase\FleetOps\Models\Vehicle::withoutGlobalScopes()->where('company_uuid',$company)->lockForUpdate()->get();
            $hospitalIds=[];
            foreach($plan['hospitals'] as $record){
                $key=$record['hospital_key'];$aliases=array_map([ImportPlan::class,'text'],$record['collision_names']);
                $matches=$places->filter(fn($p)=>data_get($p->meta,'asr_import.hospital_key')===$key);
                if($matches->count()>1)throw new \RuntimeException('Duplicate source hospital identity.');
                if($matches->isNotEmpty()){
                    $place=$matches->first();$point=$place->location;
                    if($place->deleted_at!==null || self::flagged(data_get($place->meta,'mapa_demo')) || self::flagged(data_get($place->meta,'simulated'))
                        || $place->_key!=='asr-source-import-v1' || $place->type!=='hospital' || $place->name!==$record['name'] || $place->street1!==$record['address']
                        || data_get($place->meta,'asr_import.origin')!==$plan['source_origin'] || data_get($place->meta,'asr_import.source_csv_sha256')!==$plan['source_csv_sha256']
                        || data_get($place->meta,'asr_import.web_supplement.plan_sha256')!==$reviewedDigest
                        || !($point instanceof \Fleetbase\LaravelMysqlSpatial\Types\Point) || abs($point->getLat()-$record['latitude'])>=1e-8 || abs($point->getLng()-$record['longitude'])>=1e-8)throw new \RuntimeException('Existing researched hospital changed; review required.');
                    $hospitalIds[$key]=$place->uuid;$counts['replayed_hospitals']++;continue;
                }
                if($places->contains(fn($p)=>in_array(ImportPlan::text((string)$p->name),$aliases,true)
                    || (ImportPlan::text((string)data_get($p->meta,'asr_import.institution_type'))===ImportPlan::text($record['institution_type'])
                        && ImportPlan::text((string)data_get($p->meta,'asr_import.base_name'))===ImportPlan::text($record['base_name']))))throw new \RuntimeException('Native hospital alias or typed identity collision; no duplicate created.');
                $place=new \Fleetbase\FleetOps\Models\Place;
                $place->forceFill(['uuid'=>(string)\Illuminate\Support\Str::uuid(),'public_id'=>'place_asr_'.bin2hex(random_bytes(12)),'_key'=>'asr-source-import-v1','company_uuid'=>$company,
                    'name'=>$record['name'],'street1'=>$record['address'],'type'=>'hospital','location'=>new \Fleetbase\LaravelMysqlSpatial\Types\Point((float)$record['latitude'],(float)$record['longitude']),
                    'meta'=>['mapa_demo'=>false,'simulated'=>false,'asr_import'=>['origin'=>$plan['source_origin'],'hospital_key'=>$key,'institution_type'=>$record['institution_type'],'base_name'=>$record['base_name'],
                        'source_csv_sha256'=>$plan['source_csv_sha256'],'source_rows'=>$record['source_rows'],'source_context'=>$record['source_context'],
                        'coordinate_semantics'=>$record['coordinate_semantics'],'web_supplement'=>['contract'=>'asr-hospital-pin-supplement-v1','plan_sha256'=>$reviewedDigest,'original_plan_sha256'=>$plan['original_plan_sha256'],
                            'evidence_sha256'=>$plan['evidence_sha256'],'google_url'=>$record['google_url'],'official_sources'=>$record['official_sources'],'conflicts'=>$record['conflicts']??[],
                            'confidence'=>$record['confidence']??'','ground_surveyed'=>false,'point_kind'=>'named_facility_pin_not_ambulance_gate']]]]);
                $place->saveQuietly();$places->push($place);$hospitalIds[$key]=$place->uuid;$counts['created_hospitals']++;
            }
            foreach($plan['vehicle_links'] as $link){
                $matches=$vehicles->filter(fn($v)=>ImportPlan::plate((string)$v->plate_number)===$link['vehicle_key']);
                if($matches->count()!==1)throw new \RuntimeException('Reviewed source inventory vehicle missing or duplicated.');
                $vehicle=$matches->first();$meta=$vehicle->meta;$existing=data_get($meta,'asr_import.source_parking_base_place_uuid');$target=$hospitalIds[$link['hospital_key']];
                if($vehicle->deleted_at!==null || $vehicle->_key!=='asr-source-import-v1' || self::flagged(data_get($meta,'mapa_demo')) || self::flagged(data_get($meta,'simulated'))
                    || data_get($meta,'asr_import.origin')!==$plan['source_origin'] || data_get($meta,'asr_import.source_csv_sha256')!==$plan['source_csv_sha256']
                    || data_get($meta,'asr_import.vehicle_key')!==$link['vehicle_key'] || data_get($meta,'asr_import.hospital_key')!==$link['hospital_key']
                    || data_get($meta,'asr_import.import_scope')!=='inventory_only' || data_get($meta,'asr_import.coordinate_semantics')!=='no_vehicle_location_imported')throw new \RuntimeException('Source vehicle provenance baseline changed.');
                if($existing===$target){$counts['replayed_inventory_links']++;continue;}
                if($existing!==null)throw new \RuntimeException('Existing source base link changed; no assignment overwritten.');
                // Change exactly one nested inventory metadata value. Never save
                // a Vehicle model or set location/driver/status/duty fields.
                $written=\Illuminate\Support\Facades\DB::update("UPDATE vehicles SET meta=JSON_SET(meta,'$.asr_import.source_parking_base_place_uuid',?) WHERE company_uuid=? AND uuid=?",[$target,$company,$vehicle->uuid]);
                if($written!==1)throw new \RuntimeException('Inventory metadata link did not commit.');
                $counts['linked_inventory_metadata']++;
            }
            return ['contract'=>'asr-hospital-pin-supplement-apply-v1','company_uuid'=>$company,'plan_sha256'=>$reviewedDigest,'counts'=>$counts];
        });
    }
}

<?php
namespace App\MapaVoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only scoped inventory. A searchable row is never a dispatch authorization. */
class AmbulanceDirectory
{
    private function native(string $table,string $company) {return DB::table($table)->where('company_uuid',$company)->whereNull('deleted_at');}
    private function related(string $table,string $company,string $column,array $ids,array $columns): array {
        $out=[];
        foreach(array_chunk(array_values(array_unique(array_filter($ids,fn($id)=>is_string($id)&&Policy::uuid($id)))),500) as $chunk)
            foreach($this->native($table,$company)->whereIn($column,$chunk)->get($columns) as $row)$out[$row->uuid]=$row;
        return $out;
    }
    public static function text(mixed $value,int $max=200): string {
        return is_string($value)?mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]+/u',' ',$value)??''),0,$max):'';
    }
    private static function key(mixed $id): string {return is_string($id)&&Policy::uuid($id)?$id:'';}
    private static function sameEnvironment(mixed $meta,bool $demo): bool {
        $m=Policy::meta($meta);return Policy::demo($m)===$demo && ($demo || !($m['simulated']??false));
    }
    private static function context(array $meta,array &$mandals,array &$secretariats): void {
        foreach(['mandal'=>'mandals','secretariat'=>'secretariats'] as $key=>$kind){
            $value=self::text($meta[$key]??$meta['asr_'.$key]??null);
            if($value!==''){if($kind==='mandals')$mandals[$value]=true;else $secretariats[$value]=true;}
        }
        $source=$meta['asr_import']['source_context']??[];
        if(!is_array($source))return;
        foreach($source as $item){
            if(!is_array($item))continue;
            $mandal=self::text($item['mandal']??null);$secretariat=self::text($item['secretariat']??null);
            if($mandal!=='')$mandals[$mandal]=true;if($secretariat!=='')$secretariats[$secretariat]=true;
        }
    }
    private static function fields(object $vehicle,?object $driver,?object $user,?object $base,?object $sourceBase,bool $demo): array {
        $vehicleMeta=Policy::meta($vehicle->meta??null);$driverMeta=Policy::meta($driver?->meta??null);
        $mandals=[];$secretariats=[];self::context($vehicleMeta,$mandals,$secretariats);self::context($driverMeta,$mandals,$secretariats);
        foreach([$sourceBase,$base] as $place)if($place)self::context(Policy::meta($place->meta??null),$mandals,$secretariats);
        $sourceMeta=Policy::meta($sourceBase?->meta??null);
        $baseName=self::text($sourceMeta['asr_import']['base_name']??$vehicleMeta['asr_import']['base_name']??$base?->name??$sourceBase?->name??null);
        $hospitalName=self::text($base?->name??$sourceBase?->name??$vehicleMeta['hospital_name']??null);
        $hospitalAddress=self::text($base?->street1??$sourceBase?->street1??null,250);$sourceBaseAddress=self::text($sourceBase?->street1??null,250);
        $name=self::text($user?->name??null);$vehicleName=self::text($vehicle->name??null);$plate=self::text($vehicle->plate_number??null,50);$type=self::text($vehicle->type??null,100);
        $mandal=implode(' / ',array_keys($mandals));$secretariat=implode(' / ',array_keys($secretariats));
        return ['id'=>$driver?->public_id,'driver_id'=>$driver?->public_id,'vehicle_id'=>$vehicle->public_id,'name'=>$name!==''?$name:null,
            'vehicle_name'=>$vehicleName,'vehicle_registration'=>$plate,'vehicle_type'=>$type,'hospital_name'=>$hospitalName,'base_name'=>$baseName,'hospital_address'=>$hospitalAddress,'source_base_address'=>$sourceBaseAddress,
            // Duty is a native resource state, not proof of a current GPS fix or
            // permission to dispatch. Keep it visible for unavailable inventory.
            'vehicle_online'=>(bool)$vehicle->online,'vehicle_status'=>$vehicle->status,
            'crew_on_duty'=>$driver?(bool)$driver->online:null,'crew_status'=>$driver?->status,
            'mandal'=>$mandal,'secretariat'=>$secretariat,'simulated'=>$demo,
            'search_text'=>implode(' ',array_filter([$name,$vehicleName,$plate,$type,$hospitalName,$baseName,$hospitalAddress,$sourceBaseAddress,$mandal,$secretariat]))];
    }
    public static function matches(array $row,string $query): bool {
        // Token AND matching ignores punctuation and spacing within a registration.
        // Human-readable context remains unchanged in the API response.
        $normalize=static function(string $s):string {
            $s=mb_strtolower($s);if(class_exists('Normalizer'))$s=\Normalizer::normalize($s,\Normalizer::FORM_D)?:$s;
            $s=preg_replace('/\p{M}+/u','',$s)??$s;
            return preg_replace('/[^\p{L}\p{N}]+/u','',$s)??'';
        };
        $haystack=$normalize((string)($row['search_text']??''));
        foreach(preg_split('/\s+/u',trim($query))?:[] as $token){$needle=$normalize($token);if($needle!==''&&!str_contains($haystack,$needle))return false;}
        return true;
    }
    public function forCall(object $call,callable $inspect,string $query=''): array {
        $company=$call->company_uuid;$demo=(bool)$call->demo;
        $vehicleColumns=['uuid','public_id','company_uuid','name','plate_number','type','meta','online','status'];
        // The current native schema uses Driver.vehicle_uuid. Retain reciprocal
        // eligibility checks only on a legacy schema that actually has this field.
        if(Schema::hasColumn('vehicles','driver_uuid'))$vehicleColumns[]='driver_uuid';
        $vehicles=$this->native('vehicles',$company)->orderBy('name')->orderBy('uuid')->get($vehicleColumns)
            ->filter(fn($v)=>self::sameEnvironment($v->meta??null,$demo))->values();
        $drivers=$this->related('drivers',$company,'vehicle_uuid',$vehicles->pluck('uuid')->all(),['uuid','public_id','company_uuid','user_uuid','vehicle_uuid','online','status','latitude','longitude','meta',DB::raw('ST_Y(location) AS gps_latitude'),DB::raw('ST_X(location) AS gps_longitude')]);
        $sourceLinkedIds=[];
        if(Schema::hasTable('mapa_source_connections'))foreach(array_chunk([...$vehicles->pluck('uuid')->all(),...array_keys($drivers)],500) as $chunk)
            foreach(DB::table('mapa_source_connections')->where('company_uuid',$company)->whereIn('dataset',['drivers','ambulances'])->whereIn('native_uuid',$chunk)->pluck('native_uuid') as $uuid)
                $sourceLinkedIds[$uuid]=true;
        $users=$this->related('users',$company,'uuid',array_map(fn($d)=>$d->user_uuid,$drivers),['uuid','name']);
        $fixes=[];
        if(Schema::hasTable('mapa_mobile_driver_states'))foreach(array_chunk(array_keys($drivers),500) as $chunk)
            foreach(DB::table('mapa_mobile_driver_states')->where('company_uuid',$company)->whereIn('driver_uuid',$chunk)->get(['driver_uuid','recorded_at']) as $fix)
                $fixes[$fix->driver_uuid]=$fix->recorded_at;
        $linked=[];foreach($drivers as $d)$linked[$d->vehicle_uuid][]=$d;
        $baseByDriver=[];$placeIds=[];
        foreach(array_chunk(array_keys($drivers),500) as $chunk)foreach(DB::table('mapa_mobile_bindings')->where(['company_uuid'=>$company,'role'=>'crew','enabled'=>true])->whereIn('driver_uuid',$chunk)->orderBy('id')->get(['driver_uuid','user_uuid','place_uuid']) as $binding){
            // Duplicate enabled binding/base links are not guessed from arbitrary rows.
            if(($drivers[$binding->driver_uuid]->user_uuid??null)!==$binding->user_uuid)continue;
            $baseByDriver[$binding->driver_uuid][]=$binding->place_uuid;
        }
        foreach($drivers as $d){$meta=Policy::meta($d->meta??null);$placeIds[]=$meta['hospital_place_uuid']??null;foreach($baseByDriver[$d->uuid]??[] as $id)$placeIds[]=$id;}
        foreach($vehicles as $v){$meta=Policy::meta($v->meta??null);$placeIds[]=$meta['asr_import']['source_parking_base_place_uuid']??null;}
        $places=$this->related('places',$company,'uuid',$placeIds,['uuid','public_id','name','street1','meta']);
        $places=array_filter($places,fn($p)=>self::sameEnvironment($p->meta??null,$demo));
        $review=Policy::meta($call->review??null);$ready=$call->state==='ready' && $review!==[];$candidates=[];$unavailable=[];
        foreach($vehicles as $vehicle){
            $links=$linked[$vehicle->uuid]??[];$driver=count($links)===1?$links[0]:null;
            $meta=Policy::meta($vehicle->meta??null);$driverMeta=Policy::meta($driver?->meta??null);
            $sourceOwned=isset($meta['asr_import'])||isset($meta['asr_source_id'])||isset($meta['mapa_source_id'])||isset($sourceLinkedIds[$vehicle->uuid])
                ||isset($driverMeta['asr_import'])||isset($driverMeta['asr_source_id'])||isset($driverMeta['mapa_source_id'])||($driver&&isset($sourceLinkedIds[$driver->uuid]));
            $bindingBases=$driver?array_values(array_unique(array_filter($baseByDriver[$driver->uuid]??[]))):[];
            $baseId=count($bindingBases)===1?$bindingBases[0]:($bindingBases===[]?($driverMeta['hospital_place_uuid']??null):null);
            $base=$places[self::key($baseId)]??null;$sourceBase=$places[self::key($meta['asr_import']['source_parking_base_place_uuid']??null)]??null;
            // An invalid cross-environment link remains blocked, but its crew
            // identity and private base context never enter this call's display.
            $displayDriver=$driver&&Policy::allowedResource($demo,$driver->meta??null,$vehicle->meta??null)?$driver:null;
            $row=self::fields($vehicle,$displayDriver,$displayDriver?($users[$displayDriver->user_uuid]??null):null,$displayDriver?$base:null,$sourceBase,$demo);
            // Only an enabled, correctly bound crew membership supplies an exact
            // hospital identity. A name or imported parking base is insufficient.
            $row['hospital_place_id']=$displayDriver&&count($bindingBases)===1?$base?->public_id:null;
            $fixAt=$displayDriver?$fixes[$displayDriver->uuid]??null:null;
            $hasFix=$displayDriver&&Policy::coordinates($displayDriver->gps_latitude??null,$displayDriver->gps_longitude??null)&&is_numeric($fixAt);
            $row['crew_gps_status']=!$hasFix?'missing':(Policy::fresh($fixAt,(int)round(microtime(true)*1000),(int)config('mapa_voice.max_gps_age_seconds',180))?'fresh':'stale');
            $row['crew_gps_recorded_at']=$hasFix?(int)$fixAt:null;
            $row['crew_link_status']=count($links)>1?'ambiguous':($displayDriver?'linked':'unlinked');
            $row['crew_mobile_bound']=$displayDriver&&isset($baseByDriver[$displayDriver->uuid]);
            $row['source_imported']=$sourceOwned;
            $row['dispatch_authority']=\App\MapaMobile\DispatchAuthority::mapaControls()?'mapa':($sourceOwned?'asr':'mapa');
            $sourceOwned=$sourceOwned&&!\App\MapaMobile\DispatchAuthority::mapaControls();
            $row['source_owned']=$sourceOwned;
            if(!$ready)$state=['candidate'=>null,'code'=>in_array($call->state,['dispatched','dismissed'],true)?'call_resolved':'review_required','message'=>'Review and confirm the call before checking dispatch eligibility.'];
            elseif($sourceOwned)$state=['candidate'=>null,'code'=>'source_owned','message'=>'ASR manages this ambulance and its dispatch. MAPA shows its source GPS and trip tracking only.'];
            elseif(count($links)>1)$state=['candidate'=>null,'code'=>'assignment','message'=>'Multiple crew records link to this ambulance. Correct the crew-to-ambulance link in Resources.'];
            elseif(!$driver)$state=['candidate'=>null,'code'=>'crew_unlinked','message'=>'No crew is linked to this ambulance. Approve and link its real crew account in control.'];
            else $state=$inspect($driver,$vehicle,$call,$review);
            if($state['candidate']!==null){$row=[...$row,...$state['candidate']];if(self::matches($row,$query))$candidates[]=$row;}
            else { $row=[...$row,'code'=>$state['code'],'message'=>$state['message'],'order_id'=>$state['order_id']??null,'order_status'=>$state['order_status']??null];if(self::matches($row,$query))$unavailable[]=$row; }
        }
        usort($candidates,fn($a,$b)=>($a['distance_km']<=>$b['distance_km'])?:strcmp($a['id'],$b['id']));
        return ['candidates'=>$candidates,'unavailable'=>$unavailable,'inventory_total'=>$vehicles->count(),'matching_total'=>count($candidates)+count($unavailable)];
    }
}

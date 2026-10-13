<?php
namespace App\MapaMobile;

use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** All callers hold the native vehicle row lock in the same transaction.
 * Source fixes stay in their own histories; only newer fixes move the shared map.
 */
final class VehiclePositionProjection
{
    public static function apply(Vehicle $vehicle,array $position,int $recordedAt,string $source,string $sourceId): bool
    {
        if(DB::transactionLevel() < 1) throw new \LogicException('Vehicle projection requires a locked transaction.');
        if(!in_array($source,['crew_phone','hardware_gps'],true))throw new \InvalidArgumentException('Unknown GPS projection source.');
        if(!Schema::hasTable('mapa_vehicle_position_states')){
            // Preserve deployed phone-only operation until the additive migration.
            if($source!=='crew_phone') MobileService::fail('GPS projection migration is required.',503);
            $vehicle->updateQuietly($position);$vehicle->createPosition($position);
            return true;
        }
        $key=['company_uuid'=>$vehicle->company_uuid,'vehicle_uuid'=>$vehicle->uuid];
        $state=DB::table('mapa_vehicle_position_states')->where($key)->lockForUpdate()->first();
        if(!$state){
            // One-time bootstrap protects pre-migration phone/device fixes. This
            // JSON scan happens once per vehicle, never on every hardware sample.
            $phone=DB::table('mapa_mobile_receipts')->where('company_uuid',$vehicle->company_uuid)
                ->where('result->gps_evidence->vehicle_id',$vehicle->public_id)
                ->selectRaw("MAX(CAST(JSON_UNQUOTE(JSON_EXTRACT(result, '$.gps_evidence.recorded_at')) AS UNSIGNED)) AS recorded_at")->first();
            $hardware=Schema::hasTable('mapa_device_positions')
                ? (int)DB::table('mapa_device_positions')->where($key)->max('recorded_at') : 0;
            $previous=max((int)($phone->recorded_at??0),$hardware);
            DB::table('mapa_vehicle_position_states')->insert([...$key,'recorded_at'=>$previous,
                'source'=>'legacy','source_id'=>null,'created_at'=>now(),'updated_at'=>now()]);
        }else{$previous=(int)$state->recorded_at;}
        // Equal timestamps also retain the existing projection deterministically.
        if($recordedAt <= $previous)return false;
        $vehicle->updateQuietly($position);$vehicle->createPosition($position);
        DB::table('mapa_vehicle_position_states')->where($key)->update(['recorded_at'=>$recordedAt,
            'source'=>$source,'source_id'=>$sourceId,'updated_at'=>now()]);
        return true;
    }
}

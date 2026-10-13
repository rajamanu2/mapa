<?php
namespace App\MapaMobile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
class SourceProfileController
{
    public function show(Request $request,string $dataset,string $id){
        try{
            $context=(new ConsoleEnrollmentService(new PrivateMedia))->context($request);
            if($request->query()||!in_array($dataset,['drivers','ambulances'],true)||!preg_match('/^[1-9][0-9]{0,9}$/D',$id))MobileService::fail('Invalid source profile.',422);
            $state=SourceInventoryController::verifiedState($context['company']);
            $profile=SourceProfiles::build($state['snapshot'],$dataset,$id);
            if(!$profile)MobileService::fail('Source record not found.',404);
            if($dataset==='ambulances') {
                $profile['tracking_tile']=null;
                $link=Schema::hasTable('mapa_source_connections')?DB::table('mapa_source_connections')->where(['company_uuid'=>$context['company'],'dataset'=>'ambulances','source_id'=>$id,'active'=>true])->first():null;
                $vehicle=$link?DB::table('vehicles')->where(['uuid'=>$link->native_uuid,'company_uuid'=>$context['company']])->whereNull('deleted_at')->first():null;
                $plate=static fn($value)=>strtoupper(preg_replace('/[^A-Za-z0-9]/','',(string)$value));
                if($vehicle && $plate($vehicle->plate_number)!=='' && $plate($vehicle->plate_number)===$plate($profile['record']['vehicle_number'])) {
                    $drivers=DB::table('drivers')->where(['company_uuid'=>$context['company'],'vehicle_uuid'=>$vehicle->uuid])->whereNull('deleted_at')->get();
                    $driver=$drivers->count()===1?$drivers->first():null;
                    $name=$driver?DB::table('users')->where(['uuid'=>$driver->user_uuid,'company_uuid'=>$context['company']])->whereNull('deleted_at')->value('name'):null;
                    $profile['tracking_tile']=['vehicle_id'=>$vehicle->public_id,'registration'=>$vehicle->plate_number,'driver_id'=>$driver?->public_id,'driver_name'=>$name,
                        'locations'=>(new ConnectedTrackingService)->vehicleLocationSources($context['company'],$vehicle,$driver?->uuid)];
                }
            }
            return response()->json(['contract'=>$state['snapshot']['contract'],'company_id'=>$context['company'],'received_at'=>$state['received_at'],'stale'=>time()-(int)$state['received_at']>120]+$profile)->header('Cache-Control','no-store, private');
        }catch(HttpExceptionInterface $e){return response()->json(['error'=>$e->getMessage()],$e->getStatusCode())->header('Cache-Control','no-store');}
        catch(\Throwable $e){return response()->json(['error'=>'Source profile is unavailable.'],503)->header('Cache-Control','no-store');}
    }
}

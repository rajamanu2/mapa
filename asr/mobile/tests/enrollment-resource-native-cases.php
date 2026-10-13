<?php
// Called only by the dedicated synthetic native harness, never a live connection.
$resourceAmbulanceBody=['action_id'=>(string)Str::uuid(),'expected_revision'=>1,'name'=>'DEMO isolated reviewed ambulance','registration'=>$signup['ambulance_registration'],'details_confirmed'=>true];
$resourceHospitalBody=['action_id'=>(string)Str::uuid(),'expected_revision'=>1,'name'=>'DEMO isolated reviewed hospital','address'=>'DEMO isolated Paderu base address',
    'latitude'=>18.2201,'longitude'=>82.8802,'location_confirmed'=>true];
$resourceCounts=fn()=>array_map(fn($t)=>DB::table($t)->count(),['users','drivers','mapa_mobile_bindings','mapa_mobile_sessions','positions','orders']);
$beforeResourceCounts=$resourceCounts();$beforeResourceVehicles=DB::table('vehicles')->count();$beforeResourcePlaces=DB::table('places')->count();
denied(fn()=>$enrollService->createResource(req($resourceAmbulanceBody),$enrollmentId,'ambulance'),401);
denied(fn()=>$enrollService->createResource(req($resourceAmbulanceBody,$connectSecret),$enrollmentId,'ambulance'),401);
denied(fn()=>$enrollService->createResource(req($resourceAmbulanceBody,$token),$enrollmentId,'ambulance'),403);
denied(fn()=>$enrollService->createResource(req($resourceAmbulanceBody,$foreignSecret),$enrollmentId,'ambulance'),401);
$foreignEnrollment=(string)Str::uuid();$foreignFixture=(array)$stored;$foreignFixture['id']=$foreignEnrollment;$foreignFixture['company_uuid']=$foreign;$foreignFixture['connect_hash']=hash('sha256',bin2hex(random_bytes(32)));
DB::table('mapa_mobile_enrollments')->insert($foreignFixture);
denied(fn()=>$enrollService->createResource(req($resourceAmbulanceBody,$staffToken),$foreignEnrollment,'ambulance'),404);
DB::table('mapa_mobile_enrollments')->where('id',$foreignEnrollment)->delete();
foreach(['company_uuid'=>$foreign,'online'=>true,'meta'=>['mapa_demo'=>false],'driver_uuid'=>$driver,'location'=>[18.11,82.71]] as $key=>$value)
    denied(fn()=>$enrollService->createResource(req([...$resourceAmbulanceBody,$key=>$value],$staffToken),$enrollmentId,'ambulance'),422);
denied(fn()=>$enrollService->createResource(req([...$resourceAmbulanceBody,'details_confirmed'=>false],$staffToken),$enrollmentId,'ambulance'),422);
denied(fn()=>$enrollService->createResource(req([...$resourceAmbulanceBody,'registration'=>'DEMO different plate'],$staffToken),$enrollmentId,'ambulance'),409);
denied(fn()=>$enrollService->createResource(req([...$resourceAmbulanceBody,'expected_revision'=>2],$staffToken),$enrollmentId,'ambulance'),409);
$oldResourceExpiry=$stored->connect_expires_at;DB::table('mapa_mobile_enrollments')->where('id',$enrollmentId)->update(['connect_expires_at'=>1]);
denied(fn()=>$enrollService->createResource(req($resourceAmbulanceBody,$staffToken),$enrollmentId,'ambulance'),409);
DB::table('mapa_mobile_enrollments')->where('id',$enrollmentId)->update(['connect_expires_at'=>$oldResourceExpiry]);
check(DB::table('vehicles')->count()===$beforeResourceVehicles && DB::table('places')->count()===$beforeResourcePlaces,'invalid or unauthorized creation leaves native catalog untouched');
$resourceVehicleResponse=$signupController->ambulance(req($resourceAmbulanceBody,$staffToken),$enrollmentId);
check($resourceVehicleResponse->getStatusCode()===200,'staff controller creates reviewed native ambulance');
$resourceVehicleResult=json_decode($resourceVehicleResponse->getContent(),true);
$resourceVehicleUuid=DB::table('vehicles')->where('public_id',$resourceVehicleResult['resource']['id'])->value('uuid');
$createdVehicle=Fleetbase\FleetOps\Models\Vehicle::withoutGlobalScopes()->where('uuid',$resourceVehicleUuid)->first();
check($createdVehicle->company_uuid===$company && $createdVehicle->online===false && $createdVehicle->location===null && DB::table('vehicles')->where('uuid',$resourceVehicleUuid)->whereNull('location')->exists(),'created ambulance exact company offline with unknown location, no fabricated GPS');
check($createdVehicle->type==='ambulance' && $createdVehicle->plate_number===$resourceAmbulanceBody['registration'],'created native name/plate/type match confirmed input');
check($resourceVehicleResult['enrollment']['status']==='pending' && $resourceVehicleResult['enrollment']['revision']===1,'catalog creation leaves enrollment pending and revision unchanged');
check($resourceCounts()===$beforeResourceCounts,'resource creation adds no users/drivers/bindings/sessions/positions/orders');
$vehicleReplay=$enrollService->createResource(req($resourceAmbulanceBody,$staffToken),$enrollmentId,'ambulance');
check($vehicleReplay['replayed'] && $vehicleReplay['resource']['id']===$resourceVehicleResult['resource']['id'] && DB::table('vehicles')->count()===$beforeResourceVehicles+1,'exact lost-response retry returns same resource and one vehicle');
denied(fn()=>$enrollService->createResource(req([...$resourceAmbulanceBody,'name'=>'DEMO altered action'],$staffToken),$enrollmentId,'ambulance'),409);
denied(fn()=>$enrollService->createResource(req([...$resourceAmbulanceBody,'action_id'=>(string)Str::uuid(),'registration'=>strtolower(str_replace('-',' . ',$resourceAmbulanceBody['registration']))],$staffToken),$enrollmentId,'ambulance'),409);
DB::table('vehicles')->where('uuid',$resourceVehicleUuid)->update(['deleted_at'=>now()]);
denied(fn()=>$enrollService->createResource(req([...$resourceAmbulanceBody,'action_id'=>(string)Str::uuid()],$staffToken),$enrollmentId,'ambulance'),409);
DB::table('vehicles')->where('uuid',$resourceVehicleUuid)->update(['deleted_at'=>null]);
foreach([['location_confirmed'=>false],['latitude'=>0,'longitude'=>0],['latitude'=>91],['longitude'=>181],['address'=>' '],['company_uuid'=>$foreign],['meta'=>['mapa_demo'=>false]],['online'=>true]] as $extra)
    check($signupController->hospital(req([...$resourceHospitalBody,...$extra],$staffToken),$enrollmentId)->getStatusCode()===422,'invalid hospital/base input returns definitive validation rejection');
$resourceHospitalResponse=$signupController->hospital(req($resourceHospitalBody,$staffToken),$enrollmentId);
check($resourceHospitalResponse->getStatusCode()===200,'staff controller creates verified native hospital/base');
$resourceHospitalResult=json_decode($resourceHospitalResponse->getContent(),true);
$resourcePlaceUuid=DB::table('places')->where('public_id',$resourceHospitalResult['resource']['id'])->value('uuid');
$createdPlace=Fleetbase\FleetOps\Models\Place::withoutGlobalScopes()->where('uuid',$resourcePlaceUuid)->first();
check($createdPlace->company_uuid===$company && $createdPlace->street1===$resourceHospitalBody['address'],'hospital/base uses exact confirmed company/address');
check(abs($createdPlace->location->getLat()-$resourceHospitalBody['latitude'])<0.0000001 && abs($createdPlace->location->getLng()-$resourceHospitalBody['longitude'])<0.0000001,'native base coordinates use operator map input, not registration GPS');
check(data_get($createdPlace->meta,'location_source')==='operator_confirmed_map_pin','base coordinate provenance is operator confirmed');
check($enrollService->createResource(req($resourceHospitalBody,$staffToken),$enrollmentId,'hospital')['replayed'],'base creation immutable exact replay');
denied(fn()=>$enrollService->createResource(req([...$resourceHospitalBody,'action_id'=>(string)Str::uuid(),'name'=>strtoupper($resourceHospitalBody['name']),'address'=>'  '.strtoupper($resourceHospitalBody['address']).'  ','latitude'=>18.9],$staffToken),$enrollmentId,'hospital'),409);
$beforeFailedPlaces=DB::table('places')->count();$beforeFailedReceipts=DB::table('mapa_mobile_enrollment_audit')->count();
$forceResourceAuditFailure=true;
DB::listen(function($query)use(&$forceResourceAuditFailure){if($forceResourceAuditFailure && str_starts_with(strtolower($query->sql),'insert into') && str_contains($query->sql,'mapa_mobile_enrollment_audit'))throw new RuntimeException('DEMO injected resource receipt failure');});
$rollbackBody=[...$resourceHospitalBody,'action_id'=>(string)Str::uuid(),'name'=>'DEMO rollback-only base'];
try{$enrollService->createResource(req($rollbackBody,$staffToken),$enrollmentId,'hospital');throw new RuntimeException('DEMO expected atomic resource rollback');}
catch(RuntimeException $error){check($error->getMessage()==='DEMO injected resource receipt failure','receipt failure propagated without partial success');}
finally{$forceResourceAuditFailure=false;}
check(DB::table('places')->count()===$beforeFailedPlaces && DB::table('mapa_mobile_enrollment_audit')->count()===$beforeFailedReceipts,'native catalog and action receipt roll back atomically');
$resourceOptions=$enrollService->options(req([],$staffToken));
check(collect($resourceOptions['vehicles'])->contains('id',$resourceVehicleResult['resource']['id']) && collect($resourceOptions['places'])->contains('id',$resourceHospitalResult['resource']['id']),'new IDs appear in scoped review options before separate approval');
check($resourceCounts()===$beforeResourceCounts && DB::table('mapa_mobile_enrollments')->where('id',$enrollmentId)->value('status')==='pending','hospital/base and ambulance creation never authorizes crew or starts duty');

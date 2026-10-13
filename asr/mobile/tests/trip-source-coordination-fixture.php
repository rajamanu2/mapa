<?php
// Pre-existing confirmed ownership fixture for the real TripWorkflow path.
// Reservation HTTP and pre-transaction persistence are tested in the saga suite.
if(!isset($mapaDedicatedQaApp))throw new RuntimeException('Dedicated QA required for coordinated native activity.');
$tripCoordPrior=[config('mapa_mobile.source_dispatch_coordination_enabled'),config('mapa_mobile.source_sync_enabled')];
$tripCoordDir=storage_path('app/mapa-source-sync');@mkdir($tripCoordDir,0700,true);$tripCoordFile=$tripCoordDir.'/state.json';$tripCoordPrevious=is_file($tripCoordFile)?file_get_contents($tripCoordFile):null;
$tripCoordSnapshot=['contract'=>\MapaSource\SourceSync::CONTRACT,'source'=>'https://asrhealth.in','company_id'=>$company,'complete'=>true,'generated_at'=>microtime(true),'source_date'=>'2026-10-06','datasets'=>array_fill_keys(array_keys(\MapaSource\SourceSync::FIELDS),[])];
$tripSourceDriver=array_replace(array_fill_keys(\MapaSource\SourceSync::FIELDS['drivers'],null),['id'=>'1','name'=>'DEMO source trip driver']);
$tripSourceVehicle=array_replace(array_fill_keys(\MapaSource\SourceSync::FIELDS['ambulances'],null),['id'=>'1','vehicle_number'=>'DEMO source trip vehicle']);
$tripCoordSnapshot['datasets']['drivers']=[$tripSourceDriver];$tripCoordSnapshot['datasets']['ambulances']=[$tripSourceVehicle];\MapaSource\SourceSync::validate($tripCoordSnapshot,time());
file_put_contents($tripCoordFile,json_encode(['snapshot'=>$tripCoordSnapshot,'received_at'=>time(),'changes'=>[]]));
foreach(['drivers'=>[$driver,$tripSourceDriver],'ambulances'=>[$vehicle,$tripSourceVehicle]] as $dataset=>[$nativeUuid,$sourceRow])DB::table('mapa_source_connections')->insert(['company_uuid'=>$company,'dataset'=>$dataset,'source_id'=>'1','native_uuid'=>$nativeUuid,'active'=>true,'revision'=>1,'source_fingerprint'=>\App\MapaMobile\SourceConnections::fingerprint($sourceRow),'source_contract'=>\MapaSource\SourceSync::CONTRACT,'source_generation'=>str_repeat('a',64),'reviewed_by'=>$user,'reason'=>'DEMO fixture reviewed identity','created_at'=>now(),'updated_at'=>now()]);
$tripCoordContext=$service->context(req([],$token));$tripCoordJourney=(string)Str::uuid();
DB::table('mapa_source_journeys')->insert(['journey_id'=>$tripCoordJourney,'company_uuid'=>$company,'order_uuid'=>$trip,'driver_uuid'=>$driver,'vehicle_uuid'=>$vehicle,'source_driver_id'=>'1','source_ambulance_id'=>'1','driver_link_revision'=>1,'vehicle_link_revision'=>1,'actor_uuid'=>$user,'binding_id'=>$tripCoordContext['binding']->id,'binding_revision'=>$tripCoordContext['binding']->revision,'intent_hash'=>str_repeat('b',64),'reserve_command'=>'{}','state'=>'confirmed','reservation'=>json_encode(['status'=>'reserved','version'=>1,'created_at'=>'2026-10-06 00:00:00.000000','terminal_at'=>null]),'created_at'=>now(),'updated_at'=>now()]);
config(['mapa_mobile.source_dispatch_coordination_enabled'=>true,'mapa_mobile.source_sync_enabled'=>true]);

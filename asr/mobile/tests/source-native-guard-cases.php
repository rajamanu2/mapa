<?php
if(!isset($mapaDedicatedQaApp,$company))throw new RuntimeException('Isolated native QA required.');
$guardPrior=config('mapa_mobile.source_dispatch_coordination_enabled');
$authorityPrior=config('mapa_mobile.dispatch_authority');
config(['mapa_mobile.dispatch_authority'=>'asr']);
$guard=new App\MapaMobile\SourceNativeWriteGuard;
$next=fn($request)=>response()->json(['allowed'=>true]);
config(['mapa_mobile.source_dispatch_coordination_enabled'=>true]);
foreach(['/api/int/v1/orders','/api/v1/drivers/DEMO','/int/v1/vehicles','/v1/fleets','/api/int/v1/%6frders'] as $path)
    check($guard->handle(Illuminate\Http\Request::create($path,'POST'),$next)->getStatusCode()===409,'coordinated guard rejects alternate fleet write '.$path);
foreach([['/api/int/v1/orders','GET'],['/api/int/v1/orders','OPTIONS'],['/api/int/v1/mapa/source-connections','POST']] as [$path,$method])
    check($guard->handle(Illuminate\Http\Request::create($path,$method),$next)->getStatusCode()===200,'coordinated guard retains reviewed/read route');
$observer=new App\MapaMobile\SourceNativeAssignmentObserver;
$guardOrder=new Fleetbase\FleetOps\Models\Order;
denied(fn()=>$observer->creating($guardOrder),409);
$guardOrder->setRawAttributes(['uuid'=>(string)Illuminate\Support\Str::uuid(),'company_uuid'=>$company,'status'=>'created'],true);
$guardOrder->status='started';denied(fn()=>$observer->updating($guardOrder),409);
$guardDriver=new Fleetbase\FleetOps\Models\Driver;
$guardUuid=(string)Illuminate\Support\Str::uuid();$guardJourney=(string)Illuminate\Support\Str::uuid();
$guardDriver->setRawAttributes(['uuid'=>$guardUuid,'company_uuid'=>$company,'vehicle_uuid'=>null],true);
$guardDriver->vehicle_uuid=(string)Illuminate\Support\Str::uuid();
denied(fn()=>$observer->updating($guardDriver),409);
$guardDriver->setRawAttributes(['uuid'=>$guardUuid,'company_uuid'=>$company,'vehicle_uuid'=>null],true);
DB::table('mapa_source_journeys')->insert(['journey_id'=>$guardJourney,'company_uuid'=>$company,'driver_uuid'=>$guardUuid,'vehicle_uuid'=>(string)Illuminate\Support\Str::uuid(),'source_driver_id'=>'1','source_ambulance_id'=>'1','state'=>'unknown','intent_hash'=>str_repeat('a',64),'driver_link_revision'=>1,'vehicle_link_revision'=>1,'actor_uuid'=>(string)Illuminate\Support\Str::uuid(),'binding_id'=>(string)Illuminate\Support\Str::uuid(),'binding_revision'=>1,'reserve_command'=>'{}','terminal_at'=>null,'created_at'=>now(),'updated_at'=>now()]);
try{
    $guardDriver->company_uuid=(string)Illuminate\Support\Str::uuid();$guardDriver->uuid=(string)Illuminate\Support\Str::uuid();
    denied(fn()=>$observer->updating($guardDriver),409);
    denied(fn()=>$observer->deleting($guardDriver),409);
    $guardDriver->setRawAttributes(['uuid'=>$guardUuid,'company_uuid'=>$company,'vehicle_uuid'=>null],true);
    $guardDriver->online=true;$observer->updating($guardDriver);check(true,'held driver heartbeat remains writable');
    DB::table('mapa_source_journeys')->where('journey_id',$guardJourney)->update(['state'=>'rejected']);
    $guardDriver->vehicle_uuid=(string)Illuminate\Support\Str::uuid();denied(fn()=>$observer->updating($guardDriver),409);
    config(['mapa_mobile.source_dispatch_coordination_enabled'=>false]);
    $observer->creating($guardOrder);$observer->updating($guardDriver);
    check($guard->handle(Illuminate\Http\Request::create('/api/int/v1/orders','POST'),$next)->getStatusCode()===200,'disabled coordination preserves existing fleet workflow');
    config(['mapa_mobile.dispatch_authority'=>'mapa']);
    $guardOrder->company_uuid=$company;
    denied(fn()=>$observer->creating($guardOrder),409);
    denied(fn()=>$observer->updating($guardDriver),409);
    denied(fn()=>$observer->deleting($guardDriver),409);
    DB::table('mapa_source_journeys')->where('journey_id',$guardJourney)->update(['terminal_at'=>now()]);
    $observer->creating($guardOrder);$observer->updating($guardDriver);
    check(true,'MAPA permits native operational changes only after old ownership is reconciled');
}finally{
    DB::table('mapa_source_journeys')->where('journey_id',$guardJourney)->delete();
    config(['mapa_mobile.source_dispatch_coordination_enabled'=>$guardPrior]);
    config(['mapa_mobile.dispatch_authority'=>$authorityPrior]);
}

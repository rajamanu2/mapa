<?php
// Existing disposable journey fixtures only. Seed exact-endpoint route cache; no HTTP.
$roadSettings=config('mapa_mobile.road_routing');
$roadDevice=(string)Illuminate\Support\Str::uuid();
$roadNow=(int)round(microtime(true)*1000);
$roadFrom=['latitude'=>18.32,'longitude'=>82.85];
$roadPickup=['latitude'=>18.33,'longitude'=>82.87];
$roadHospital=['latitude'=>18.36,'longitude'=>82.91];
$roadKeys=[];
try {
    Illuminate\Support\Facades\DB::table('mapa_tracking_devices')->insert([
        'id'=>$roadDevice,'company_uuid'=>$company,'vehicle_uuid'=>$jv,'external_id'=>'DEMO-route-hook',
        'label'=>'DEMO route hook tracker','secret_hash'=>hash('sha256','DEMO-no-device-credential'),
        'created_by'=>$ju,'created_at'=>now(),'updated_at'=>now()]);
    Illuminate\Support\Facades\DB::table('mapa_device_positions')->insert([
        'device_id'=>$roadDevice,'company_uuid'=>$company,'vehicle_uuid'=>$jv,...$roadFrom,
        'accuracy_m'=>8,'recorded_at'=>$roadNow,'created_at'=>now(),'updated_at'=>now()]);
    Illuminate\Support\Facades\DB::table('places')->where('uuid',$jd)->update([
        'location'=>Illuminate\Support\Facades\DB::raw("ST_GeomFromText('POINT(82.91 18.36)',0)")]);
    config(['mapa_mobile.road_routing'=>['enabled'=>false,'origin'=>App\MapaMobile\PrivateRoadRoute::ORIGIN]]);
    $roadDisabled=$jview();
    check($roadDisabled['ambulance']['location']['source']==='hardware_gps'&&$roadDisabled['planned_road_route']===null,'routing stays disabled despite fresh assigned hardware fix');
    $roadRoutes=[];
    foreach(['pickup'=>$roadPickup,'hospital'=>$roadHospital] as $leg=>$target){
        $roadRoutes[$leg]=App\MapaMobile\PrivateRoadRoute::validate(['code'=>'Ok','routes'=>[[
            'distance'=>8000,'duration'=>1000,'geometry'=>['type'=>'LineString','coordinates'=>[
                [$roadFrom['longitude'],$roadFrom['latitude']],[82.86,18.325],[$target['longitude'],$target['latitude']]]]
        ]]],$roadFrom,$target,$roadNow);
        $key='mapa:private-road:'.hash('sha256',json_encode([$roadFrom['latitude'],$roadFrom['longitude'],$target['latitude'],$target['longitude']]));
        $roadKeys[]=$key;
        Illuminate\Support\Facades\Cache::put($key,['route'=>$roadRoutes[$leg]],120);
    }
    config(['mapa_mobile.road_routing.enabled'=>true]);
    $roadView=$jview();$route=$roadView['planned_road_route'];
    check($route!==null&&$route['coordinates']===$roadRoutes['pickup']['coordinates'],'enabled hook uses cached road geometry for exact assigned ambulance and native pickup');
    check($route['leg']==='pickup'&&$route['context_revision']===$roadView['revision']&&$route['source_recorded_at']===$roadNow,'pickup road envelope binds leg, current facts revision and ambulance timestamp');
    check($route['source']==='private_osrm'&&$route['generated_at']===$roadNow&&$route['expires_at']===$roadNow+60000,'adapter provenance and millisecond TTL retained');
    check($roadView['revision']===$roadDisabled['revision'],'route presence and generated time cannot create revision feedback');
    check($route['coordinates'][2]===[$roadPickup['longitude'],$roadPickup['latitude']]
        &&$roadView['caller_pickup_confirmation']['latitude']===$journeyCandidate['latitude'],'road targets authoritative pickup instead of unaccepted caller candidate');
    $roadStaff=App\MapaMobile\TripTrackingView::forOrder($jo,$company);
    check($roadStaff['planned_road_route']===$route&&$roadStaff['revision']===$roadView['revision'],'authorized staff and caller get identical same-facts road envelope');

    Illuminate\Support\Facades\DB::table('mapa_device_positions')->where('device_id',$roadDevice)->update(['recorded_at'=>$roadNow+1]);
    $roadLater=$jview();
    check($roadLater['planned_road_route']['source_recorded_at']===$roadNow+1&&$roadLater['revision']!==$roadView['revision']
        &&$roadLater['planned_road_route']['context_revision']===$roadLater['revision'],'reused geometry is rebound to latest accepted ambulance fix and facts');
    Illuminate\Support\Facades\DB::table('mapa_device_positions')->where('device_id',$roadDevice)->update(['recorded_at'=>$roadNow-600000]);
    check($jview()['planned_road_route']===null,'stale hardware hides cached geometry');
    Illuminate\Support\Facades\DB::table('mapa_device_positions')->where('device_id',$roadDevice)->update(['recorded_at'=>$roadNow]);
    Illuminate\Support\Facades\DB::table('mapa_tracking_devices')->where('id',$roadDevice)->update(['company_uuid'=>$foreign]);
    check($jview()['planned_road_route']===null,'foreign device ownership cannot provide assigned ambulance route');
    Illuminate\Support\Facades\DB::table('mapa_tracking_devices')->where('id',$roadDevice)->update(['company_uuid'=>$company]);

    Illuminate\Support\Facades\DB::table('orders')->where('uuid',$jo)->update(['status'=>'enroute']);
    $roadHospitalView=$jview();$route=$roadHospitalView['planned_road_route'];
    check($route['leg']==='hospital'&&$route['coordinates']===$roadRoutes['hospital']['coordinates'],'patient pickup switches route to authoritative hospital endpoint');
    check($route['context_revision']===$roadHospitalView['revision']&&$route['context_revision']!==$roadView['revision'],'hospital leg geometry binds new trip context');
    Illuminate\Support\Facades\DB::table('places')->where('uuid',$jd)->update(['company_uuid'=>$foreign]);
    check($jview()['planned_road_route']===null,'foreign hospital prevents route projection despite cached geometry');
    Illuminate\Support\Facades\DB::table('places')->where('uuid',$jd)->update(['company_uuid'=>$company]);
    Illuminate\Support\Facades\DB::table('orders')->where('uuid',$jo)->update(['status'=>'completed']);
    check(App\MapaMobile\TripTrackingView::forOrder($jo,$company)['planned_road_route']===null,'completed trip removes road geometry');
    check(App\MapaMobile\TripTrackingView::forOrder(null,$company)['planned_road_route']===null,'unassigned trip has no road geometry');
} finally {
    config(['mapa_mobile.road_routing'=>$roadSettings]);
    foreach($roadKeys as $key)Illuminate\Support\Facades\Cache::forget($key);
    Illuminate\Support\Facades\DB::table('mapa_device_positions')->where('device_id',$roadDevice)->delete();
    Illuminate\Support\Facades\DB::table('mapa_tracking_devices')->where('id',$roadDevice)->delete();
    Illuminate\Support\Facades\DB::table('places')->where('uuid',$jd)->update(['company_uuid'=>$company,'location'=>$jpoint]);
    Illuminate\Support\Facades\DB::table('orders')->where('uuid',$jo)->update(['status'=>'dispatched']);
}

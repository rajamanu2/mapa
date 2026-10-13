<?php
/** Offline actual-native-event boundary test: no bootstrap, DB, network, queue or handset. */
namespace Illuminate\Contracts\Broadcasting { interface ShouldBroadcast {} }
namespace Illuminate\Broadcasting {
    trait InteractsWithSockets {}
    class Channel { public function __construct(public string $name){} }
}
namespace Illuminate\Foundation\Bus { trait Dispatchable {} }
namespace Illuminate\Queue { trait SerializesModels {} }
namespace Illuminate\Support {
    class Carbon { public static function now(){return new self;} public function toDateTimeString(){return '2026-10-01 00:00:00';} }
}
namespace Fleetbase\FleetOps\Models {
    class Driver {
        public $altitude=null,$heading=30,$speed=null;
        public function __construct(public string $company_uuid,public string $uuid,public string $public_id,public string $internal_id,
            public string $name,public string $phone,public $location){}
    }
    class Vehicle {
        public $altitude=null,$heading=40,$speed=null;
        public function __construct(public string $company_uuid,public string $uuid,public string $public_id,public string $plate_number,
            public string $display_name,public $location){}
    }
}
namespace Illuminate\Support\Facades {
    class DB { public static array $callbacks=[];public static function afterCommit($callback){self::$callbacks[]=$callback;} }
    class Log {
        public static array $warnings=[];public static bool $fail=false;
        public static function warning($message,$context){if(self::$fail)throw new \RuntimeException('logger unavailable');self::$warnings[]=[$message,$context];}
    }
}
namespace {
    // These are the actual installed native event classes, not a duplicated implementation.
    $native='/fleetbase/api/vendor/fleetbase/fleetops-api/server/src/Events/';
    require $native.'DriverLocationChanged.php';require $native.'VehicleLocationChanged.php';
    require __DIR__.'/../CrewDriverLocationChanged.php';require __DIR__.'/../CrewVehicleLocationChanged.php';
    require __DIR__.'/../MobileService.php';
    use App\MapaMobile\{CrewDriverLocationChanged,CrewVehicleLocationChanged,MobileService};
    use Fleetbase\FleetOps\Models\{Driver,Vehicle};
    use Illuminate\Support\Facades\{DB,Log};
    $checks=0;$dispatched=[];$throwFor=[];
    function check($condition,$message){global $checks;if(!$condition)throw new \RuntimeException($message);$checks++;}
    function config($name){if($name!=='api.version')throw new \RuntimeException('Unexpected config lookup');return 'test-native';}
    function session($name){throw new \RuntimeException('Mobile event must not read native console session '.$name);}
    function event($event){global $dispatched,$throwFor;$dispatched[]=$event;if(in_array($event->broadcastAs(),$throwFor,true))throw new \RuntimeException('provider unavailable: private phone/location/token must not be logged');}
    function channels($event){return array_map(fn($channel)=>$channel->name,$event->broadcastOn());}
    $company='aaaaaaaa-1111-2222-3333-444444444444';$foreign='bbbbbbbb-1111-2222-3333-444444444444';
    $point=['type'=>'Point','coordinates'=>[82.7,18.1]];
    $driver=new Driver($company,'driver-uuid','driver-real','internal-driver','Private crew name','Private crew phone',$point);
    $vehicle=new Vehicle($company,'vehicle-uuid','vehicle-real','AP31 TEST','Actual ambulance',$point);
    $d=new CrewDriverLocationChanged($driver,$company);$v=new CrewVehicleLocationChanged($vehicle,$company,['driver'=>$driver->public_id]);
    check($d instanceof \Illuminate\Contracts\Broadcasting\ShouldBroadcast&&$v instanceof \Illuminate\Contracts\Broadcasting\ShouldBroadcast,'Native broadcast contract retained');
    check(!((new \ReflectionClass($d))->isAnonymous())&&!((new \ReflectionClass($v))->isAnonymous()),'Named serializable events support queued broadcasts');
    check($d->broadcastAs()==='driver.location_changed'&&$v->broadcastAs()==='vehicle.location_changed','Existing native socket event names retained');
    check(channels($d)===['company.'.$company,'driver.driver-real','driver.driver-uuid'],'Driver broadcasts only authenticated company and its specific identities');
    check(channels($v)===['company.'.$company,'vehicle.vehicle-real','vehicle.vehicle-uuid'],'Vehicle broadcasts only authenticated company and its specific identities');
    foreach([$d,$v] as $event)check(!in_array('company.',channels($event),true)&&!in_array('api.',channels($event),true),'No empty tenant or console API-credential channel');
    $driverData=$d->broadcastWith()['data'];$vehicleData=$v->broadcastWith()['data'];
    check($driverData['id']===$driver->public_id&&$driverData['location']===$point&&$driverData['phone']===$driver->phone,'Native driver payload preserves accepted GPS');
    check($vehicleData['id']===$vehicle->public_id&&$vehicleData['location']===$point&&$vehicleData['additionalData']===['driver'=>$driver->public_id],'Native vehicle payload and driver context retained');
    foreach([$d,$v] as $event)check($event->broadcastWith()['api_version']==='test-native','Native payload API version retained');
    foreach([$foreign,'','invalid',str_repeat('a',36)] as $wrong){
        foreach(['driver','vehicle'] as $kind){$rejected=false;try{$kind==='driver'?new CrewDriverLocationChanged($driver,$wrong):new CrewVehicleLocationChanged($vehicle,$wrong);}catch(\InvalidArgumentException $e){$rejected=true;}check($rejected,'Wrong or malformed tenant cannot construct mobile GPS event');}
    }
    $service=(new \ReflectionClass(MobileService::class))->newInstanceWithoutConstructor();
    $method=new \ReflectionMethod(MobileService::class,'publishLocationAfterCommit');
    $context=['company'=>$company,'driver'=>$driver,'vehicle'=>$vehicle];
    $method->invoke($service,$context);
    check(count(DB::$callbacks)===1&&$dispatched===[]&&Log::$warnings===[],'No event/log dispatch before the native transaction commits');
    DB::$callbacks=[];check($dispatched===[],'Rolled-back transaction cannot broadcast discarded GPS');
    $method->invoke($service,$context);(array_pop(DB::$callbacks))();
    check(count($dispatched)===2&&$dispatched[0] instanceof CrewDriverLocationChanged&&$dispatched[1] instanceof CrewVehicleLocationChanged,'Successful commit queues each scoped native-compatible event');
    check(Log::$warnings===[],'Healthy broadcast has no false failure warning');
    $dispatched=[];$throwFor=['driver.location_changed'];$method->invoke($service,$context);(array_pop(DB::$callbacks))();
    check(count($dispatched)===2&&$dispatched[1] instanceof CrewVehicleLocationChanged,'Driver queue failure cannot skip vehicle GPS broadcast');
    check(count(Log::$warnings)===1&&Log::$warnings[0][1]===['company_uuid'=>$company,'driver_uuid'=>$driver->uuid,'vehicle_uuid'=>$vehicle->uuid,'event'=>'driver.location_changed','exception_class'=>\RuntimeException::class],'Broadcast failure warning contains only scoped IDs, event and exception class');
    check(!str_contains(json_encode(Log::$warnings),$driver->name)&&!str_contains(json_encode(Log::$warnings),$driver->phone)&&!str_contains(json_encode(Log::$warnings),'coordinates')&&!str_contains(json_encode(Log::$warnings),'provider unavailable'),'Failure logger excludes private GPS/contact/provider message');
    $dispatched=[];$throwFor=['driver.location_changed','vehicle.location_changed'];$method->invoke($service,$context);(array_pop(DB::$callbacks))();
    check(count($dispatched)===2&&count(Log::$warnings)===3,'Each independent broadcast failure is observable and cannot throw after committed GPS');
    Log::$fail=true;$dispatched=[];$method->invoke($service,$context);(array_pop(DB::$callbacks))();
    check(count($dispatched)===2,'Unavailable logger cannot turn a committed GPS receipt into a failed upload or skip the other event');
    echo "Mobile GPS native broadcast boundaries: {$checks} assertions passed\n";
}

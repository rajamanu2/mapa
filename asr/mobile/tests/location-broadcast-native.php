<?php
/** Offline real framework/model/event serialization; no application bootstrap, DB or network. */
require '/fleetbase/api/vendor/autoload.php';
require __DIR__.'/../CrewDriverLocationChanged.php';require __DIR__.'/../CrewVehicleLocationChanged.php';
use Fleetbase\FleetOps\Models\{Driver,Vehicle};
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use App\MapaMobile\{CrewDriverLocationChanged,CrewVehicleLocationChanged};
$container=new Illuminate\Container\Container;
Illuminate\Container\Container::setInstance($container);
$container->instance('config',new Illuminate\Config\Repository(['api'=>['version'=>'qa-native']]));
$checks=0;
function check($value,$label){global $checks;if(!$value)throw new RuntimeException($label);$checks++;}
$company='aaaaaaaa-1111-2222-3333-444444444444';
// Bypass model boot (observers/providers) and persistence: only real native accessor/value semantics matter here.
$driver=(new ReflectionClass(Driver::class))->newInstanceWithoutConstructor();
$vehicle=(new ReflectionClass(Vehicle::class))->newInstanceWithoutConstructor();
$driver->setRawAttributes(['company_uuid'=>$company,'uuid'=>'driver-uuid','public_id'=>'driver_qa_native','internal_id'=>'qa-driver','location'=>new Point(18.1,82.7),'heading'=>20],true);
$driver->setRelation('user',(object)['name'=>'DEMO offline native driver','phone'=>'DEMO offline phone']);
$vehicle->setRawAttributes(['company_uuid'=>$company,'uuid'=>'vehicle-uuid','public_id'=>'vehicle_qa_native','name'=>'DEMO offline native ambulance','plate_number'=>'AP31 QA','location'=>new Point(18.1,82.7),'heading'=>30],true);
foreach([new CrewDriverLocationChanged($driver,$company),new CrewVehicleLocationChanged($vehicle,$company,['driver'=>$driver->public_id])] as $event){
    check($event instanceof Illuminate\Contracts\Broadcasting\ShouldBroadcast,'Actual ShouldBroadcast contract');
    $names=array_map(fn($c)=>$c->name,$event->broadcastOn());
    check(count($names)===3&&$names[0]==='company.'.$company,'Actual framework channel scopes');
    $serialized=serialize($event);
    check(str_contains($serialized,get_class($event)),'Named event persists queue class');
    $decoded=unserialize($serialized,['allowed_classes'=>true]);
    check(get_class($decoded)===get_class($event)&&$decoded->broadcastAs()===$event->broadcastAs(),'Real SerializesModels event survives queue serialization');
    check(array_map(fn($c)=>$c->name,$decoded->broadcastOn())===$names,'Private authenticated tenant survives actual event serialization');
    $data=$decoded->broadcastWith()['data'];
    check($data['location'] instanceof Point&&$data['location']->getLat()===18.1&&$data['location']->getLng()===82.7,'Actual Point location survives queue serialization');
    check($decoded->broadcastWith()['api_version']==='qa-native','Native payload version from minimal config');
}
echo "Actual native queued mobile GPS events: {$checks} assertions passed\n";

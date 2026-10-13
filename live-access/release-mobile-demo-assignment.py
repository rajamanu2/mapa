"""Release only the exact unstarted mobile-pilot DEMO assignment. No real call or GPS changes."""
import json,runpy,sys
common=runpy.run_path('fleetbase/live-access/provision-customer-access.py')
code=r'''
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Http;
use Fleetbase\FleetOps\Models\Order;
use App\MapaVoice\Policy;
Event::fake();Bus::fake();Queue::fake();Mail::fake();Notification::fake();Http::preventStrayRequests();
$out=DB::transaction(function(){
 $company=config('mapa.company_uuid');
 if($company!=='29eff7bc-7b9b-4d8a-b1b4-55f33681bd2d')throw new RuntimeException('Wrong workspace');
 DB::table('companies')->where('uuid',$company)->lockForUpdate()->first();
 $order=Order::withoutGlobalScopes()->where('company_uuid',$company)->whereNull('deleted_at')->where('public_id','order_mapa_mobile_demo_01')->lockForUpdate()->first();
 $driver=DB::table('drivers')->where('company_uuid',$company)->whereNull('deleted_at')->where('public_id','driver_mapa_mobile_demo_01')->lockForUpdate()->first();
 $vehicle=DB::table('vehicles')->where('company_uuid',$company)->whereNull('deleted_at')->where('public_id','vehicle_mapa_mobile_demo_01')->lockForUpdate()->first();
 if(!$order||!$driver||!$vehicle)throw new RuntimeException('Missing exact DEMO fixture');
 $meta=Policy::meta($order->meta);
 if(!Policy::demo($meta)||($meta['demo_dataset']??'')!=='mapa-mobile-pilot-v1'||!Policy::allowedResource(true,$driver->meta,$vehicle->meta)
   ||$order->driver_assigned_uuid!==$driver->uuid||$order->vehicle_assigned_uuid!==$vehicle->uuid||$order->started||$order->facilitator_uuid)throw new RuntimeException('DEMO release guard failed');
 if($order->status==='canceled'&&isset($meta['mapa_demo_release']))return ['already_released'=>true,'order_id'=>$order->public_id,'status'=>$order->status];
 if($order->status!=='dispatched'||!$order->orderConfig)throw new RuntimeException('DEMO order state changed');
 $meta['mapa_demo_release']=['at'=>now()->toIso8601String(),'reason'=>'Old unstarted training assignment canceled to allow the user to assign a new reviewed DEMO call.','previous_status'=>'dispatched','notifications_sent'=>false];
 $order->meta=$meta;$order->saveQuietly();$order->cancel();$order->refresh();
 if($order->status!=='canceled'||$order->started||!$order->trackingStatuses()->where('code','canceled')->exists())throw new RuntimeException('Cancellation did not validate');
 return ['order_id'=>$order->public_id,'status'=>$order->status,'started'=>(bool)$order->started,'assignment_history_retained'=>$order->driver_assigned_uuid===$driver->uuid,'notifications_sent'=>false,'gps_changed'=>false];
});
echo 'MAPA_RESULT:'.json_encode($out);
'''
if len(sys.argv)!=3 or sys.argv[2]!='--release-exact-demo': raise SystemExit('Pass Docker path and --release-exact-demo for the user-authorized training reset.')
print(json.dumps(common['docker_php'](sys.argv[1],code,{}),indent=2))

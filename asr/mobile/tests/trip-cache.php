<?php
// Isolated cache-boundary checks: no framework boot, database or network calls.
namespace Fleetbase\FleetOps\Models {
    class CacheModel {
        public static array $flushed=[];
        public function __construct(public string $company_uuid, public string $uuid) {}
        public function getKey(){return $this->uuid;}
        public function flushAttributesCache(){self::$flushed[]=$this->uuid;}
    }
    class Order extends CacheModel { public $payload; public $tracking_number_uuid='tracking-a'; }
    class TrackingNumber extends CacheModel {
        public static $row;
        public static function withoutGlobalScopes(){return new CacheQuery;}
    }
    class CacheQuery {
        public function where($values){
            if ($values!==['uuid'=>'tracking-a','company_uuid'=>'company-a']) throw new \Exception('Tracking cache query escaped scope');
            return $this;
        }
        public function whereNull($field){if($field!=='deleted_at')throw new \Exception('Soft delete filter missing');return $this;}
        public function first(){return TrackingNumber::$row;}
    }
}
namespace Illuminate\Support\Facades {
    class DB { public static array $callbacks=[]; public static function afterCommit($callback){self::$callbacks[]=$callback;} }
    class Cache {
        public static array $increments=[],$forgotten=[],$tags=[];
        public static bool $fail=false;
        public static function increment($key){self::$increments[]=$key;if(self::$fail)throw new \Exception('Unavailable');}
        public static function forget($key){self::$forgotten[]=$key;}
        public static function tags($tags){self::$tags[]=$tags;return new CacheTags;}
    }
    class CacheTags { public function flush(){} }
    class Log { public static array $warnings=[]; public static function warning($message,$context){self::$warnings[]=[$message,$context];} }
}
namespace {
    require __DIR__.'/../TripWorkflow.php';
    use Fleetbase\FleetOps\Models\CacheModel;
    use Fleetbase\FleetOps\Models\Order;
    use Fleetbase\FleetOps\Models\TrackingNumber;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\Log;
    $assertions=0;
    function check($ok,$label){global $assertions;$assertions++;if(!$ok)throw new Exception($label);}
    $order=new Order('company-a','order-a');$order->payload=new CacheModel('company-a','payload-a');
    TrackingNumber::$row=new TrackingNumber('company-a','tracking-a');
    $c=['company'=>'company-a','driver'=>new CacheModel('company-a','driver-a'),'vehicle'=>new CacheModel('company-a','vehicle-a')];
    $method=new ReflectionMethod(App\MapaMobile\TripWorkflow::class,'cacheInvalidation');
    $method->invoke(new App\MapaMobile\TripWorkflow,$order,$c);
    check(count(DB::$callbacks)===1,'Only post-commit work is scheduled');
    check(Cache::$increments===[]&&Cache::$forgotten===[]&&CacheModel::$flushed===[],'No pre-commit invalidation');
    // A rolled-back transaction discards its callback, without touching caches.
    DB::$callbacks=[];
    check(Cache::$increments===[],'Discarded transaction cannot flush caches');
    $method->invoke(new App\MapaMobile\TripWorkflow,$order,$c);
    (array_pop(DB::$callbacks))();
    check(count(Cache::$increments)===6,'All six live endpoint versions refreshed');
    foreach(Cache::$increments as $key)check(str_starts_with($key,'live:company-a:')&&str_ends_with($key,':version'),'Only authenticated tenant version keys');
    check(Cache::$forgotten===['order:order-a:tracker'],'Only the changed order tracker forgotten');
    foreach(Cache::$tags as $tags)check(count($tags)===1&&str_starts_with($tags[0],'live:company-a:'),'Only endpoint-specific tenant tags');
    sort(CacheModel::$flushed);check(CacheModel::$flushed===['driver-a','order-a','payload-a','tracking-a','vehicle-a'],'Affected native model attributes refreshed');
    check(Log::$warnings===[],'Healthy cache invalidation has no warning');
    CacheModel::$flushed=[];$c['vehicle']=new CacheModel('company-b','vehicle-b');
    $method->invoke(new App\MapaMobile\TripWorkflow,$order,$c);(array_pop(DB::$callbacks))();
    check(!in_array('vehicle-b',CacheModel::$flushed,true),'A foreign model cannot have its attribute cache flushed');
    Cache::$fail=true;
    $method->invoke(new App\MapaMobile\TripWorkflow,$order,$c);(array_pop(DB::$callbacks))();
    check(count(Log::$warnings)===1,'Cache failure is recorded without converting committed update into a failed mutation');
    check(Log::$warnings[0][1]===['company_uuid'=>'company-a','order_uuid'=>'order-a'],'Failure log excludes call data and credentials');
    echo "Trip cache boundaries: {$assertions} assertions passed\n";
}

<?php
/** Run only in the fresh disposable QA network/container; never uses a live .env. */
if(getenv('MAPA_CREW_QA_ISOLATED')!=='1' || getenv('DB_DATABASE')!=='mapa_crew_qa'
    || !preg_match('/^mapa-crew-qa-db-[a-f0-9]{8}$/D',(string)getenv('DB_HOST'))
    || getenv('DB_USERNAME')!=='root' || getenv('DB_PASSWORD')!=='DEMO-crew-qa-local-only') throw new RuntimeException('Dedicated empty QA database is required.');
require '/fleetbase/api/vendor/autoload.php';
$app=require '/fleetbase/api/bootstrap/app.php';
if(is_file($app->getCachedConfigPath()))throw new RuntimeException('Cached image config must be absent; do not bootstrap with unknown DB configuration.');
$app->useEnvironmentPath('/tmp/mapa-crew-qa-no-env');
$app->useStoragePath('/tmp/mapa-crew-qa-storage');
foreach(['logs','framework/cache','framework/sessions','framework/views','app'] as $dir) @mkdir($app->storagePath($dir),0700,true);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e) { fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n"); exit(1); });
config(['database.default'=>'mysql','database.connections.mysql.host'=>getenv('DB_HOST'),'database.connections.mysql.database'=>'mapa_crew_qa',
    'database.connections.mysql.username'=>'root','database.connections.mysql.password'=>'DEMO-crew-qa-local-only','database.connections.mysql.url'=>null,'database.connections.mysql.options'=>[PDO::ATTR_TIMEOUT=>2],
    'cache.default'=>'array','queue.default'=>'sync','mail.default'=>'array','broadcasting.default'=>'log']);
Illuminate\Support\Facades\DB::purge('mysql');
$connected=false;
for($i=0;$i<120;$i++){try{Illuminate\Support\Facades\DB::connection()->getPdo();$connected=true;break;}catch(Throwable $e){$lastConnectionError=$e->getMessage();Illuminate\Support\Facades\DB::purge('mysql');usleep(1000000);}}
if(!$connected) throw new RuntimeException('Disposable QA database unavailable: '.($lastConnectionError??'unknown')); 
if(Illuminate\Support\Facades\DB::select('SHOW TABLES')!==[])throw new RuntimeException('QA database must start empty; do not reuse an existing database.');
Illuminate\Support\Facades\Http::fake();Illuminate\Support\Facades\Mail::fake();Illuminate\Support\Facades\Notification::fake();Illuminate\Support\Facades\Queue::fake();Illuminate\Support\Facades\Bus::fake();
$result=Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true,'--database'=>'mysql','--realpath'=>true,'--path'=>[
    '/fleetbase/api/database/migrations',
    '/fleetbase/api/vendor/laravel/sanctum/database/migrations',
    '/fleetbase/api/vendor/fleetbase/core-api/migrations',
    '/fleetbase/api/vendor/fleetbase/fleetops-api/server/migrations',
    __DIR__.'/../migrations',
]]);
if($result!==0){echo Illuminate\Support\Facades\Artisan::output();throw new RuntimeException('Disposable native migration failed.');}
echo 'Initialized disposable native schema from cached source; zero production connections.',PHP_EOL;
// native.php can reuse this configured app without reading any environment file.
$mapaDedicatedQaApp=$app;
Illuminate\Support\Facades\Event::fakeExcept(['eloquent.creating: Fleetbase\\FleetOps\\Models\\Position']);
try { require __DIR__.'/connected-tracking-native-cases.php'; }
catch (Throwable $e) { echo $e->getTraceAsString(),PHP_EOL; throw $e; }
require __DIR__.'/whatsapp-receipts-native-cases.php';
require __DIR__.'/hardware-tracking-native-cases.php';
require __DIR__.'/gt06-native-cases.php';
require __DIR__.'/way4track-native-cases.php';
require __DIR__.'/whatsapp-boundaries-native-cases.php';
require __DIR__.'/journey-tracking-native-cases.php';
require __DIR__.'/sms-native-cases.php';
require __DIR__.'/caller-pickup-confirmation-native-cases.php';
echo "MAPA_CONNECTED_SUITE_COMPLETE: $count checks passed.\n";

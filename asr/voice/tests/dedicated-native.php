<?php
/** Run only in the fresh disposable QA network/container; never uses a live .env. */
if(getenv('MAPA_VOICE_QA_ISOLATED')!=='1' || getenv('DB_DATABASE')!=='mapa_voice_qa'
    || !preg_match('/^mapa-voice-qa-db-[a-f0-9]{8}$/D',(string)getenv('DB_HOST'))
    || getenv('DB_USERNAME')!=='root' || getenv('DB_PASSWORD')!=='DEMO-voice-qa-local-only') throw new RuntimeException('Dedicated empty QA database is required.');
require '/fleetbase/api/vendor/autoload.php';
$app=require '/fleetbase/api/bootstrap/app.php';
if(is_file($app->getCachedConfigPath()))throw new RuntimeException('Cached image config must be absent; do not bootstrap with unknown DB configuration.');
$app->useEnvironmentPath('/tmp/mapa-voice-qa-no-env');
$app->useStoragePath('/tmp/mapa-voice-qa-storage');
foreach(['logs','framework/cache','framework/sessions','framework/views','app'] as $dir) @mkdir($app->storagePath($dir),0700,true);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default'=>'mysql','database.connections.mysql.host'=>getenv('DB_HOST'),'database.connections.mysql.database'=>'mapa_voice_qa',
    'database.connections.mysql.username'=>'root','database.connections.mysql.password'=>'DEMO-voice-qa-local-only','database.connections.mysql.url'=>null,'database.connections.mysql.options'=>[PDO::ATTR_TIMEOUT=>2],
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
    dirname(__DIR__,2).'/MapaMobile/migrations',
]]);
if($result!==0){echo Illuminate\Support\Facades\Artisan::output();throw new RuntimeException('Disposable native migration failed.');}
echo 'Initialized disposable native schema from cached source; zero production connections.',PHP_EOL;
// native.php can reuse this configured app without reading any environment file.
$mapaDedicatedVoiceQaApp=$app;
$suite=getenv('MAPA_VOICE_QA_SUITE')?:'native';
if(!in_array($suite,['native','automatic','arrivals'],true))throw new RuntimeException('Unknown isolated QA suite.');
if($suite==='arrivals')require dirname(__DIR__,2).'/MapaMobile/tests/dedicated-arrivals.php';
else require __DIR__.'/'.($suite==='automatic'?'dedicated-automatic.php':'native.php');

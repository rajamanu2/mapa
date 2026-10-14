<?php
// Isolated eligibility boundary: no application .env, messages, native models or production DB.
require $argv[1];
$app=new Illuminate\Container\Container;Illuminate\Container\Container::setInstance($app);
$app->instance('config',new Illuminate\Config\Repository([]));
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$db=new Illuminate\Database\Capsule\Manager($app);$db->addConnection(['driver'=>'sqlite','database'=>':memory:']);$db->setAsGlobal();$db->bootEloquent();
$app->instance('db',$db->getDatabaseManager());$app->bind('db.schema',fn()=>$db->getConnection()->getSchemaBuilder());
require __DIR__.'/../../mobile/DispatchAuthority.php';
require __DIR__.'/../Policy.php';require __DIR__.'/../VoiceService.php';
class SourceBoundary extends App\MapaVoice\VoiceService {public function inspect($d,$v,$call){return $this->inspectEligibility($d,$v,$call,['vehicle_requirement'=>'any']);}}
$check=function($condition,$label){if(!$condition)throw new RuntimeException($label);};$service=new SourceBoundary;
$d=(object)['uuid'=>'DEMO-driver','company_uuid'=>'DEMO-company','meta'=>null,'online'=>false];$v=(object)['uuid'=>'DEMO-vehicle','meta'=>null,'online'=>false];$call=(object)['company_uuid'=>'DEMO-company','demo'=>false];$checks=0;
foreach(['asr_import','asr_source_id','mapa_source_id'] as $key){$d->meta=json_encode([$key=>'DEMO-source']);$check($service->inspect($d,$v,$call)['code']==='source_owned','Source metadata blocks duplicate native dispatch');$checks++;}
$d->meta=null;$v->meta=json_encode(['asr_import'=>['id'=>'DEMO-source']]);$check($service->inspect($d,$v,$call)['code']==='source_owned','Vehicle import blocks duplicate dispatch');$checks++;$v->meta=null;
Illuminate\Support\Facades\Schema::create('mapa_source_connections',function($t){$t->text('company_uuid');$t->text('native_uuid');$t->text('dataset');});
Illuminate\Support\Facades\DB::table('mapa_source_connections')->insert(['company_uuid'=>'DEMO-other-company','native_uuid'=>$d->uuid,'dataset'=>'drivers']);
$check($service->inspect($d,$v,$call)['code']==='off_duty','Other company connection does not alter own eligibility');$checks++;
Illuminate\Support\Facades\DB::table('mapa_source_connections')->insert(['company_uuid'=>$call->company_uuid,'native_uuid'=>$d->uuid,'dataset'=>'drivers']);
$check($service->inspect($d,$v,$call)['code']==='source_owned','Exact tenant source connection blocks native dispatch');$checks++;
config(['mapa_mobile.dispatch_authority'=>'mapa']);
$check($service->inspect($d,$v,$call)['code']==='source_reconciliation_required','Missing cutover ownership schema fails closed');$checks++;
Illuminate\Support\Facades\Schema::create('mapa_source_journeys',function($t){$t->text('company_uuid');$t->timestamp('terminal_at')->nullable();});
Illuminate\Support\Facades\Schema::create('mapa_source_dispatch_outbox',function($t){$t->text('company_uuid');$t->text('state');});
Illuminate\Support\Facades\DB::table('mapa_source_journeys')->insert(['company_uuid'=>$call->company_uuid,'terminal_at'=>null]);
$check($service->inspect($d,$v,$call)['code']==='source_reconciliation_required','Unresolved source journey keeps MAPA resources unavailable');$checks++;
Illuminate\Support\Facades\DB::table('mapa_source_journeys')->delete();
$check($service->inspect($d,$v,$call)['code']==='off_duty','MAPA cutover permits imported resources but preserves duty gate');$checks++;
$v->meta=json_encode(['asr_import'=>['id'=>'DEMO-source']]);
$check($service->inspect($d,$v,$call)['code']==='off_duty','Import provenance is not dispatch ownership after cutover');$checks++;
config(['mapa_mobile.dispatch_authority'=>'invalid']);
$check($service->inspect($d,$v,$call)['code']==='source_owned','Unknown authority fails closed to legacy source ownership');$checks++;
echo $checks.' source dispatch ownership boundary checks passed; isolated SQLite, zero production writes.'.PHP_EOL;


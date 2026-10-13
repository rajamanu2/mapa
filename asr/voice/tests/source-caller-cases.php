<?php
// Included only in the existing temporary synthetic voice schema, never live records.
require_once $mobileDirectory.'/SourceCallStore.php';
require_once __DIR__.'/../SourceCallerService.php';
class DemoCallerStore extends App\MapaMobile\SourceCallStore {
    public array $fixture;
    public function read(string $company):array {if($company!==$this->fixture['company_id'])App\MapaMobile\MobileService::fail('Wrong fixture company',403);return $this->fixture;}
}
class DemoCallerService extends App\MapaVoice\SourceCallerService {
    public DemoCallerStore $fixtureStore;
    protected function company():string {return config('mapa.company_uuid');} // MySQL temporary fixture tables are invisible to information_schema.
    protected function sourceStore():App\MapaMobile\SourceCallStore {return $this->fixtureStore;}
    protected function sourceDirectory(string $company):array {return ['snapshot'=>['datasets'=>['hospitals'=>[['phc_id'=>'PHC-DEMO','name'=>'DEMO PHC']],'secretariats'=>[],'villages'=>[]]]];}
}
$sourceRow=array_fill_keys(MapaSource\SourceCallFeed::FIELDS,null);
$sourceRow=array_replace($sourceRow,['id'=>'701','incident_number'=>'DEMO-INC-701','caller_name'=>'DEMO source caller','caller_phone'=>'2025550142','patient_name'=>'DEMO patient','incident_type'=>'Medical','priority'=>'P2','from_phc_id'=>'PHC-DEMO','status'=>'pending','notes'=>'DEMO synthetic caller report','pickup_address'=>'DEMO pickup','created_at'=>'2026-10-07 10:00:00']);
$callerStore=new DemoCallerStore;$callerStore->fixture=['contract'=>MapaSource\SourceCallFeed::CONTRACT,'company_id'=>$company,'source'=>'https://asrhealth.in','generated_at'=>time(),'calls'=>[$sourceRow],'truncated'=>false,'stale'=>false];
$sourceService=new DemoCallerService;$sourceService->fixtureStore=$callerStore;
$sourceRevision=MapaSource\SourceCallFeed::revision($sourceRow);$sourceBody=['source_revision'=>$sourceRevision];
$ordersBeforeSource=DB::table('orders')->count();$usersBeforeSource=DB::table('users')->count();
denied(fn()=>$sourceService->openSource(voiceRequest($sourceBody,$tokens['crew']),'701'),403);
$sourceResult=$sourceService->openSource(voiceRequest($sourceBody,$tokens['admin']),'701');
check($sourceResult['source_owned']&&$sourceResult['dispatch_created']===false,'source open is a tracking mirror, not a dispatch');
check($sourceResult['call']['draft']['callback_number']==='+912025550142','source callback is normalized without substituting driver phone');
check($sourceResult['call']['draft']['origin_phc_name']==='DEMO PHC','source PHC name uses exact ID');
$secondSource=$sourceService->openSource(voiceRequest($sourceBody,$tokens['admin']),'701');
check($secondSource['call']['id']===$sourceResult['call']['id']&&$secondSource['call']['revision']===$sourceResult['call']['revision'],'repeat source opens reuse one unchanged tracking record');
check(DB::table('mapa_voice_calls')->where(['provider'=>'asrhealth','provider_call_id'=>'701'])->count()===1,'one source identity has one tracking mirror');
check(DB::table('orders')->count()===$ordersBeforeSource&&DB::table('users')->count()===$usersBeforeSource,'no native order or user created');
denied(fn()=>$s->mutate(voiceRequest(['action_id'=>(string)Str::uuid(),'expected_revision'=>1,'reason'=>'DEMO'],$tokens['admin']),'dismiss',$sourceResult['call']['id']),409);
denied(fn()=>$s->extract(voiceRequest(['action_id'=>(string)Str::uuid(),'expected_revision'=>1],$tokens['admin']),$sourceResult['call']['id']),409);
$callerStore->fixture['calls'][0]['caller_phone']='2025550199';
denied(fn()=>$sourceService->openSource(voiceRequest($sourceBody,$tokens['admin']),'701'),409);
$sourceBody['source_revision']=MapaSource\SourceCallFeed::revision($callerStore->fixture['calls'][0]);
$sourceUpdated=$sourceService->openSource(voiceRequest($sourceBody,$tokens['admin']),'701');
check($sourceUpdated['call']['id']===$sourceResult['call']['id']&&$sourceUpdated['call']['revision']===2,'source phone change updates same call revision');
check($sourceUpdated['call']['draft']['callback_number']==='+912025550199','updated source phone is displayed in tracking desk');
$callerStore->fixture['stale']=true;denied(fn()=>$sourceService->openSource(voiceRequest($sourceBody,$tokens['admin']),'701'),409);
$callerStore->fixture['stale']=false;$callerStore->fixture['calls']=[];denied(fn()=>$sourceService->openSource(voiceRequest($sourceBody,$tokens['admin']),'701'),410);
check(DB::table('orders')->count()===$ordersBeforeSource,'all source retries and rejection paths leave dispatch unchanged');

<?php
// Included only from the existing fresh isolated native console-review suite.
if(!isset($mapaDedicatedQaApp,$consoleBearer,$company))throw new RuntimeException('Dedicated source QA context required.');
$sourceController=new App\MapaMobile\SourceInventoryController;
$sourcePrior=config('mapa_mobile.source_sync_enabled');
config(['mapa_mobile.source_sync_enabled'=>true]);
$dir=storage_path('app/mapa-source-sync');@mkdir($dir,0700,true);
$snapshot=['contract'=>'mapa-asr-source-v1','source'=>'https://asrhealth.in','company_id'=>$company,'complete'=>true,
    'generated_at'=>microtime(true),'source_date'=>'2026-10-05','datasets'=>array_fill_keys(array_keys(\MapaSource\SourceSync::FIELDS),[])];
for($i=1;$i<=101;$i++)$snapshot['datasets']['drivers'][]=['id'=>$i,'name'=>'DEMO source driver '.$i,'age'=>null,'gender'=>null,'dob'=>null,'doj'=>null,'phc_id'=>'DEMO-PHC','mobile_number'=>'9000000000','vehicle_type'=>'Special'];
$snapshot['datasets']['hospitals']=[['phc_id'=>'DEMO-PHC','name'=>'DEMO source hospital','type'=>'1']];
\MapaSource\SourceSync::validate($snapshot,time());
$state=['snapshot'=>$snapshot,'received_at'=>time(),'changes'=>[]];
$file=$dir.'/state.json';file_put_contents($file,json_encode($state));
$sourceCounts=fn()=>array_map(fn($t)=>DB::table($t)->count(),['users','drivers','vehicles','orders','positions','mapa_mobile_bindings','mapa_mobile_sessions']);$sourceBefore=$sourceCounts();
$sourceReq=function($query=[],$token=null)use($consoleBearer){$r=Illuminate\Http\Request::create('/api/int/v1/mapa/source-inventory/drivers','GET',$query);$r->headers->set('Authorization','Bearer '.($token??$consoleBearer));return $r;};
$sourceResponse=$sourceController->index($sourceReq(),'drivers');$data=$sourceResponse->getData(true);
check($sourceResponse->getStatusCode()===200 && count($data['rows'])===100 && $data['total']===101 && $data['has_more'],'source mirror admin view is bounded and paginated');
check($sourceController->index($sourceReq(),'status')->getData(true)['filter_options']['hospitals'][0]['phc_id']==='DEMO-PHC','source dropdown options preserve exact hospital identities');
check($sourceController->index($sourceReq(['sort'=>'id','direction'=>'desc']),'drivers')->getData(true)['rows'][0]['id']===101,'source sort applies before pagination');
check($sourceController->index($sourceReq(['sort'=>'password']),'drivers')->getStatusCode()===422,'source sort rejects private/unrecognized fields');
check($sourceController->index($sourceReq(['institute_type_id'=>'1']),'drivers')->getData(true)['total']===101,'source institute filter resolves exact hospital type');
check($sourceController->index($sourceReq(['institute_type_id'=>'2']),'drivers')->getData(true)['total']===0,'source institute filter excludes unrelated hospitals');
check($sourceController->index($sourceReq(['date_from'=>'2026-02-30']),'daily_status')->getStatusCode()===422,'source invalid calendar date denied');
check($sourceController->index($sourceReq(['date_from'=>'2026-10-06','date_to'=>'2026-10-05']),'daily_status')->getStatusCode()===422,'source reversed date range denied');
check($data['rows'][0]['hospital_name']==='DEMO source hospital','source view resolves PHC labels');
check(str_contains($sourceResponse->headers->get('Cache-Control'),'no-store'),'source mirror private responses are not cached');
check(count($sourceController->index($sourceReq(['page'=>'2']),'drivers')->getData(true)['rows'])===1,'source second page preserves remaining identity');
check($sourceController->index($sourceReq(['page'=>'-1']),'drivers')->getStatusCode()===422,'source page validation');
check($sourceController->index($sourceReq(),'users')->getStatusCode()===404,'source arbitrary datasets are not exposed');
check($sourceController->index($sourceReq(['month'=>'2026-99']),'attendance')->getStatusCode()===422,'source attendance month validation');
check($sourceController->index($sourceReq(['vehicle_type'=>'104']),'drivers')->getData(true)['total']===0,'source category filtering does not invent records');
check($sourceController->index($sourceReq([],str_repeat('x',40)),'drivers')->getStatusCode()===401,'source invalid native credential denied');
$crewToken=Fleetbase\Models\User::where('uuid',$user)->first()->createToken($user,['*']);
check($sourceController->index($sourceReq([],$crewToken->plainTextToken),'drivers')->getStatusCode()===403,'crew cannot read staff source mirror');
$profileController=new App\MapaMobile\SourceProfileController;
$profileReply=$profileController->show($sourceReq(),'drivers','1');
check($profileReply->getStatusCode()===200 && $profileReply->getData(true)['record']['name']==='DEMO source driver 1','source profile exact identity');
check(str_contains($profileReply->headers->get('Cache-Control'),'no-store'),'source profile private cache boundary');
check($profileController->show($sourceReq(),'drivers','999')->getStatusCode()===404,'source profile missing identity');
check($profileController->show($sourceReq(['company_id'=>$foreign]),'drivers','1')->getStatusCode()===422,'source profile rejects tenant override');
check($profileController->show($sourceReq(),'users','1')->getStatusCode()===422,'source profile rejects unrelated dataset');
check($profileController->show($sourceReq(),'drivers','../1')->getStatusCode()===422,'source profile rejects path identity');
check($profileController->show($sourceReq([],str_repeat('x',40)),'drivers','1')->getStatusCode()===401,'source profile rejects invalid credential');
check($profileController->show($sourceReq([],$crewToken->plainTextToken),'drivers','1')->getStatusCode()===403,'source profile blocks crew staff data access');
$crewToken->accessToken->delete();
$state['received_at']=time()-121;file_put_contents($file,json_encode($state));
check($sourceController->index($sourceReq(),'status')->getData(true)['stale'],'source outage keeps prior data explicitly stale');
// A private cached file is still untrusted input: exact schema is rechecked on read.
$corruptSource=$state;$corruptSource['snapshot']['datasets']['drivers'][0]['caller_phone']='DEMO forbidden field';
file_put_contents($file,json_encode($corruptSource));
$corruptResponse=$sourceController->index($sourceReq(),'drivers');
check($corruptResponse->getStatusCode()===503&&!str_contains($corruptResponse->getContent(),'DEMO forbidden field'),'source cached extra private field fails closed without disclosure');
$corruptSource=$state;unset($corruptSource['snapshot']['datasets']['repairs']);file_put_contents($file,json_encode($corruptSource));
check($sourceController->index($sourceReq(),'drivers')->getStatusCode()===503,'source cached partial dataset fails closed');
$corruptSource=$state;$corruptSource['snapshot']['datasets']['drivers'][0]['id']=null;file_put_contents($file,json_encode($corruptSource));
check($sourceController->index($sourceReq(),'drivers')->getStatusCode()===503,'source cached missing identity fails closed');
$corruptSource=$state;$corruptSource['snapshot']['contract']='mapa-asr-source-unknown';file_put_contents($file,json_encode($corruptSource));
check($sourceController->index($sourceReq(),'drivers')->getStatusCode()===503,'source cached unsupported contract fails closed');
file_put_contents($file,json_encode($state));
check($sourceController->index($sourceReq(),'drivers')->getStatusCode()===200,'valid exact source schema restores read after rejected corrupt fixtures');
$state['snapshot']['company_id']=$foreign;file_put_contents($file,json_encode($state));
check($sourceController->index($sourceReq(),'drivers')->getStatusCode()===503,'foreign source company denied');
config(['mapa_mobile.source_sync_enabled'=>false]);
check($sourceController->index($sourceReq(),'drivers')->getStatusCode()===503,'source integration disabled by default');
// Manual fetch exercises native authorization and reply scope with a synthetic receiver.
config(['mapa_mobile.source_sync_enabled'=>true]);$state['snapshot']['company_id']=$company;file_put_contents($file,json_encode($state));
$refreshController=new class extends App\MapaMobile\SourceInventoryController {
    public bool $allowed=true;public int $calls=0;public $operation=null;
    protected function fetchAllowed(): bool{return $this->allowed;}
    protected function runSync(): void{$this->calls++;if($this->operation)($this->operation)();}
};
$refreshReq=function($query=[],$body='',$token=null)use($consoleBearer){
    $r=Illuminate\Http\Request::create('/api/int/v1/mapa/source-inventory/refresh','POST',[],[],[],[], $body);
    $r->query->replace($query);$r->headers->set('Authorization','Bearer '.($token??$consoleBearer));return $r;
};
check($refreshController->refresh($refreshReq([], '',str_repeat('x',40)))->getStatusCode()===401,'manual source fetch rejects invalid native credential');
$crewToken=Fleetbase\Models\User::where('uuid',$user)->first()->createToken($user,['*']);
check($refreshController->refresh($refreshReq([], '',$crewToken->plainTextToken))->getStatusCode()===403,'crew cannot trigger source fetch');$crewToken->accessToken->delete();
foreach([['url'=>'https://other.invalid'],['company_id'=>$foreign],['state_directory'=>'/tmp']] as $params)
    check($refreshController->refresh($refreshReq($params))->getStatusCode()===422,'manual source fetch rejects browser supplied '.array_key_first($params));
check($refreshController->refresh($refreshReq([], '{}'))->getStatusCode()===422,'manual source fetch rejects request body');
check($refreshController->calls===0,'denied or malformed source fetch never invokes receiver');
$refreshController->allowed=false;
check($refreshController->refresh($refreshReq())->getStatusCode()===503,'unconfigured manual source fetch is unavailable');$refreshController->allowed=true;
$refreshController->operation=function()use($file,&$state){$state['received_at']=time();file_put_contents($file,json_encode($state));};
$fetched=$refreshController->refresh($refreshReq());$fetchedData=$fetched->getData(true);
check($fetched->getStatusCode()===200 && $fetchedData['has_snapshot'] && $fetchedData['counts']['drivers']===101 && !$fetchedData['stale'],'manual source fetch returns current verified status');
check(str_contains($fetched->headers->get('Cache-Control'),'no-store'),'manual source fetch response is private and uncached');
$preserved=file_get_contents($file);
$refreshController->operation=function(){throw new \MapaSource\SourceSyncBusy('DEMO lock contention');};
$throttled=$refreshController->refresh($refreshReq());
check($throttled->getStatusCode()===429 && $throttled->headers->get('Retry-After')==='30','manual source fetch exposes bounded retry delay');
check(file_get_contents($file)===$preserved,'busy manual source fetch retains prior records');
$refreshController->operation=function(){throw new RuntimeException('DEMO internal secret must not escape');};
$failed=$refreshController->refresh($refreshReq());
check($failed->getStatusCode()===503 && !str_contains($failed->getContent(),'internal secret'),'manual source fetch hides transport and credential errors');
check(file_get_contents($file)===$preserved,'failed manual source fetch retains prior records');
$revocable=$consoleReviewer->createToken($consoleReviewer->uuid,['*']);
$refreshController->operation=function()use($revocable){$revocable->accessToken->delete();};
$revoked=$refreshController->refresh($refreshReq([], '',$revocable->plainTextToken));
check($revoked->getStatusCode()===401 && !isset($revoked->getData(true)['counts']),'manual source fetch rechecks revocation before returning records');
check($sourceCounts()===$sourceBefore,'source reading and fetch change no native operational records, bindings or sessions');
unlink($file);config(['mapa_mobile.source_sync_enabled'=>$sourcePrior]);

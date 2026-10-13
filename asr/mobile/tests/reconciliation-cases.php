<?php
use Illuminate\Support\Facades\DB;
use MapaSource\SourceSync;
use MapaSource\SourceJournal;
use App\MapaMobile\SourceReconciliation;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
$count=0;
function check($ok,$label){global $count;if(!$ok)throw new RuntimeException($label);$count++;}
function fails(callable $fn,string $label){try{$fn();}catch(Throwable $e){check(true,$label);return;}throw new RuntimeException('Expected failure: '.$label);}
function denied(callable $fn,int $status){try{$fn();}catch(HttpExceptionInterface $e){check($e->getStatusCode()===$status,'Expected denial '.$status);return;}throw new RuntimeException('Expected denial '.$status);}
function fixture(int $n): array {
    $s=['contract'=>SourceSync::CONTRACT,'source'=>'https://asrhealth.in','company_id'=>SourceSync::COMPANY,'complete'=>true,
        'generated_at'=>microtime(true)+$n/1000,'source_date'=>date('Y-m-d'),'datasets'=>[]];
    foreach(SourceSync::FIELDS as $dataset=>$fields)$s['datasets'][$dataset]=[];
    $d=array_fill_keys(SourceSync::FIELDS['drivers'],null);$d['id']='DEMO-driver';$d['name']='DEMO driver';$d['phc_id']='DEMO-A';
    $s['datasets']['drivers']=[$d];return $s;
}
$dir=storage_path('app/mapa-source-sync');mkdir($dir,0700,true);$path=$dir.'/state.json';
$company=SourceSync::COMPANY;$actor='00000000-0000-4000-8000-000000000001';$second='00000000-0000-4000-8000-000000000002';
$context=['company'=>$company,'user'=>(object)['uuid'=>$actor]];$other=['company'=>'00000000-0000-4000-8000-000000000099','user'=>(object)['uuid'=>$actor]];
$policy=['enabled'=>true,'company_id'=>$company,'actors'=>[$actor=>['read','review'],$second=>['read','review']]];
file_put_contents($dir.'/connections.json',json_encode($policy));config(['mapa_mobile.source_sync_enabled'=>true,'mapa_mobile.source_connections_enabled'=>true]);
$service=new SourceReconciliation;
$first=fixture(1);SourceSync::save($path,$first,time());
$rows=$service->listing($context,'pending',1);check($rows['pending_count']===1&&$rows['rows'][0]['kind']==='added','initial identity observation');
$event=$rows['rows'][0];$id=$event['id'];
check(!array_key_exists('mobile_number',$event['observation']['after'])&&!array_key_exists('dob',$event['observation']['after']),'journal minimizes private fields');
check($rows['source_action_time_available']===false&&$rows['review_only']===true,'observation not source action time');
check($service->listing($context,'pending',1)['pending_count']===1,'repeat ingest idempotent');
$head=DB::table('mapa_source_reconciliation_cursors')->value('head');
check($head===json_decode(file_get_contents($path),true)['reconciliation_head'],'cursor commits promoted head');
$secondSnapshot=$first;$secondSnapshot['generated_at']+=0.01;$secondSnapshot['datasets']['drivers'][0]['phc_id']='DEMO-B';SourceSync::save($path,$secondSnapshot,time());
$third=$secondSnapshot;$third['generated_at']+=0.01;$third['datasets']['drivers'][0]['phc_id']='DEMO-A';SourceSync::save($path,$third,time());
$rows=$service->listing($context,'all',1);
check($rows['total']===3,'both intermediate transfers retained before polling');
$transfers=array_values(array_filter($rows['rows'],fn($r)=>$r['kind']==='transfer_observed'));
check(count($transfers)===2,'transfer classification');
check($transfers[0]['observation']['before']['phc_id']!==$transfers[0]['observation']['after']['phc_id'],'before and after source base recorded');
$ack=$service->acknowledge($context,$id);check(!$ack['replayed']&&$ack['acknowledged_by']===$actor,'first acknowledgement');
$again=$service->acknowledge(['company'=>$company,'user'=>(object)['uuid'=>$second]],$id);
check($again['replayed']&&$again['acknowledged_by']===$actor&&$again['acknowledged_at']===$ack['acknowledged_at'],'replay keeps original audit actor and time');
check($service->listing($context,'pending',1)['pending_count']===2,'acknowledged hidden from pending');
check($service->listing($context,'acknowledged',1)['total']===1,'acknowledged view');
config(['mapa_mobile.source_connections_enabled'=>false]);
$readOnly=$service->listing($context,'all',1);
check($readOnly['total']===3&&$readOnly['review_allowed']===false,'read-only observations remain visible while connections are disabled');
denied(fn()=>$service->acknowledge($context,$id),503);
config(['mapa_mobile.source_connections_enabled'=>true]);
$policy['company_id']=$other['company'];file_put_contents($dir.'/connections.json',json_encode($policy));
denied(fn()=>$service->acknowledge($other,$id),404);
$policy['company_id']=$company;$policy['actors'][$actor]=['read'];file_put_contents($dir.'/connections.json',json_encode($policy));
denied(fn()=>$service->acknowledge($context,$id),403);
$policy['actors'][$actor]=[];file_put_contents($dir.'/connections.json',json_encode($policy));denied(fn()=>$service->listing($context,'pending',1),403);
$policy['actors'][$actor]=['read','review'];file_put_contents($dir.'/connections.json',json_encode($policy));
$old=json_decode(file_get_contents($path),true);$orphan=$third;$orphan['generated_at']+=0.001;$orphan['datasets']['drivers'][0]['phc_id']='DEMO-UNCOMMITTED';
$orphanHead=SourceJournal::prepare($path,$old,$orphan,time());check(is_file($dir.'/reconciliation/'.$orphanHead.'.json'),'prepared journal exists before promotion');
check($service->listing($context,'all',1)['total']===3,'unpromoted generation ignored');
$empty=$third;$empty['generated_at']+=0.02;SourceSync::save($path,$empty,time());
check(json_decode(file_get_contents($path),true)['reconciliation_head']===$old['reconciliation_head'],'no-change generation does not consume journal capacity');
$removed=$empty;$removed['generated_at']+=0.01;$removed['datasets']['drivers']=[];SourceSync::save($path,$removed,time());
$rows=$service->listing($context,'all',1);check($rows['total']===4,'source removal preserved');
$removals=array_values(array_filter($rows['rows'],fn($r)=>$r['kind']==='removed_at_source'));check(count($removals)===1&&$removals[0]['observation']['after']===null,'removal is review tombstone');
$many=$removed;$many['generated_at']+=0.01;
for($i=0;$i<510;$i++){$d=$first['datasets']['drivers'][0];$d['id']='DEMO-'.$i;$many['datasets']['drivers'][]=$d;}
SourceSync::save($path,$many,time());check(count(json_decode(file_get_contents($path),true)['changes'])===500,'legacy changes remains bounded');
$rows=$service->listing($context,'all',1);check($rows['total']===514&&count($rows['rows'])===50&&$rows['has_more'],'journal retains beyond legacy cap and pagination bounded');
$page2=$service->listing($context,'all',2);check(count(array_intersect(array_column($rows['rows'],'id'),array_column($page2['rows'],'id')))===0,'stable pagination no repeated IDs');
$stableState=file_get_contents($path);$state=json_decode($stableState,true);$stableCursor=DB::table('mapa_source_reconciliation_cursors')->value('head');$stableCount=DB::table('mapa_source_reconciliation_events')->count();
$doc=['version'=>1,'company_id'=>$company,'source'=>'https://asrhealth.in','previous'=>$state['reconciliation_head'],'generated_at'=>$many['generated_at']+0.001,'observed_at'=>time(),
    'events'=>[['dataset'=>'drivers','source_id'=>'DEMO-rollback','kind'=>'added','fields'=>[],'before'=>null,'after'=>null],['dataset'=>'users','source_id'=>'DEMO-invalid','kind'=>'added']]];
$body=SourceSync::json($doc);$bad=hash('sha256',$body);file_put_contents($dir.'/reconciliation/'.$bad.'.json',$body);$state['reconciliation_head']=$bad;file_put_contents($path,SourceSync::json($state));
fails(fn()=>$service->ingest($company),'partial batch rolls back');
check(DB::table('mapa_source_reconciliation_events')->count()===$stableCount&&DB::table('mapa_source_reconciliation_cursors')->value('head')===$stableCursor,'failure leaves cursor and events intact');
file_put_contents($path,$stableState);
$journal=$dir.'/reconciliation/'.$stableCursor.'.json';$saved=file_get_contents($journal);file_put_contents($journal,$saved.' ');
fails(fn()=>SourceJournal::pending($path,$stableCursor,null,$company),'tampered journal rejected');file_put_contents($journal,$saved);
fails(fn()=>SourceJournal::pending($path,str_repeat('a',64),null,$company),'missing committed journal rejected');
fails(fn()=>SourceJournal::pending($path,$stableCursor,null,$other['company']),'foreign journal rejected');
check($service->listing($context,'all',1)['total']===$stableCount,'recovery safely retries');
denied(fn()=>$service->acknowledge($context,'../state.json'),422);
// Exercise real capacity failure without deleting existing journal history.
$capacity=storage_path('app/DEMO-journal-capacity');mkdir($capacity.'/reconciliation',0700,true);
for($i=0;$i<SourceJournal::MAX_FILES;$i++)file_put_contents($capacity.'/reconciliation/'.hash('sha256','DEMO-capacity-'.$i).'.json','{}');
fails(fn()=>SourceSync::save($capacity.'/state.json',fixture(1),time()),'capacity fails before state promotion');
check(!is_file($capacity.'/state.json'),'capacity never promotes an unjournaled snapshot');
check(DB::select('SHOW TABLES')!==[],'tests use actual MySQL migration tables');
// Focused response-contract integration: explicitly fake only the already separately
// tested console authentication boundary; retain real grants, journal and MySQL service.
if(class_exists('App\\MapaMobile\\ConsoleEnrollmentService',false))throw new RuntimeException('QA auth stub must not replace a loaded class.');
eval('namespace App\\MapaMobile; class ConsoleEnrollmentService {public static $qaContext;public function __construct($media){} public function context($request,$lock=false){if(self::$qaContext===null)MobileService::fail("DEMO unauthenticated",401);return self::$qaContext;}}');
App\MapaMobile\ConsoleEnrollmentService::$qaContext=$context;
require_once __DIR__.'/../SourceReconciliationController.php';
$controller=new App\MapaMobile\SourceReconciliationController;
$response=$controller->index(Illuminate\Http\Request::create('/review?page=1&status=pending','GET'));
$body=$response->getData(true);
check($response->getStatusCode()===200&&$body['contract']==='mapa-asr-reconciliation-v1'&&$body['company_id']===$company,'GET controller company and contract envelope');
check(str_contains($response->headers->get('Cache-Control'),'no-store'),'GET queue disables caching');
$ackRequest=Illuminate\Http\Request::create('/review/'.$id.'/acknowledge','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],'{"reviewed":true}');
$response=$controller->acknowledge($ackRequest,$id);$body=$response->getData(true);
check($response->getStatusCode()===200&&$body['contract']==='mapa-asr-reconciliation-v1'&&$body['company_id']===$company&&$body['replayed']===true,'POST acknowledgement envelope accepts successful replay');
foreach(['{}','{"reviewed":false}','{"reviewed":true,"native_uuid":"DEMO"}','not-json'] as $invalid){
    $r=Illuminate\Http\Request::create('/review/'.$id.'/acknowledge','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],$invalid);
    check($controller->acknowledge($r,$id)->getStatusCode()===422,'controller rejects invalid acknowledgement body');
}
check($controller->index(Illuminate\Http\Request::create('/review?page=0','GET'))->getStatusCode()===422,'controller rejects invalid page');
App\MapaMobile\ConsoleEnrollmentService::$qaContext=null;
check($controller->index(Illuminate\Http\Request::create('/review','GET'))->getStatusCode()===401,'controller propagates auth boundary denial');
echo 'Journal chain, retained transfers, idempotent import, SQL rollback, tenant review permissions, capacity and replay checks passed.',PHP_EOL;

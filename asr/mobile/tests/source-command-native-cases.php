<?php
// Synthetic, isolated native suite only. No source-host network request is made.
if(!isset($mapaDedicatedQaApp,$consoleBearer,$company,$consoleReviewer))throw new RuntimeException('Dedicated source command QA required.');
$commands=new class extends App\MapaMobile\SourceCommandController {
    public string $tenant;public bool $enabled=true;public array $sent=[];public $operation=null;public bool $refreshFail=false;public $refreshOperation=null;
    protected function authoritativeCompany():string{return $this->tenant;}
    protected function configured():bool{return $this->enabled;}
    protected function allowedActions(string $actor):array{return \MapaSource\SourceCommands::ACTIONS;}
    protected function send(array $c):array{$this->sent[]=$c;if($this->operation)return ($this->operation)($c);return ['contract'=>$c['contract'],'company_id'=>$c['company_id'],
        'request_id'=>$c['request_id'],'action'=>$c['action'],'record_id'=>$c['record_id'],'revision'=>str_repeat('b',64),'applied_at'=>gmdate('c'),'refresh_required'=>true];}
    protected function refreshSource():void{if($this->refreshOperation)($this->refreshOperation)();if($this->refreshFail)throw new RuntimeException('DEMO receiver cooling down');}
};
$commands->tenant=$company;
$commandBody=['request_id'=>(string)Str::uuid(),'action'=>'ambulance.profile','record_id'=>'DEMO-vehicle-1',
    'expected_revision'=>str_repeat('a',64),'values'=>['vehicle_model'=>'DEMO ambulance model','model_year'=>2024]];
$commandReq=function($body=null,$token=null,$query=[])use($consoleBearer,$commandBody){
    $r=Illuminate\Http\Request::create('/api/int/v1/mapa/source-commands','POST',[],[],[],[],json_encode($body??$commandBody));
    $r->headers->set('Authorization','Bearer '.($token??$consoleBearer));$r->headers->set('Content-Type','application/json');$r->query->replace($query);return $r;
};
$commandCounts=fn()=>array_map(fn($t)=>DB::table($t)->count(),['users','drivers','vehicles','orders','positions','mapa_mobile_bindings','mapa_mobile_sessions']);$commandsBefore=$commandCounts();
check($commands->index($commandReq())->getData(true)['actions']===\MapaSource\SourceCommands::ACTIONS,'source command capabilities expose only three reviewed actions');
check($commands->store($commandReq(null,str_repeat('x',40)))->getStatusCode()===401,'source edits reject invalid native credential');
$commandCrew=Fleetbase\Models\User::where('uuid',$user)->first()->createToken($user,['*']);
check($commands->store($commandReq(null,$commandCrew->plainTextToken))->getStatusCode()===403,'crew cannot edit ASR master records');$commandCrew->accessToken->delete();
foreach(['actor_id'=>$foreign,'company_id'=>$foreign,'url'=>'https://other.invalid'] as $field=>$value){$bad=$commandBody;$bad[$field]=$value;
    check($commands->store($commandReq($bad))->getStatusCode()===422,'source edits reject browser supplied '.$field);}
check($commands->store($commandReq(null,null,['url'=>'https://other.invalid']))->getStatusCode()===422,'source edits reject all query parameters');
$bad=$commandBody;$bad['action']='ambulance.delete';check($commands->store($commandReq($bad))->getStatusCode()===422,'source arbitrary and destructive actions rejected');
$bad=$commandBody;$bad['expected_revision']='old';check($commands->store($commandReq($bad))->getStatusCode()===422,'source edit requires an exact revision');
$bad=$commandBody;$bad['values']['model_year']='2024';check($commands->store($commandReq($bad))->getStatusCode()===422,'source edit keeps typed validation');
check(count($commands->sent)===0,'unauthorized or invalid edits never reach source client');
$commands->enabled=false;check(!$commands->index($commandReq())->getData(true)['enabled'] && $commands->store($commandReq())->getStatusCode()===503,'source editing stays disabled without private configuration');$commands->enabled=true;
$commands->tenant=$foreign;check($commands->store($commandReq())->getStatusCode()===403,'source edits cannot cross authoritative company');$commands->tenant=$company;
$applied=$commands->store($commandReq());$appliedData=$applied->getData(true);
check($applied->getStatusCode()===200 && $appliedData['outcome']==='applied' && $appliedData['refresh_status']==='complete','confirmed source edit returns a verified refresh receipt');
check($commands->sent[0]['actor_id']===$consoleReviewer->uuid && $commands->sent[0]['company_id']===$company,'source edit actor and company derive from native membership');
check(str_contains($applied->headers->get('Cache-Control'),'no-store'),'source command receipts are never cached');
$commands->refreshFail=true;$pending=$commands->store($commandReq())->getData(true);
check($pending['outcome']==='applied' && $pending['refresh_status']==='pending','source save remains applied when follow-up refresh is cooling down');
$commands->operation=function(){throw new App\MapaMobile\SourceCommandRefused(409,'Fetch and review.');};
$conflict=$commands->store($commandReq());check($conflict->getStatusCode()===409 && $conflict->getData(true)['outcome']==='rejected','source conflicts never report successful edits');
$commands->operation=function(){throw new App\MapaMobile\SourceCommandUncertain('DEMO secret');};
$unknown=$commands->store($commandReq());$uncertain=$unknown->getData(true);
check($unknown->getStatusCode()===503 && $uncertain['outcome']==='unknown' && $uncertain['request_id']===$commandBody['request_id'] && !str_contains($unknown->getContent(),'DEMO secret'),'lost save response preserves request identity and never claims rejection');
$commands->store($commandReq());$last=array_slice($commands->sent,-2);
check($last[0]===$last[1],'retry transmits the identical idempotency key and unchanged values');
$revocableCommand=$consoleReviewer->createToken($consoleReviewer->uuid,['*']);
$commands->operation=function($c)use($revocableCommand){$revocableCommand->accessToken->delete();return ['contract'=>$c['contract'],'company_id'=>$c['company_id'],'request_id'=>$c['request_id']];};
$revokedCommand=$commands->store($commandReq(null,$revocableCommand->plainTextToken));
check($revokedCommand->getStatusCode()===401 && $revokedCommand->getData(true)['outcome']==='unknown' && !isset($revokedCommand->getData(true)['revision']),'revoked native session cannot receive committed source receipt');
$refreshRevocable=$consoleReviewer->createToken($consoleReviewer->uuid,['*']);$commands->operation=null;$commands->refreshFail=false;
$commands->refreshOperation=function()use($refreshRevocable){$refreshRevocable->accessToken->delete();};
$refreshRevoked=$commands->store($commandReq(null,$refreshRevocable->plainTextToken));
check($refreshRevoked->getStatusCode()===401 && $refreshRevoked->getData(true)['outcome']==='unknown' && !isset($refreshRevoked->getData(true)['revision']),'native authorization is rechecked after follow-up source refresh');
check($commandCounts()===$commandsBefore,'source commands never mutate native users, dispatch, vehicles, GPS or sessions');

// Transport authenticity tests use an in-process signed fake, never curl or ASR Health.
$commandDir=sys_get_temp_dir().'/mapa-command-DEMO-'.bin2hex(random_bytes(6));mkdir($commandDir,0700);
$commandConfig=$commandDir.'/command.json';$commandSecret=str_repeat('DEMO-private-key-',4);
$clientConfig=['enabled'=>true,'url'=>App\MapaMobile\SourceCommandClient::URL,'secret'=>$commandSecret];file_put_contents($commandConfig,json_encode($clientConfig));
$fakeClient=new class($commandConfig) extends App\MapaMobile\SourceCommandClient {
    public string $secret;public string $mode='success';public int $calls=0;
    protected function transmit(string $url,array $headers,string $body):array{
        $this->calls++;$h=[];foreach($headers as $line){[$k,$v]=explode(':',$line,2);$h[$k]=trim($v);}
        $c=json_decode($body,true);if($this->mode==='timeout')throw new RuntimeException('DEMO lost reply');
        check($url===self::URL && hash_equals(\MapaSource\SourceCommands::signature($this->secret,$h['X-Mapa-Timestamp'],$h['X-Mapa-Nonce'],$body),$h['X-Mapa-Signature']),'source client sends fixed endpoint and signed complete command');
        $r=['contract'=>$c['contract'],'company_id'=>$c['company_id'],'request_id'=>$c['request_id'],'action'=>$c['action'],'record_id'=>$c['record_id'],
            'revision'=>str_repeat('c',64),'applied_at'=>gmdate('c'),'refresh_required'=>true];$status=200;
        if($this->mode==='foreign')$r['company_id']='00000000-0000-0000-0000-000000000000';
        if($this->mode==='request')$r['request_id']='00000000-0000-0000-0000-000000000000';
        if($this->mode==='action')$r['action']='driver.transfer';
        if($this->mode==='record')$r['record_id']='DEMO-other';
        if($this->mode==='extra')$r['secret']='DEMO must not escape';
        if($this->mode==='rejected'||$this->mode==='server'){$r=array_intersect_key($r,array_flip(['contract','company_id','request_id','action','record_id']));$r['outcome']=$this->mode==='rejected'?'rejected':'unknown';$r['error']='DEMO database detail';$status=$this->mode==='rejected'?409:503;}
        $reply=\MapaSource\SourceSync::json($r);$signature=\MapaSource\SourceCommands::responseSignature($this->secret,$h['X-Mapa-Nonce'],$reply);
        return ['status'=>$status,'body'=>$reply,'signature'=>$this->mode==='tamper'?str_repeat('0',64):$signature,'type'=>$this->mode==='html'?'text/html':'application/json'];
    }
};$fakeClient->secret=$commandSecret;
$wire=['contract'=>\MapaSource\SourceCommands::CONTRACT,'company_id'=>\MapaSource\SourceSync::COMPANY,'actor_id'=>$consoleReviewer->uuid]+$commandBody;
check($fakeClient->configured() && $fakeClient->send($wire)['request_id']===$commandBody['request_id'],'source client accepts only authenticated matching receipt');
foreach(['timeout','foreign','request','action','record','extra','tamper','html','server'] as $mode){$fakeClient->mode=$mode;$caught=false;try{$fakeClient->send($wire);}catch(App\MapaMobile\SourceCommandUncertain $e){$caught=true;}check($caught,'source client treats '.$mode.' as an unknown outcome');}
$fakeClient->mode='rejected';$caught=false;try{$fakeClient->send($wire);}catch(App\MapaMobile\SourceCommandRefused $e){$caught=$e->status===409 && !str_contains($e->getMessage(),'DEMO database detail');}check($caught,'only authenticated scoped rejection is surfaced with safe message');
$priorCalls=$fakeClient->calls;$clientConfig['url']='https://other.invalid';file_put_contents($commandConfig,json_encode($clientConfig));
$caught=false;try{$fakeClient->send($wire);}catch(App\MapaMobile\SourceCommandUnavailable $e){$caught=true;}
check($caught && !$fakeClient->configured() && $fakeClient->calls===$priorCalls,'arbitrary source endpoint rejected before transport');
$workflowConfig=$commandDir.'/workflow.json';file_put_contents($workflowConfig,json_encode(['enabled'=>true,'url'=>App\MapaMobile\SourceWorkflowClient::URL,'secret'=>$commandSecret]));
$workflowClient=new class($workflowConfig) extends App\MapaMobile\SourceWorkflowClient {
    public string $secret;public string $mode='success';
    protected function transmit(string $url,array $headers,string $body):array {
        $h=[];foreach($headers as $line){[$key,$value]=explode(':',$line,2);$h[$key]=trim($value);}$command=json_decode($body,true);
        check($url===self::URL&&hash_equals(\MapaSource\SourceWorkflowCommands::signature($this->secret,$h['X-Mapa-Timestamp'],$h['X-Mapa-Nonce'],$body),$h['X-Mapa-Signature']),'workflow uses fixed URL and its separate signed contract');
        $receipt=array_intersect_key($command,array_flip(['contract','company_id','request_id','action','record_id']))+['revision'=>str_repeat('d',64),'applied_at'=>'2026-10-06T14:00:00+05:30','refresh_required'=>true,'daily_status_id'=>'1'];
        if($this->mode==='foreign')$receipt['company_id']='DEMO-FOREIGN';if($this->mode==='extra')$receipt['patient_name']='DEMO PRIVATE';if($this->mode==='identity')$receipt['daily_status_id']='invalid';
        $reply=\MapaSource\SourceSync::json($receipt);return ['status'=>200,'body'=>$reply,'type'=>'application/json','signature'=>$this->mode==='signature'?str_repeat('0',64):\MapaSource\SourceWorkflowCommands::responseSignature($this->secret,$h['X-Mapa-Nonce'],$reply)];
    }
};$workflowClient->secret=$commandSecret;
$workflowWire=['contract'=>\MapaSource\SourceWorkflowCommands::CONTRACT,'company_id'=>\MapaSource\SourceSync::COMPANY,'actor_id'=>$consoleReviewer->uuid,'request_id'=>(string)Illuminate\Support\Str::uuid(),'action'=>'session.start','record_id'=>'1','expected_revision'=>str_repeat('c',64),'values'=>['record_date'=>'2026-10-06','status'=>'On Road','odo_reading'=>'1000.00']];
check($workflowClient->configured()&&$workflowClient->send($workflowWire)['daily_status_id']==='1','signed workflow receipt accepts source timezone');
foreach(['foreign','extra','identity','signature'] as $mode){$workflowClient->mode=$mode;$caught=false;try{$workflowClient->send($workflowWire);}catch(App\MapaMobile\SourceCommandUncertain $e){$caught=true;}check($caught,'workflow rejects '.$mode.' response');}
unlink($workflowConfig);unlink($commandConfig);rmdir($commandDir);

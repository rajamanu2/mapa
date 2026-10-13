<?php
// Included only within disposable native hospital fixtures; no provider sends.
if(!isset($mapaDedicatedQaApp))throw new RuntimeException('Disposable hospital QA required.');
class QaHospitalPush extends App\MapaMobile\PushService {public function available():bool{return true;}public function hospitalAvailable():bool{return true;}}
class QaHospitalFcm extends App\MapaMobile\FcmTransport {
 public array $sent=[];public string $result='accepted';public bool $fail=false;
 public function configured():bool{return true;}
 public function sendHospital(string $token,string $eventId,string $sessionHash,int $issuedAt):string{if($this->fail)throw new RuntimeException('DEMO fake provider failure');$this->sent[]=self::message($token,$eventId,$sessionHash,$issuedAt,'mapa_hospital_changed');return $this->result;}
}
class QaHospitalJob extends App\MapaMobile\DeliverHospitalPush {protected function isTraining($model):bool{return false;}}
$hpush=new QaHospitalPush;$hfcm=new QaHospitalFcm;
$hinput=['installation_id'=>(string)Str::uuid(),'fcm_token'=>'DEMO_HOSPITAL_TOKEN_NEVER_SEND_01234567890'];
denied(fn()=>(new App\MapaMobile\PushService)->register(req($hinput,$ht)),503);
denied(fn()=>$hpush->register(req($hinput,$at)),403);
check($hpush->register(req($hinput,$ht))['registered'],'hospital token registers from bound session');
$hdevice=DB::table('mapa_mobile_hospital_push_devices')->first();
check($hdevice->place_uuid===$hp&&$hdevice->company_uuid===$company&&$hdevice->binding_id===$hpSessions['hospital']['binding'],'hospital device server-owned destination');
check(!str_contains($hdevice->token_encrypted,$hinput['fcm_token']),'hospital FCM token encrypted');
check($hpush->status($service->context(req([],$ht)))['registered'],'hospital registered status');
$hinput['fcm_token'].='_ROTATED';$hpush->register(req($hinput,$ht));check(DB::table('mapa_mobile_hospital_push_devices')->count()===1,'hospital token rotation');
$hevent=hash('sha256','DEMO-hospital-event');
(new App\MapaMobile\DeliverHospitalPush($hpReport['id'],$company,$hp,$hevent,time()))->handle($hfcm,$hpush);check(count($hfcm->sent)===0,'training hospital trip cannot send production alert');
$hjob=fn($event,$issued=null)=>new QaHospitalJob($hpReport['id'],$company,$hp,hash('sha256',$event),$issued??time());
$hjob('first')->handle($hfcm,$hpush);check(count($hfcm->sent)===1,'hospital fake provider receives hint');
$hint=$hfcm->sent[0]['message'];check(array_keys($hint['data'])===['kind','event_id','session_tag','issued_at']&&$hint['data']['kind']==='mapa_hospital_changed','generic hospital allowlist');
check(!isset($hint['notification'])&&!str_contains(json_encode($hint),$hpReport['id'])&&!str_contains(json_encode($hint),'condition'),'provider payload excludes clinical or trip identity');
check($hint['android']['ttl']==='120s'&&$hint['android']['collapse_key']==='mapa_hospital','hospital alert bounded TTL and collapse');
$hjob('first')->handle($hfcm,$hpush);check(count($hfcm->sent)===1,'hospital accepted event deduplicated');
$hjob('old',time()-121)->handle($hfcm,$hpush);$hjob('future',time()+40)->handle($hfcm,$hpush);check(count($hfcm->sent)===1,'stale/future hospital event rejected');
DB::table('payloads')->where('uuid',$hpPayload)->update(['dropoff_uuid'=>$hpOther]);$hjob('changed-destination')->handle($hfcm,$hpush);check(count($hfcm->sent)===1,'destination change suppresses hospital push');DB::table('payloads')->where('uuid',$hpPayload)->update(['dropoff_uuid'=>$hp]);
DB::table('orders')->where('uuid',$hpOrder)->update(['status'=>'completed']);$hjob('closed')->handle($hfcm,$hpush);check(count($hfcm->sent)===1,'ended trip suppresses hospital push');DB::table('orders')->where('uuid',$hpOrder)->update(['status'=>'dispatched']);
DB::table('mapa_mobile_hospital_handovers')->where('id',$hpReport['id'])->update(['status'=>'acknowledged']);$hjob('acknowledged')->handle($hfcm,$hpush);check(count($hfcm->sent)===1,'already acknowledged hospital not alerted');DB::table('mapa_mobile_hospital_handovers')->where('id',$hpReport['id'])->update(['status'=>'incoming']);
DB::table('mapa_mobile_bindings')->where('id',$hpSessions['hospital']['binding'])->update(['place_uuid'=>$hpOther]);$hjob('binding-changed')->handle($hfcm,$hpush);check(count($hfcm->sent)===1&&DB::table('mapa_mobile_hospital_push_devices')->value('revoked_at')!==null,'changed hospital binding revokes delivery');DB::table('mapa_mobile_bindings')->where('id',$hpSessions['hospital']['binding'])->update(['place_uuid'=>$hp]);$hpush->register(req($hinput,$ht));
$hfcm->fail=true;try{$hjob('retry')->handle($hfcm,$hpush);throw new RuntimeException('Expected fake failure');}catch(RuntimeException $e){check($e->getMessage()==='MAPA hospital alert could not be delivered.','provider errors sanitized');}$hfcm->fail=false;$hjob('retry')->handle($hfcm,$hpush);check(count($hfcm->sent)===2,'failed delivery can retry');
$hfcm->result='unregistered';$hjob('invalid')->handle($hfcm,$hpush);check(DB::table('mapa_mobile_hospital_push_devices')->value('revoked_at')!==null,'invalid hospital device revoked');
$hpush->register(req($hinput,$ht));DB::table('mapa_mobile_sessions')->where('token_hash',hash('sha256',$ht))->update(['revoked_at'=>1]);$hjob('logout')->handle($hfcm,$hpush);check(count($hfcm->sent)===3,'hospital logout suppresses pending delivery');DB::table('mapa_mobile_sessions')->where('token_hash',hash('sha256',$ht))->update(['revoked_at'=>null]);
$hpush->register(req($hinput,$ht));$hpush->unregister(req([],$ht));check(!$hpush->status($service->context(req([],$ht)))['registered'],'hospital unregister removes registered status');
DB::table('mapa_mobile_hospital_push_devices')->delete();DB::table('mapa_mobile_hospital_push_deliveries')->delete();

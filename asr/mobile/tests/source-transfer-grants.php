<?php
declare(strict_types=1);
require_once '/mobile/SourceCommandClient.php';
use App\MapaMobile\SourceCommandClient;
use App\MapaMobile\SourceCommandRefused;
use MapaSource\SourceCommands;
use MapaSource\SourceSync;
$dir=sys_get_temp_dir().'/mapa-transfer-grants-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$path=$dir.'/command.json';$actor='11111111-1111-4111-8111-111111111111';$key=str_repeat('DEMO-transfer-only-',4);
file_put_contents($path,json_encode(['enabled'=>true,'url'=>SourceCommandClient::URL,'secret'=>$key,'actors'=>[$actor=>['driver.transfer','ambulance.transfer']]]));
$client=new class($path) extends SourceCommandClient {
    public int $calls=0;public string $key;
    protected function transmit(string $url,array $headers,string $body):array{
        $this->calls++;$c=json_decode($body,true);$nonce='';foreach($headers as $h)if(str_starts_with($h,'X-Mapa-Nonce: '))$nonce=substr($h,14);
        $reply=SourceSync::json(array_intersect_key($c,array_flip(['contract','company_id','request_id','action','record_id']))+['revision'=>str_repeat('b',64),'applied_at'=>'2026-10-06T17:00:00+00:00','refresh_required'=>true]);
        return ['status'=>200,'type'=>'application/json','body'=>$reply,'signature'=>SourceCommands::responseSignature($this->key,$nonce,$reply)];
    }
};$client->key=$key;$n=0;
function ck(bool $ok,string $message):void{global $n;if(!$ok)throw new RuntimeException($message);echo 'PASS '.(++$n).' '.$message."\n";}
$c=['contract'=>SourceCommands::CONTRACT,'company_id'=>SourceSync::COMPANY,'actor_id'=>$actor,'request_id'=>'22222222-2222-4222-8222-222222222222','action'=>'driver.transfer','record_id'=>'1','expected_revision'=>str_repeat('a',64),'values'=>['phc_id'=>'DEMO-PHC']];
try{
    ck($client->allowedActions($actor)===['ambulance.transfer','driver.transfer'],'capabilities expose exactly approved transfers');
    ck($client->allowedActions('33333333-3333-4333-8333-333333333333')===[],'ungranted admin has no capabilities');
    ck($client->send($c)['request_id']===$c['request_id'],'approved transfer uses verified receipt');
    foreach(['foreign','profile'] as $case){$bad=$c;if($case==='foreign')$bad['actor_id']='33333333-3333-4333-8333-333333333333';else{$bad['action']='ambulance.profile';$bad['values']=['vehicle_model'=>'DEMO','model_year'=>2024];}$before=$client->calls;$denied=false;try{$client->send($bad);}catch(SourceCommandRefused $e){$denied=$e->status===403;}ck($denied&&$client->calls===$before,$case.' rejected before source network write');}
    echo "SOURCE TRANSFER GRANTS PASS $n checks\n";
}finally{unlink($path);rmdir($dir);}

<?php
/** Pure current-assignment acknowledgement guards; no DB or operational writes. */
require __DIR__.'/../CommandDeskPolicy.php';
use App\MapaVoice\CommandDeskPolicy as P;
$order=(object)['uuid'=>'DEMO-order','company_uuid'=>'DEMO-company','status'=>'started','meta'=>'{}'];
$driver=(object)['uuid'=>'DEMO-driver','vehicle_uuid'=>'DEMO-vehicle'];$vehicle=(object)['uuid'=>'DEMO-vehicle'];
$now=1791300000000;$checks=0;
foreach([null,0,$now+1] as $ack){if(P::tripProgress($order,$driver,$vehicle,$ack,$now)['accepted'])throw new RuntimeException('Invalid acknowledgement accepted');$checks++;}
if(!P::tripProgress($order,$driver,$vehicle,$now-1,$now)['accepted'])throw new RuntimeException('Current acknowledgement rejected');$checks++;
$driver->vehicle_uuid='DEMO-other-vehicle';
if(P::tripProgress($order,$driver,$vehicle,$now-1,$now)['accepted'])throw new RuntimeException('Changed assignment accepted');$checks++;
echo "$checks acknowledgement-state checks passed; zero network/production writes.\n";

<?php
declare(strict_types=1);
// Standalone policy/transport check: no server, database or real notification.
$settings=[];
function config(string $key, mixed $default=null): mixed { return $GLOBALS['settings'][$key]??$default; }
require_once __DIR__.'/../DispatchAuthority.php';
require_once __DIR__.'/../SourceCommandClient.php';
require_once __DIR__.'/../SourceWorkflowClient.php';
require_once __DIR__.'/../SourceMasterClient.php';
require_once __DIR__.'/../SourceDispatchClient.php';
require_once __DIR__.'/../SourceDispatchSaga.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$check(!App\MapaMobile\DispatchAuthority::mapaControls(),'Missing setting must retain compatibility authority');
foreach(['asr','MAPA','invalid',''] as $value){$settings['mapa_mobile.dispatch_authority']=$value;$check(!App\MapaMobile\DispatchAuthority::mapaControls(),'Only explicit mapa is a cutover');}
$settings=['mapa_mobile.dispatch_authority'=>'mapa','mapa_mobile.source_dispatch_coordination_enabled'=>true];
$check(App\MapaMobile\DispatchAuthority::mapaControls(),'Explicit cutover selects MAPA');
$check(!App\MapaMobile\SourceDispatchSaga::enabled(),'Legacy coordination flag cannot restore ASR dispatch');
foreach([App\MapaMobile\SourceCommandClient::class,App\MapaMobile\SourceWorkflowClient::class,App\MapaMobile\SourceMasterClient::class,App\MapaMobile\SourceDispatchClient::class] as $class){
    $client=new $class(__DIR__.'/DOES-NOT-EXIST.json');
    $check(!$client->configured(),'Source outbound adapter must be unavailable');
    try{$client->send([]);throw new RuntimeException('Source adapter unexpectedly sent a request');}
    catch(App\MapaMobile\SourceCommandUnavailable|App\MapaMobile\SourceDispatchUnavailable $e){$check(str_contains($e->getMessage(),'read-only'),'Authority rejection occurs before reading credentials, validation or transport');}
}
$settings['mapa_mobile.dispatch_authority']='asr';
$check(App\MapaMobile\SourceDispatchSaga::enabled(),'Explicit compatibility rollback retains legacy coordination');
echo "PASS dispatch authority: {$checks} checks\n";

<?php
/** Pure no-network checks; no database, calls, orders, or notifications. */
require ($argv[1]??'/fleetbase/api/vendor/autoload.php');
require __DIR__.'/../Policy.php';
require __DIR__.'/../VoiceService.php';
$mobileDirectory=dirname(__DIR__,2).'/mobile';
if(!is_file($mobileDirectory.'/ConnectedTrackingPolicy.php'))$mobileDirectory=dirname(__DIR__,2).'/MapaMobile';
require $mobileDirectory.'/ConnectedTrackingPolicy.php';

class PilotPolicyProbe extends App\MapaVoice\VoiceService {
    public function details(array $values): void {$this->checkSupervisedPilotDetails($values);}
    public function marked(object $call): bool {return $this->supervisedPilot($call);}
}
function pilotCheck(bool $pass,string $name): void {
    if(!$pass)throw new RuntimeException($name);
    $GLOBALS['pilot_checks'][]=$name;
}
function pilotDenied(callable $action,string $name): void {
    try{$action();}catch(Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){
        pilotCheck($e->getStatusCode()===422,$name);return;
    }
    throw new RuntimeException('Expected denial: '.$name);
}
$GLOBALS['pilot_checks']=[];
$service=new PilotPolicyProbe();
$valid=['caller_name'=>'MAPA PILOT — NO PATIENT','callback_number'=>'',
    'reported_problem'=>'SUPERVISED MAPA PILOT — NO PATIENT. Two-crew drill.',
    'pickup_address'=>'Araku Railway Station test point','latitude'=>18.332922,'longitude'=>82.865258];
$service->details($valid);
pilotCheck(true,'Explicit no-patient test label and map point are accepted');
foreach([['caller_name'=>'Real patient'],['callback_number'=>'+919876543210'],
    ['patient_name'=>'Person'],['patient_gender'=>'unknown'],['patient_count'=>1],['patient_age'=>30],
    ['incident_type'=>'Medical'],['consciousness'=>'unknown'],['breathing'=>'unknown'],
    ['scene_safety'=>'unknown'],['destination_phc_id'=>'PHC-1'],['destination_facility_name'=>'Hospital'],
    ['reported_problem'=>'Patient needs ambulance'],['pickup_address'=>''],
    ['latitude'=>null],['longitude'=>null]] as $change)
    pilotDenied(fn()=>$service->details([...$valid,...$change]),'Unsafe pilot details are denied');
$pilot=(object)['draft'=>json_encode(['supervised_pilot'=>true])];
$real=(object)['draft'=>json_encode(['caller_name'=>'Real caller'])];
pilotCheck($service->marked($pilot)&&!$service->marked($real),'Only persisted pilot marker selects the exceptional review path');
pilotCheck(App\MapaMobile\ConnectedTrackingPolicy::supervisedPilot($pilot)
    &&!App\MapaMobile\ConnectedTrackingPolicy::supervisedPilot($real),
    'Caller tracking policy distinguishes pilot from ordinary call');
echo json_encode(['ok'=>true,'checks'=>count($GLOBALS['pilot_checks']),
    'production_rows_written'=>0,'notifications_sent'=>0],JSON_PRETTY_PRINT).PHP_EOL;

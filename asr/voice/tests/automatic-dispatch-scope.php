<?php
/** Pure pilot-scope policy test; no database, phones, or operational writes. */
require __DIR__.'/../Policy.php';
require __DIR__.'/../AutomaticDispatchPolicy.php';
require __DIR__.'/../AutomaticDispatchScope.php';

function config(string $key): mixed {return $GLOBALS['pilot_config'][$key] ?? null;}
function verify(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException('FAILED: '.$label);
    $GLOBALS['checks'][] = $label;
}

use App\MapaVoice\AutomaticDispatchScope as Scope;
$GLOBALS['checks'] = [];
$call = 'ccf2ae67-5a08-45fb-b489-32f62a09da41';
$otherCall = '0d844da2-76c9-4d7d-979e-c0a2ff119fab';
$one = 'e4f8f563-6b61-42dc-a89c-d4829ce916d8';
$two = 'bf704db9-6e37-4cbe-bfd7-bdd6cf820b78';
$other = '8777ac9c-f398-4869-83b0-5279c81df746';

verify(Scope::configured() === null && !Scope::allowsCallId($call) && !Scope::allowsDriverUuid($one), 'Unconfigured pilot denies all calls and drivers');
$GLOBALS['pilot_config'] = ['mapa_voice.automatic_dispatch_pilot_call_id' => $call,
    'mapa_voice.automatic_dispatch_pilot_driver_uuids' => $one.', '.$two];
verify(Scope::configured() !== null && Scope::allowsCallId($call) && !Scope::allowsCallId($otherCall), 'Only the exact pilot call is allowed');
verify(Scope::allowsDriverUuid($one) && Scope::allowsDriverUuid($two) && !Scope::allowsDriverUuid($other), 'Only the listed native driver UUIDs are allowed');
verify(Scope::driverUuids() === [$one,$two], 'Driver list is normalized without broadening scope');
foreach (['', $one.',', $one.','.$one, $one.','.$other.',bad',
    implode(',', array_fill(0,6,$one))] as $invalid) {
    $GLOBALS['pilot_config']['mapa_voice.automatic_dispatch_pilot_driver_uuids'] = $invalid;
    verify(Scope::configured() === null && !Scope::allowsCallId($call) && !Scope::allowsDriverUuid($one),
        'Malformed or excessive driver list fails closed: '.($invalid ?: '(empty)'));
}
$GLOBALS['pilot_config']['mapa_voice.automatic_dispatch_pilot_driver_uuids'] = $one.','.$two;
$GLOBALS['pilot_config']['mapa_voice.automatic_dispatch_pilot_call_id'] = '';
verify(Scope::configured() === null && !Scope::allowsDriverUuid($one), 'Removing the pilot call disables driver offers');
echo json_encode(['ok' => true, 'checks' => count($GLOBALS['checks']), 'cases' => $GLOBALS['checks'],
    'production_rows_written' => 0], JSON_PRETTY_PRINT)."\n";

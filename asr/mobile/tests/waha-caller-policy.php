<?php
// Run directly with PHP: no network, database, or WhatsApp account is used.
$settings = [
 'mapa_mobile.whatsapp.waha_caller_enabled' => true,
 'mapa_text.waha_url' => 'http://waha:3000',
 'mapa_text.default_sender' => '919999999999',
 'mapa_text.phone_id' => '919999999999',
 'mapa_text.waha_api_key' => str_repeat('x', 64),
 'mapa_text.tracking_host' => 'app.itda.asrhealth.in',
];
function config(string $key): mixed { global $settings; return $settings[$key] ?? null; }
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); }
require __DIR__ . '/../CallerPhone.php';
require __DIR__ . '/../../text/TrackerMessage.php';
require __DIR__ . '/../WahaCallerTransport.php';

use App\MapaMobile\WahaCallerTransport;
$url = 'https://app.itda.asrhealth.in/track/#' . str_repeat('a', 64);
check(WahaCallerTransport::configReady(), 'Private bridge configuration');
$message = WahaCallerTransport::message('919999999999', $url);
check($message['chatId'] === '919999999999@c.us', 'Reviewed caller number is recipient');
check(str_contains($message['text'], $url) && str_contains($message['text'], 'అంబులెన్స్'), 'English and Telugu tracker');
check(WahaCallerTransport::outcome(201, ['_data' => ['id' => ['_serialized' => 'provider-id']]])['status'] === 'accepted', 'Provider accepted');
check(WahaCallerTransport::outcome(201, [])['status'] === 'unknown', 'Missing receipt is unknown');
check(WahaCallerTransport::outcome(503, [])['status'] === 'unknown', 'Server failure is unknown');
check(WahaCallerTransport::outcome(422, [])['status'] === 'rejected', 'Definite rejection');
foreach (['http://evil.example/track/#' . str_repeat('a', 64),
          'https://app.itda.asrhealth.in/track/#bad',
          'https://app.itda.asrhealth.in/track/?next=x#' . str_repeat('a', 64)] as $bad) {
 try { WahaCallerTransport::message('919999999999', $bad); throw new RuntimeException('Bad tracker accepted'); }
 catch (InvalidArgumentException $expected) {}
}
foreach ([['mapa_mobile.whatsapp.waha_caller_enabled', false],
          ['mapa_text.waha_url', 'http://evil.example:3000'],
          ['mapa_text.default_sender', '919999999999'],
          ['mapa_text.waha_api_key', 'short']] as [$key, $bad]) {
 $old = $settings[$key]; $settings[$key] = $bad;
 check(!WahaCallerTransport::configReady(), $key . ' must fail closed');
 $settings[$key] = $old;
}
echo "WAHA caller policy passed\n";

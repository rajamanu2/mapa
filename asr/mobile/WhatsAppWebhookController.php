<?php
namespace App\MapaMobile;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
class WhatsAppWebhookController {
 private function run(callable $op){
  try{$response=$op(new WhatsAppWebhook);}
  catch(HttpExceptionInterface $e){$response=response()->json(['error'=>$e->getMessage()],$e->getStatusCode());}
  catch(\Throwable $e){$response=response()->json(['error'=>'Webhook unavailable.'],503);}
  return $response->header('Cache-Control','no-store')->header('X-Content-Type-Options','nosniff');
 }
 public function verify(Request $r){return $this->run(fn($s)=>response($s->verify($r->query('hub_mode',$r->query('hub.mode')),$r->query('hub_verify_token',$r->query('hub.verify_token')),$r->query('hub_challenge',$r->query('hub.challenge'))),200)->header('Content-Type','text/plain'));}
 public function receive(Request $r){return $this->run(function($s)use($r){if(!$r->isJson())MobileService::fail('JSON required.',415);return response()->json($s->receive($r->getContent(),(string)$r->header('X-Hub-Signature-256')));});}
}

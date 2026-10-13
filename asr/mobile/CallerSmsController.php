<?php
namespace App\MapaMobile;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
class CallerSmsController {
 private function run(Request $r,callable $op){try{if(strlen($r->getContent())>4096)MobileService::fail('Request is too large.',413);if($r->isMethod('POST')&&!$r->isJson())MobileService::fail('JSON is required.',415);$body=$op(new CallerSmsService);$status=200;}catch(ValidationException $e){$body=['error'=>'Invalid SMS request.'];$status=422;}catch(HttpExceptionInterface $e){$body=['error'=>$e->getMessage()];$status=$e->getStatusCode();}catch(\Throwable $e){$body=['error'=>'SMS status unavailable. Do not resend until receipt is checked.'];$status=503;}return response()->json($body,$status)->header('Cache-Control','no-store')->header('Referrer-Policy','no-referrer')->header('X-Content-Type-Options','nosniff');}
 public function status(Request $r){return $this->run($r,fn($s)=>$s->status($r));}
 public function forCall(Request $r,string $id){return $this->run($r,fn($s)=>$s->forCall($r,$id));}
 public function send(Request $r,string $id){return $this->run($r,fn($s)=>$s->send($r,$id));}
 public function auto(Request $r,string $id){return $this->run($r,fn($s)=>$s->auto($r,$id));}
}

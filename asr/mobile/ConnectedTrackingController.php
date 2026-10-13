<?php
namespace App\MapaMobile;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
class ConnectedTrackingController
{
    private function run(Request $r,callable $operation) {
        try {
            if(strlen($r->getContent())>16384)MobileService::fail('Request is too large.',413);
            if($r->isMethod('POST')&&!$r->isJson())MobileService::fail('JSON is required.',415);
            $body=$operation(new ConnectedTrackingService);$status=200;
        } catch(ValidationException $e){$body=['error'=>'Invalid request.','fields'=>$e->errors()];$status=422;
        } catch(HttpExceptionInterface $e){$body=['error'=>$e->getMessage()];$status=$e->getStatusCode();
        } catch(\Throwable $e){logger()->error('MAPA connected tracking failed.',['class'=>get_class($e)]);$body=['error'=>'Tracking unavailable.'];$status=503;}
        return response()->json($body,$status)->header('Cache-Control','no-store')->header('Referrer-Policy','no-referrer')->header('X-Content-Type-Options','nosniff');
    }
    public function issue(Request $r,string $id){return $this->run($r,fn($s)=>$s->issue($r,$id));}
    public function revoke(Request $r,string $id){return $this->run($r,fn($s)=>$s->revoke($r,$id));}
    public function locations(Request $r,string $id){return $this->run($r,fn($s)=>$s->locations($r,$id));}
    public function pickup(Request $r,string $id){return $this->run($r,fn($s)=>(new ConfirmCallerPickupService)->confirm($r,$id));}
    public function view(Request $r){return $this->run($r,fn($s)=>$s->view($r));}
    public function location(Request $r){return $this->run($r,fn($s)=>$s->location($r));}
    public function devices(Request $r){return $this->run($r,fn($s)=>$s->devices($r));}
    public function createDevice(Request $r){return $this->run($r,fn($s)=>$s->createDevice($r));}
    public function revokeDevice(Request $r,string $id){return $this->run($r,fn($s)=>$s->revokeDevice($r,$id));}
    public function ingest(Request $r){return $this->run($r,fn($s)=>$s->ingest($r));}
}

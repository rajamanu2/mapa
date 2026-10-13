<?php
namespace App\MapaMobile;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
class HospitalHandoverController
{
    private function run(Request $r,callable $operation) {
        try {
            if(strlen($r->getContent())>16384) MobileService::fail('Request is too large.',413);
            if($r->isMethod('POST')&&!$r->isJson()) MobileService::fail('JSON is required.',415);
            $body=$operation(new HospitalHandoverService); $status=200;
        } catch(ValidationException $e){ $body=['error'=>'Invalid request.','fields'=>$e->errors()];$status=422;
        } catch(HttpExceptionInterface $e){ $body=['error'=>$e->getMessage()];$status=$e->getStatusCode();
        } catch(\Throwable $e){ logger()->error('MAPA hospital handover failed.',['class'=>get_class($e)]);$body=['error'=>'Hospital handover unavailable.'];$status=503; }
        return response()->json($body,$status)->header('Cache-Control','no-store')->header('X-Content-Type-Options','nosniff');
    }
    public function overview(Request $r){return $this->run($r,fn($s)=>$s->overview($r));}
    public function onboard(Request $r,string $id){return $this->run($r,fn($s)=>$s->onboard($r,$id));}
    public function incoming(Request $r){return $this->run($r,fn($s)=>$s->incoming($r));}
    public function acknowledge(Request $r,string $id){return $this->run($r,fn($s)=>$s->respond($r,$id));}
}

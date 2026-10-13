<?php
namespace App\MapaMobile;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class MobileController
{
    private function respond(Request $request, callable $operation)
    {
        $media=new PrivateMedia;
        try {
            if (strlen($request->getContent()) > ($request->is('mapa/mobile/v1/orders/*/activity')?EnrollmentPolicy::MAX_BODY:16384)) MobileService::fail('Request is too large.',413);
            if ($request->isMethod('POST') && !$request->isJson()) MobileService::fail('JSON is required.',415);
            $result=$operation(new MobileService($media)); $media->committed();
            return response()->json($result)->header('Cache-Control','no-store')->header('X-Content-Type-Options','nosniff');
        } catch (ValidationException $e) { $media->rollback(); return response()->json(['error'=>'Invalid request.','fields'=>$e->errors()],422)->header('Cache-Control','no-store');
        } catch (HttpExceptionInterface $e) { $media->rollback(); return response()->json(['error'=>$e->getMessage()],$e->getStatusCode())->header('Cache-Control','no-store');
        } catch (\Throwable $e) { $media->rollback(); logger()->error('MAPA mobile operation failed.',['class'=>get_class($e)]); return response()->json(['error'=>'MAPA mobile service unavailable.'],503)->header('Cache-Control','no-store'); }
    }
    public function login(Request $r) { return $this->respond($r,fn($s)=>$s->login($r)); }
    public function capabilities(Request $r) { return $this->respond($r,fn($s)=>$s->capabilities($s->context($r))); }
    public function snapshot(Request $r) { return $this->respond($r,fn($s)=>$s->snapshot($s->context($r))); }
    public function travel(Request $r) { return $this->respond($r,fn($s)=>(new TravelEvidence)->inspect($r,$s->context($r))); }
    public function logout(Request $r) { return $this->respond($r,fn($s)=>$s->logout($s->context($r))); }
    public function duty(Request $r) { return $this->respond($r,fn($s)=>$s->mutate($r,'duty')); }
    public function gps(Request $r) { return $this->respond($r,fn($s)=>$s->mutate($r,'gps')); }
    public function ack(Request $r, string $id) { return $this->respond($r,fn($s)=>$s->mutate($r,'ack',$id)); }
    public function arrivals(Request $r, string $id) { return $this->respond($r,fn($s)=>(new TripArrivalService)->recordArrival($r,$id)); }
    public function activity(Request $r, string $id) { return $this->respond($r,fn($s)=>$s->mutate($r,'activity',$id)); }
}

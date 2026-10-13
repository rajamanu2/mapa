<?php
namespace App\MapaMobile;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** A narrow native-auth proxy, despite the retained descriptive class name. */
class ConsoleReviewSession
{
    private function respond(Request $request,callable $operation)
    {
        $media=new PrivateMedia;
        try {
            if(strlen($request->getContent())>16384) MobileService::fail('Request is too large.',413);
            if($request->isMethod('POST') && !$request->isJson()) MobileService::fail('JSON is required.',415);
            $service=new ConsoleEnrollmentService($media);
            $service->context($request);
            $result=$operation($service);
            $media->committed();
            return is_array($result)
                ? response()->json($result)->header('Cache-Control','no-store')->header('X-Content-Type-Options','nosniff')
                : $result;
        } catch(ValidationException $e) {
            $media->rollback();
            return response()->json(['error'=>'Invalid request.','fields'=>$e->errors()],422)->header('Cache-Control','no-store');
        } catch(HttpExceptionInterface $e) {
            $media->rollback();
            return response()->json(['error'=>$e->getMessage()],$e->getStatusCode())->header('Cache-Control','no-store');
        } catch(\Throwable $e) {
            $media->rollback();
            logger()->error('MAPA native crew review failed.',['class'=>get_class($e)]);
            return response()->json(['error'=>'Native crew review is unavailable.'],503)->header('Cache-Control','no-store');
        }
    }
    public function identity(Request $r) { return $this->respond($r,fn($s)=>$s->identity($r)); }
    public function sourceCalls(Request $r) { return $this->respond($r,fn($s)=>(new SourceCallStore)->summary($s->context($r)['company'])); }
    public function intake(Request $r) { return $this->respond($r,fn($s)=>$s->intake($r)); }
    public function index(Request $r) { return $this->respond($r,fn($s)=>$s->index($r)); }
    public function options(Request $r) { return $this->respond($r,fn($s)=>$s->options($r)); }
    public function reports(Request $r) { return $this->respond($r,fn($s)=>$s->reports($r)); }
    public function media(Request $r,string $id) { return $this->respond($r,fn($s)=>$s->media($r,$id)); }
    public function ambulance(Request $r,string $id) { return $this->respond($r,fn($s)=>$s->createResource($r,$id,'ambulance')); }
    public function hospital(Request $r,string $id) { return $this->respond($r,fn($s)=>$s->createResource($r,$id,'hospital')); }
    public function approve(Request $r,string $id) { return $this->respond($r,fn($s)=>$s->review($r,$id,'approve')); }
    public function reject(Request $r,string $id) { return $this->respond($r,fn($s)=>$s->review($r,$id,'reject')); }
    public function revoke(Request $r,string $id) { return $this->respond($r,fn($s)=>$s->review($r,$id,'revoke')); }
}

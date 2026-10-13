<?php
namespace App\MapaMobile;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
class EnrollmentController
{
    private function respond(Request $r,callable $fn,bool $large=false) {
        $media=new PrivateMedia;
        try {
            if(strlen($r->getContent())>($large?EnrollmentPolicy::MAX_BODY:16384)) MobileService::fail('Request is too large.',413);
            if($r->isMethod('POST')&&!$r->isJson()) MobileService::fail('JSON is required.',415);
            $result=$fn(new EnrollmentService($media)); $media->committed();
            return is_array($result) ? response()->json($result)->header('Cache-Control','no-store')->header('X-Content-Type-Options','nosniff') : $result;
        } catch(ValidationException $e) { $media->rollback(); return response()->json(['error'=>'Invalid request.','fields'=>$e->errors()],422)->header('Cache-Control','no-store'); }
        catch(HttpExceptionInterface $e) { $media->rollback(); return response()->json(['error'=>$e->getMessage()],$e->getStatusCode())->header('Cache-Control','no-store'); }
        catch(\Throwable $e) { $media->rollback(); logger()->error('MAPA crew enrollment operation failed.',['class'=>get_class($e)]); return response()->json(['error'=>'MAPA crew enrollment unavailable.'],503)->header('Cache-Control','no-store'); }
    }
    public function register(Request $r) { return $this->respond($r,fn($s)=>$s->register($r),true); }
    public function status(Request $r) { return $this->respond($r,fn($s)=>$s->status($r)); }
    public function connect(Request $r) { return $this->respond($r,fn($s)=>$s->connect($r)); }
    public function disconnect(Request $r) { return $this->respond($r,fn($s)=>$s->disconnect($r)); }
    public function index(Request $r) { return $this->respond($r,fn($s)=>$s->index($r)); }
    public function options(Request $r) { return $this->respond($r,fn($s)=>$s->options($r)); }
    public function ambulance(Request $r,string $id) { return $this->respond($r,fn($s)=>$s->createResource($r,$id,'ambulance')); }
    public function hospital(Request $r,string $id) { return $this->respond($r,fn($s)=>$s->createResource($r,$id,'hospital')); }
    public function approve(Request $r,string $id) { return $this->respond($r,fn($s)=>$s->review($r,$id,'approve')); }
    public function reject(Request $r,string $id) { return $this->respond($r,fn($s)=>$s->review($r,$id,'reject')); }
    public function revoke(Request $r,string $id) { return $this->respond($r,fn($s)=>$s->review($r,$id,'revoke')); }
    public function reports(Request $r) { return $this->respond($r,fn($s)=>$s->reports($r)); }
    public function media(Request $r,string $id) { return $this->respond($r,fn($s)=>$s->media($r,$id)); }
}

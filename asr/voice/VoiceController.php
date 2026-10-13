<?php
namespace App\MapaVoice;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
class VoiceController
{
    private function respond(Request $r,callable $op) {
        try {
            if(strlen($r->getContent())>32768) VoiceService::fail('Request is too large.',413);
            if($r->isMethod('POST')&&!$r->isJson()) VoiceService::fail('JSON is required.',415);
            return response()->json($op(new VoiceService()))->header('Cache-Control','no-store')->header('X-Content-Type-Options','nosniff');
        } catch(ValidationException $e) {return response()->json(['error'=>'Invalid request.','fields'=>$e->errors()],422)->header('Cache-Control','no-store');
        } catch(HttpExceptionInterface $e) {return response()->json(['error'=>$e->getMessage()],$e->getStatusCode())->header('Cache-Control','no-store');
        } catch(\Throwable $e) {
            // SQL exception bindings can include private caller details. Do not report raw exceptions.
            logger()->error('MAPA voice operation failed',['class'=>get_class($e)]);
            return response()->json(['error'=>'MAPA voice service unavailable.'],503)->header('Cache-Control','no-store');
        }
    }
    public function sourceCalls(Request $r){return $this->respond($r,fn($s)=>(new SourceCallerService)->indexSource($r));}
    public function sourceOpen(Request $r,string $id){return $this->respond($r,fn($s)=>(new SourceCallerService)->openSource($r,$id));}
    public function index(Request $r){return $this->respond($r,fn($s)=>$s->index($r));}
    public function commandDesk(Request $r){return $this->respond($r,fn($s)=>$s->commandDesk($r));}
    public function automaticSettings(Request $r){return $this->respond($r,fn($s)=>(new DispatchSettingsService)->showSettings($r));}
    public function automaticSettingsSave(Request $r){return $this->respond($r,fn($s)=>(new DispatchSettingsService)->saveSettings($r));}
    public function automaticStart(Request $r,string $id){return $this->respond($r,fn($s)=>(new AutomaticDispatch)->control($r,$id,'start'));}
    public function automaticStop(Request $r,string $id){return $this->respond($r,fn($s)=>(new AutomaticDispatch)->control($r,$id,'stop'));}
    public function offers(Request $r){return $this->respond($r,fn($s)=>(new AutomaticDispatch)->offers($r));}
    public function offerResponse(Request $r,string $id){return $this->respond($r,fn($s)=>(new AutomaticDispatch)->respondOffer($r,$id));}
    public function manual(Request $r){return $this->respond($r,fn($s)=>$s->mutate($r,'manual'));}
    public function locations(Request $r){return $this->respond($r,fn($s)=>$s->locations($r));}
    public function nearby(Request $r){return $this->respond($r,fn($s)=>$s->nearby($r));}
    public function show(Request $r,string $id){return $this->respond($r,fn($s)=>$s->show($r,$id));}
    public function bridge(Request $r){return $this->respond($r,fn($s)=>$s->bridge($r));}
    public function demo(Request $r){return $this->respond($r,fn($s)=>$s->mutate($r,'demo'));}
    public function extract(Request $r,string $id){return $this->respond($r,fn($s)=>$s->extract($r,$id));}
    public function review(Request $r,string $id){return $this->respond($r,fn($s)=>$s->mutate($r,'review',$id));}
    public function dispatch(Request $r,string $id){return $this->respond($r,fn($s)=>$s->mutate($r,'dispatch',$id));}
    public function dismiss(Request $r,string $id){return $this->respond($r,fn($s)=>$s->mutate($r,'dismiss',$id));}
}

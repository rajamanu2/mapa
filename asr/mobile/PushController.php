<?php
namespace App\MapaMobile;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PushController
{
    private function respond(Request $r, callable $fn)
    {
        try {
            if ($r->isMethod('POST') && !$r->isJson()) MobileService::fail('JSON is required.',415);
            $result = $fn(); $status = 200;
        } catch (ValidationException $e) { $result=['error'=>'Invalid push registration.']; $status=422;
        } catch (HttpExceptionInterface $e) { $result=['error'=>$e->getMessage()]; $status=$e->getStatusCode();
        } catch (\Throwable $e) { $result=['error'=>'MAPA push service unavailable.']; $status=503; }
        return response()->json($result,$status)->header('Cache-Control','no-store')->header('X-Content-Type-Options','nosniff');
    }
    public function status(Request $r) { return $this->respond($r,fn()=>(new PushService)->status((new MobileService)->context($r))); }
    public function register(Request $r) { return $this->respond($r,fn()=>(new PushService)->register($r)); }
    public function unregister(Request $r) { return $this->respond($r,fn()=>(new PushService)->unregister($r)); }
}

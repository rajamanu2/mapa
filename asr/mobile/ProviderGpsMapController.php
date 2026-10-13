<?php
namespace App\MapaMobile;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ProviderGpsMapController
{
    public function console(Request $request){return $this->run(fn()=>(new ConsoleEnrollmentService(new PrivateMedia))->context($request));}
    public function mobile(Request $request){return $this->run(fn()=>(new MobileService)->context($request));}
    public function journey(Request $request,string $vehicleId)
    {
        try{$context=(new ConsoleEnrollmentService(new PrivateMedia))->context($request);$body=(new ProviderGpsSnapshotService)->journey($context,$vehicleId);$status=200;}
        catch(HttpExceptionInterface $e){$body=['error'=>$e->getMessage()];$status=$e->getStatusCode();}
        catch(\Throwable $e){logger()->warning('MAPA vehicle journey unavailable.',['class'=>get_class($e)]);$body=['error'=>'Vehicle journey is unavailable.'];$status=503;}
        return response()->json($body,$status)->header('Cache-Control','no-store')->header('Referrer-Policy','no-referrer')->header('X-Content-Type-Options','nosniff');
    }
    private function run(callable $context)
    {
        try{$body=(new ProviderGpsSnapshotService)->read($context());$status=200;}
        catch(HttpExceptionInterface $e){$body=['error'=>$e->getMessage()];$status=$e->getStatusCode();}
        catch(\Throwable $e){logger()->warning('MAPA provider map unavailable.',['class'=>get_class($e)]);$body=['error'=>'Provider map data is unavailable.'];$status=503;}
        return response()->json($body,$status)->header('Cache-Control','no-store')->header('Referrer-Policy','no-referrer')->header('X-Content-Type-Options','nosniff');
    }
}

<?php
namespace App\MapaMobile;
use Illuminate\Http\Request;
class BoundedRequest
{
    public function handle(Request $request, \Closure $next)
    {
        // Only camera registration/completion endpoints accept a bounded larger body.
        $camera=$request->isMethod('POST') && ($request->is('mapa/mobile/v1/enrollments') || $request->is('mapa/mobile/v1/orders/*/activity'));
        $webhook=$request->isMethod('POST') && $request->is('mapa/mobile/v1/tracking/whatsapp/webhook');
        $limit=$camera?EnrollmentPolicy::MAX_BODY:($webhook?65536:16384);
        if (strlen((string)$request->header('Authorization'))>128 || (int)$request->header('Content-Length',0)>$limit || strlen($request->getContent())>$limit)
            return response()->json(['error'=>'Request exceeds mobile limits.'],413)->header('Cache-Control','no-store');
        return $next($request);
    }
}

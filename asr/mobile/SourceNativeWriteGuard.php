<?php
namespace App\MapaMobile;
use Closure;
use Illuminate\Http\Request;

/** Coordinated fleet mutations use the reviewed adapters, never a legacy assignment shortcut. */
class SourceNativeWriteGuard
{
    public function handle(Request $request,Closure $next)
    {
        if(!config('mapa_mobile.source_dispatch_coordination_enabled',false)
            ||in_array($request->method(),['GET','HEAD','OPTIONS'],true)
            ||!preg_match('~^(?:api/)?(?:int/)?v1/(?:orders|drivers|vehicles|fleets)(?:/|$)~D',ltrim(rawurldecode($request->path()),'/')))return $next($request);
        return response()->json(['error'=>'Use the reviewed call or ASR integration workflow for this fleet change.'],409)
            ->header('Cache-Control','no-store, private');
    }
}

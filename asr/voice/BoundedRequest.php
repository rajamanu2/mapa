<?php
namespace App\MapaVoice;
class BoundedRequest {
    public function handle($r,\Closure $next){
        if(strlen((string)$r->header('Authorization'))>256||(int)$r->header('Content-Length',0)>32768||strlen($r->getContent())>32768)
            return response()->json(['error'=>'Request exceeds voice limits.'],413)->header('Cache-Control','no-store');
        return $next($r);
    }
}

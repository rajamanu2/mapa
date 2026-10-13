<?php
namespace App\MapaMaintenance;
class RequestGuard
{
    public function handle($request, \Closure $next) {
        if ($request->isMethod('POST')) {
            abort_unless($request->isJson() && strlen($request->getContent()) <= 4096, 413, 'A bounded JSON request is required.');
            $origin = $request->header('Origin');
            if ($origin) abort_unless($origin === $request->getSchemeAndHttpHost(), 403, 'Same-origin maintenance request required.');
        }
        return $next($request);
    }
}

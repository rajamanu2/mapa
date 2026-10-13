<?php
namespace App\MapaMobile;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** One-use launch grants. A child session never outlives its native authorization. */
class ConsoleIntakeLaunch extends ConsoleEnrollmentService
{
    public function launch(Request $request)
    {
        return $this->respond(function() use ($request) {
            $context=$this->context($request);
            if(!config('mapa_voice.enabled')) self::fail('Call intake is unavailable.',503);
            $ticket=bin2hex(random_bytes(32));
            Cache::put('mapa:intake:launch:'.hash('sha256',$ticket),[
                'credential'=>Crypt::encryptString((string)$request->bearerToken()),
                'user'=>$context['user']->uuid,'company'=>$context['company'],'expires'=>time()+60
            ],60);
            return ['ticket'=>$ticket,'expires_at'=>(time()+60)*1000];
        });
    }
    public function exchange(Request $request)
    {
        return $this->respond(function() use ($request) {
            $input=$request->validate(['ticket'=>'required|string|regex:/^[a-f0-9]{64}$/D']);
            $key='mapa:intake:launch:'.hash('sha256',$input['ticket']);
            return Cache::lock($key.':lock',10)->block(3,function() use ($key) {
                $grant=Cache::pull($key);
                if(!is_array($grant)||($grant['expires']??0)<=time()) self::fail('Launch link expired. Open New Call again from MAPA.',401);
                $parent=Request::create('/');
                $parent->headers->set('Authorization','Bearer '.Crypt::decryptString($grant['credential']));
                return DB::transaction(function() use ($parent,$grant) {
                    $context=$this->context($parent,true);
                    if($context['user']->uuid!==$grant['user']||$context['company']!==$grant['company']) self::fail('Operations identity changed.',401);
                    $session=$this->issueSession($context,'MAPA parent-bound intake');
                    $expiry=min($session['expires_at'],(int)round(microtime(true)*1000)+30*60000);
                    $hash=hash('sha256',$session['token']);
                    Cache::put('mapa:intake:parent:'.$hash,$grant['credential'],1800);
                    DB::table('mapa_mobile_sessions')->where('token_hash',$hash)->update(['expires_at'=>$expiry]);
                    $session['expires_at']=$expiry;
                    return $session;
                });
            });
        });
    }
    private function respond(callable $operation)
    {
        try { return response()->json($operation())->header('Cache-Control','no-store'); }
        catch(\Illuminate\Validation\ValidationException $e) { return response()->json(['error'=>'Invalid launch request.'],422)->header('Cache-Control','no-store'); }
        catch(HttpExceptionInterface $e) { return response()->json(['error'=>$e->getMessage()],$e->getStatusCode())->header('Cache-Control','no-store'); }
        catch(\Throwable $e) { return response()->json(['error'=>'Call intake launch is unavailable. Sign in directly or try again.'],503)->header('Cache-Control','no-store'); }
    }
}

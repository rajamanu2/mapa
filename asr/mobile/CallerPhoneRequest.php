<?php
namespace App\MapaMobile;
use Closure;
use Illuminate\Http\Request;

/** Native create/edit requests only; runs before persistence and sends no notifications. */
class CallerPhoneRequest
{
    public function handle(Request $request,Closure $next)
    {
        if(!in_array($request->method(),['POST','PUT','PATCH'],true)||!preg_match('~^(?:api/)?int/v1/orders(?:/[^/]+)?/?$~D',$request->path()))return $next($request);
        $order=$request->input('order');$meta=is_array($order)?($order['meta']??null):null;
        if(!is_array($meta)||!array_key_exists(CallerPhone::KEY,$meta))return $next($request);
        try{$meta[CallerPhone::KEY]=CallerPhone::normalize($meta[CallerPhone::KEY]);}
        catch(\InvalidArgumentException $e){return response()->json(['error'=>$e->getMessage(),'errors'=>['order.meta.'.CallerPhone::KEY=>[$e->getMessage()]]],422)->header('Cache-Control','no-store');}
        $order['meta']=$meta;$request->merge(['order'=>$order]);
        return $next($request);
    }
}

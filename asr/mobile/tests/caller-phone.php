<?php
/** No database writes, calls, messages or network requests. */
require '/fleetbase/api/vendor/autoload.php';
require_once __DIR__.'/../CallerPhone.php';
require_once __DIR__.'/../CallerPhoneRequest.php';
$app=require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\MapaMobile\CallerPhone;
use App\MapaMobile\CallerPhoneRequest;
use Illuminate\Http\Request;
$count=0;
function check($value,string $message):void {global $count;if(!$value)throw new RuntimeException($message);$count++;}
foreach([
    ['+1 (202) 555-0142','+12025550142'],['98765 43210','+919876543210'],['919876543210','+919876543210'],
    ['', ''],[null,''],['  ',''],['+44 20 7946 0958','+442079460958'],
] as [$input,$expected])check(CallerPhone::normalize($input)===$expected,'Phone normalization and optional unknown');
foreach(['caller@example.com','tel:+12025550142','+12025550142;123','+12025550142,123','0123456789','+01234567890','++12025550142','123','()--','+1234567890123456',str_repeat(' ',41),"+12025550142\n",1234567890,['phone'=>'+12025550142']] as $invalid){
    $rejected=false;try{CallerPhone::normalize($invalid);}catch(InvalidArgumentException $e){$rejected=true;}check($rejected,'Invalid number is not silently accepted');
}
$middleware=new CallerPhoneRequest;
foreach(['POST','PUT','PATCH'] as $method){
    foreach(['/int/v1/orders','/int/v1/orders/order_DEMO_TEST','/api/int/v1/orders/order_DEMO_TEST'] as $path){
        $original=['order'=>['internal_id'=>'DEMO CALLER TEST','meta'=>['mapa_demo'=>true,'retained'=>['a'=>1],'mapa_caller_phone'=>'+1 (202) 555-0142']]];
        $request=Request::create($path,$method,[],[],[],['CONTENT_TYPE'=>'application/json'],json_encode($original));
        $result=$middleware->handle($request,fn($r)=>response()->json($r->all()));$body=$result->getData(true);
        check($result->getStatusCode()===200&&$body['order']['meta']['mapa_caller_phone']==='+12025550142','Native create/edit gets normalized phone');
        check($body['order']['meta']['retained']===['a'=>1]&&$body['order']['meta']['mapa_demo']===true&&$body['order']['internal_id']==='DEMO CALLER TEST','Normalization retains unrelated native order data');
        $original['order']['meta']['mapa_caller_phone']='bad caller number';$next=false;
        $request=Request::create($path,$method,[],[],[],['CONTENT_TYPE'=>'application/json'],json_encode($original));
        $result=$middleware->handle($request,function()use(&$next){$next=true;});
        check($result->getStatusCode()===422&&!$next&&isset($result->getData(true)['errors']['order.meta.mapa_caller_phone']),'Invalid callback gets JSON422 before native persistence');
    }
}
foreach([['mapa_dispatch_review'=>['callback_number'=>'+12025550199']],['mapa_caller_phone'=>'','mapa_dispatch_review'=>['callback_number'=>'+12025550199']],['mapa_caller_phone'=>null,'mapa_dispatch_review'=>['callback_number'=>'+12025550199']]] as $meta){
    $result=$middleware->handle(Request::create('/int/v1/orders','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],json_encode(['order'=>['meta'=>$meta]])),fn($r)=>response()->json($r->all()));
    check($result->getStatusCode()===200,'Absent or explicitly unknown caller number is valid');
}
$meta=['mapa_demo'=>true,'mapa_caller_phone'=>'+12025550142','mapa_dispatch_review'=>['callback_number'=>'+12025550199','landmark'=>'DEMO meeting point']];
$raw=json_encode($meta);$public=CallerPhone::publicMeta($meta);check(!isset($public['mapa_caller_phone'])&&!isset($public['mapa_dispatch_review']['callback_number']),'Public metadata strips native and review callback numbers');
check($public['mapa_demo']===true&&$public['mapa_dispatch_review']['landmark']==='DEMO meeting point','Redaction retains unrelated metadata');check(json_encode($meta)===$raw,'Public serialization does not mutate stored metadata');
$object=json_decode($raw);$publicObject=CallerPhone::publicMeta($object);check(is_object($publicObject)&&!isset($publicObject->mapa_caller_phone)&&!isset($publicObject->mapa_dispatch_review->callback_number),'Object metadata also redacted');check($object->mapa_dispatch_review->callback_number==='+12025550199','Nested stored object preserved');
check(CallerPhone::dispatchDetails($meta)['callback_number']==='+12025550142','Native caller overrides earlier review number');
$meta['mapa_caller_phone']='';check(!isset(CallerPhone::dispatchDetails($meta)['callback_number']),'Explicit clear never revives earlier callback');
unset($meta['mapa_caller_phone']);check(CallerPhone::dispatchDetails($meta)['callback_number']==='+12025550199','Absent new key preserves existing review callback');
$meta['mapa_caller_phone']='invalid';check(!isset(CallerPhone::dispatchDetails($meta)['callback_number']),'Invalid legacy native field never reaches crew');
foreach(['Order','Driver','Vehicle'] as $name)check(CallerPhone::operatorRoute('Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1\\'.$name.'Controller@show'),'Native operator serializer retains callback');
foreach(['','Fleetbase\\CustomerPortal\\Http\\Controllers\\Internal\\v1\\OrderController@orders','Fleetbase\\FleetOps\\Http\\Controllers\\Api\\v1\\OrderController@show'] as $action)check(!CallerPhone::operatorRoute($action),'Public/customer portal serializer cannot treat internal name as staff authorization');
echo 'Caller phone: '.$count." assertions passed\n";

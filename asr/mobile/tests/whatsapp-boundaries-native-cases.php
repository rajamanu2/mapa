<?php
use App\MapaMobile\BoundedRequest;
$bounded=new BoundedRequest;
$req=Illuminate\Http\Request::create('/mapa/mobile/v1/tracking/whatsapp/webhook','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],str_repeat('x',65537));
check($bounded->handle($req,fn()=>response('unexpected'))->getStatusCode()===413,'route webhook body limited to64KiB');
$req=Illuminate\Http\Request::create('/mapa/mobile/v1/tracking/whatsapp/webhook','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],str_repeat('x',65536));
check($bounded->handle($req,fn()=>response('allowed'))->getContent()==='allowed','route webhook64KiB accepted for signature check');
$req=Illuminate\Http\Request::create('/mapa/mobile/v1/tracking/location','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],str_repeat('x',16385));
check($bounded->handle($req,fn()=>response('unexpected'))->getStatusCode()===413,'other mobile limits remain16KiB');
$verifyRequest=Illuminate\Http\Request::create('/mapa/mobile/v1/tracking/whatsapp/webhook?hub.mode=subscribe&hub.verify_token='.urlencode(str_repeat('DEMO-verify-',4)).'&hub.challenge=12345','GET');
config(['mapa_mobile.whatsapp.app_secret'=>str_repeat('DEMO-secret-',4)]);
$verified=$waController->verify($verifyRequest);check($verified->getStatusCode()===200&&$verified->getContent()==='12345','query normalization handshake controller');
echo "$count native integration checks passed; receipt route boundaries verified.\n";
Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory);
Illuminate\Support\Facades\Http::fake(['https://graph.facebook.com/*'=>Illuminate\Support\Facades\Http::response(['messages'=>[]],200)]);
check((new App\MapaMobile\WhatsAppTransport)->send('+919876543210','https://mapa.example.invalid/track/#DEMO')['status']==='unknown','successful response without ID remains unknown');
echo "$count native integration checks passed; malformed provider acceptance remains uncertain.\n";

<?php
namespace App\MapaMobile;

use Illuminate\Support\Facades\Http;

/** Opt-in trip notification only. No clinical details leave through this adapter. */
class WhatsAppTransport
{
    public function configured(): bool
    {
        return (bool)config('mapa_mobile.whatsapp.enabled',false)
            && preg_match('/^[0-9]{5,30}$/D',(string)config('mapa_mobile.whatsapp.phone_id'))
            && preg_match('/^v[0-9]{1,3}\.0$/D',(string)config('mapa_mobile.whatsapp.api_version'))
            && preg_match('/^[a-z0-9_]{1,100}$/D',(string)config('mapa_mobile.whatsapp.template'))
            && preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/D',(string)config('mapa_mobile.whatsapp.language'))
            && strlen((string)config('mapa_mobile.whatsapp.token'))>=20;
    }
    public static function message(string $phone,string $url,string $template,string $language): array
    {
        $phone=CallerPhone::normalize($phone);
        if($phone==='' || !filter_var($url,FILTER_VALIDATE_URL) || parse_url($url,PHP_URL_SCHEME)!=='https')
            throw new \InvalidArgumentException('A valid recipient and HTTPS trip link are required.');
        return ['messaging_product'=>'whatsapp','to'=>ltrim($phone,'+'),'type'=>'template',
            'template'=>['name'=>$template,'language'=>['code'=>$language],
                'components'=>[['type'=>'body','parameters'=>[['type'=>'text','text'=>$url]]]]]];
    }
    /** No automatic retry: a timeout may already have delivered the message. */
    public function send(string $phone,string $url,?string $callback=null): array
    {
        if(!$this->configured()) MobileService::fail('WhatsApp has not been configured. Contact the administrator.',503);
        $payload=self::message($phone,$url,(string)config('mapa_mobile.whatsapp.template'),(string)config('mapa_mobile.whatsapp.language'));
        if($callback!==null){if(!preg_match('/^[a-f0-9]{64}$/D',$callback))throw new \InvalidArgumentException('Invalid correlation.');$payload['biz_opaque_callback_data']=$callback;}
        try {
            $response=Http::withToken((string)config('mapa_mobile.whatsapp.token'))->acceptJson()
                ->withOptions(['allow_redirects'=>false])->connectTimeout(3)->timeout(10)
                ->post('https://graph.facebook.com/'.config('mapa_mobile.whatsapp.api_version').'/'.config('mapa_mobile.whatsapp.phone_id').'/messages',
                    $payload);
        } catch(\Throwable $e) { return ['status'=>'unknown','message_id'=>null]; }
        $id=$response->json('messages.0.id');
        if($response->successful() && is_string($id) && $id!=='' && strlen($id)<=255)
            return ['status'=>'accepted','message_id'=>$id];
        return ['status'=>($response->successful()||$response->serverError()||$response->status()===408)?'unknown':'rejected','message_id'=>null];
    }
}

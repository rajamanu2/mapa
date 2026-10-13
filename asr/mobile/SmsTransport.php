<?php
namespace App\MapaMobile;
use Illuminate\Support\Facades\Http;
/** Private mTLS gateway only. An injection receipt proves queueing, not delivery. */
class SmsTransport {
 public function configured():bool {
  if(!config('mapa_mobile.sms.enabled',false))return false;
  $origin=(string)config('mapa_mobile.sms.gateway_origin');$parts=parse_url($origin);
  if(!filter_var($origin,FILTER_VALIDATE_URL)||($parts['scheme']??'')!=='https'||empty($parts['host'])
   ||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||!empty($parts['path'])
   ||!preg_match('/^[a-f0-9]{64}$/D',(string)config('mapa_mobile.sms.gateway_token')))return false;
  foreach(['ca_file','client_certificate','client_key'] as $key){$p=(string)config('mapa_mobile.sms.'.$key);if(!$p||!is_file($p)||!is_readable($p))return false;}
  return true;
 }
 public function send(string $id,string $phone,string $url):array {
  if(!$this->configured())MobileService::fail('SMS gateway is not configured.',503);
  if(!preg_match('/^[a-f0-9]{64}$/D',$id)||CallerPhone::normalize($phone)!==$phone)throw new \InvalidArgumentException('Invalid SMS request.');
  $origin=rtrim((string)config('mapa_mobile.tracking_origin'),'/');
  if(!str_starts_with($url,$origin.'/track/#')||!preg_match('/^[a-f0-9]{64}$/D',substr($url,strlen($origin.'/track/#'))))throw new \InvalidArgumentException('Invalid tracking link.');
  try{
   $response=Http::withToken((string)config('mapa_mobile.sms.gateway_token'))->acceptJson()->withOptions([
    'allow_redirects'=>false,'verify'=>(string)config('mapa_mobile.sms.ca_file'),
    'cert'=>(string)config('mapa_mobile.sms.client_certificate'),'ssl_key'=>(string)config('mapa_mobile.sms.client_key'),
   ])->connectTimeout(3)->timeout(15)->post((string)config('mapa_mobile.sms.gateway_origin').'/v1/sms',['id'=>$id,'phone'=>$phone,'url'=>$url]);
   if(in_array($response->status(),[200,202],true)&&$response->json('id')===$id&&$response->json('status')==='queued'&&$response->json('delivered')===false)
    return ['status'=>'queued'];
  }catch(\Throwable $e){}
  // Gateway may have injected before a timeout. Never automatically retry.
  return ['status'=>'unknown'];
 }
}

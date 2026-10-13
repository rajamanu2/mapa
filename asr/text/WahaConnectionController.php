<?php
namespace App\MapaText;

use App\MapaMobile\ConsoleEnrollmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class WahaConnectionController {
 private function staff(Request $request): array {
  $context=(new ConsoleEnrollmentService)->context($request);
  abort_unless(in_array($context['binding']->role,['admin','superadmin'],true),403);
  $allowed=(string) config('mapa_text.qr_company_uuid');
  abort_unless($allowed!==''&&hash_equals($allowed,(string)$context['company']),403);
  return $context;
 }
 private function bridge(): array {
  $url=(string)config('mapa_text.waha_url');
  $key=(string)config('mapa_text.waha_api_key');
  $parts=parse_url($url);
  abort_unless(is_array($parts)&&($parts['scheme']??'')==='http'
   &&in_array($parts['host']??'',['waha','127.0.0.1'],true)
   &&($parts['port']??3000)===3000
   &&!isset($parts['user'])&&!isset($parts['pass'])&&!isset($parts['path'])
   &&strlen($key)>=32,503,'Private WhatsApp connection is not configured.');
  return [rtrim($url,'/'),$key];
 }
 private function request(string $path,string $accept='application/json') {
  [$base,$key]=$this->bridge();
  try {return Http::timeout(7)->withOptions(['allow_redirects'=>false])
   ->withHeaders(['X-Api-Key'=>$key,'Accept'=>$accept])->get($base.$path);}
  catch (\Throwable $error){abort(503,'Private WhatsApp connection is unavailable.');}
 }
 private function account(?string $id,string $company): array {
  $actual=preg_replace('/[^0-9].*$/','',(string)$id);
  $settings=DB::table('mapa_text_settings')->where('company_uuid',$company)->first();
  $expected=preg_replace('/[^0-9]/','',(string)($settings->sender??config('mapa_text.default_sender')));
  return ['linked'=>$actual!==''&&$expected!==''&&$actual===$expected,
   'mismatch'=>$actual!==''&&$expected!==''&&$actual!==$expected,
   'actual'=>$actual,'expected'=>$expected];
 }
 public function status(Request $request){
  $context=$this->staff($request);
  $response=$this->request('/api/sessions/default');
  abort_unless($response->successful(),503,'Private WhatsApp connection is unavailable.');
  $session=$response->json();
  abort_unless(is_array($session)&&is_string($session['status']??null),503,'Invalid connection status.');
  $account=$this->account($session['me']['id']??null,(string)$context['company']);
  return response()->json(['contract'=>'mapa-text-connection-v1','status'=>$session['status'],
   'linked'=>$session['status']==='WORKING'&&$account['linked'],
   'account_mismatch'=>$session['status']==='WORKING'&&$account['mismatch'],
   'linked_number'=>$session['status']==='WORKING'&&$account['actual']!==''?'+'.$account['actual']:null,
   'expected_number'=>$account['expected']!==''?'+'.$account['expected']:null,
   'sending_enabled'=>false])->header('Cache-Control','no-store, private')->header('X-Content-Type-Options','nosniff');
 }
 public function restart(Request $request){
  $this->staff($request);
  $status=$this->request('/api/sessions/default');
  abort_unless($status->successful(),503,'Private WhatsApp connection is unavailable.');
  $session=$status->json();
  abort_unless(is_array($session)&&in_array($session['status']??null,['FAILED','STOPPED'],true),409,'The WhatsApp session is not failed or stopped. Refresh its status first.');
  [$base,$key]=$this->bridge();
  try{$response=Http::timeout(15)->withOptions(['allow_redirects'=>false])
   ->withHeaders(['X-Api-Key'=>$key,'Accept'=>'application/json'])->post($base.'/api/sessions/default/'.($session['status']==='STOPPED'?'start':'restart'));}
  catch(\Throwable $error){abort(503,'Private WhatsApp connection is unavailable.');}
  abort_unless($response->successful(),503,'WhatsApp session restart failed.');
  return response()->json(['contract'=>'mapa-text-v1','ok'=>true,'status'=>'STARTING'])
   ->header('Cache-Control','no-store, private')->header('X-Content-Type-Options','nosniff');
 }
 public function switchAccount(Request $request){
  $context=$this->staff($request);
  abort_if(config('mapa_text.enabled')||DB::table('mapa_text_settings')->where('company_uuid',$context['company'])->where('enabled',true)->exists(),409,'Turn off message sending before changing the linked WhatsApp account.');
  $status=$this->request('/api/sessions/default');
  abort_unless($status->successful(),503,'Private WhatsApp connection is unavailable.');
  $session=$status->json();
  abort_unless(is_array($session),503,'Invalid connection status.');
  $account=$this->account($session['me']['id']??null,(string)$context['company']);
  abort_unless(($session['status']??null)==='WORKING'&&$account['mismatch'],409,'Save a different expected number before changing the linked account.');
  [$base,$key]=$this->bridge();
  try{$response=Http::timeout(15)->withOptions(['allow_redirects'=>false])
   ->withHeaders(['X-Api-Key'=>$key,'Accept'=>'application/json'])->post($base.'/api/sessions/default/logout');}
  catch(\Throwable $error){abort(503,'Private WhatsApp connection is unavailable.');}
  abort_unless($response->successful(),503,'Could not unlink the previous WhatsApp account.');
  DB::table('mapa_text_audit')->insert(['company_uuid'=>$context['company'],'user_uuid'=>$context['user']->uuid,
   'action'=>'sender_unlinked_for_switch','record_id'=>null,'created_at'=>now()]);
  return response()->json(['contract'=>'mapa-text-v1','ok'=>true,'status'=>'STARTING'])
   ->header('Cache-Control','no-store, private')->header('X-Content-Type-Options','nosniff');
 }
 public function qr(Request $request){
  $this->staff($request);
  $status=$this->request('/api/sessions/default');
  abort_unless($status->successful(),503,'Private WhatsApp connection is unavailable.');
  $session=$status->json();
  abort_unless(is_array($session)&&($session['status']??'')==='SCAN_QR_CODE',409,'QR is available only while waiting to link WhatsApp.');
  $response=$this->request('/api/default/auth/qr','image/png');
  $bytes=$response->body();
  abort_unless($response->successful()&&str_starts_with(strtolower((string)$response->header('Content-Type')),'image/png')
   &&strlen($bytes)>100&&strlen($bytes)<=1048576&&str_starts_with($bytes,"\x89PNG\r\n\x1a\n"),503,'QR is temporarily unavailable.');
  return response($bytes,200,['Content-Type'=>'image/png','Cache-Control'=>'no-store, private, max-age=0',
   'Pragma'=>'no-cache','X-Content-Type-Options'=>'nosniff','Content-Security-Policy'=>"default-src 'none'; img-src 'self'"]);
 }
}

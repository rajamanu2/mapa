"""Explicitly bind the existing MAPA customer administrator to ASR Call Intake.

This inserts one narrowly scoped admin mobile binding. It never creates a user,
changes passwords/MFA, enables providers, calls a number or dispatches an order.
"""
import argparse
import json
import pathlib
import runpy
import uuid

ACCOUNT = runpy.run_path(str(pathlib.Path(__file__).with_name('provision-customer-access.py')))
BINDING_ID = str(uuid.uuid5(uuid.NAMESPACE_URL, ACCOUNT['MARKER'] + ':' + ACCOUNT['COMPANY'] + ':call-intake-binding'))
ENABLE = r"""
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Fleetbase\Models\User;
use Fleetbase\Models\CompanyUser;
try {
 $out=DB::transaction(function()use($input){
  if(!config('mapa_mobile.enabled')||!config('mapa_voice.enabled')||
     config('mapa.company_uuid')!==$input['company']||!Schema::hasTable('mapa_mobile_bindings'))
   throw new RuntimeException('Existing MAPA mobile/voice configuration unavailable.');
  $u=User::where('uuid',$input['user'])->lockForUpdate()->firstOrFail();
  if($u->company_uuid!==$input['company']||$u->email!==$input['email']||
     $u->_key!==$input['marker']||data_get($u->meta,'mapa_customer_access')!==true||
     data_get($u->meta,'provisioning_marker')!==$input['marker']||$u->type!=='user'||
     $u->isAdmin()||$u->status!=='active'||!$u->email_verified_at)
   throw new RuntimeException('Existing dedicated customer account mismatch.');
  $m=CompanyUser::where('uuid',$input['membership'])->where('user_uuid',$u->uuid)
    ->where('company_uuid',$input['company'])->where('status','active')->lockForUpdate()->firstOrFail();
  if($m->_key!==$input['marker']||$m->external||$m->roles()->count()!==1||
     !$m->roles()->where('roles.id',$input['role'])->exists())
   throw new RuntimeException('Existing dedicated company Administrator membership mismatch.');
  $q=DB::table('mapa_mobile_bindings')->where('user_uuid',$u->uuid);
  $rows=$q->lockForUpdate()->get();
  $expected=['id'=>$input['binding'],'company_uuid'=>$input['company'],'user_uuid'=>$u->uuid,
    'role'=>'admin','driver_uuid'=>null,'place_uuid'=>null,'enabled'=>1,'revision'=>1];
  $created=false;
  if($rows->isNotEmpty()){
   if($rows->count()!==1)throw new RuntimeException('Dedicated customer has multiple mobile bindings.');
   $binding=$rows->first();
   foreach($expected as $field=>$value)
    if((string)($binding->$field??'')!==(string)($value??''))
     throw new RuntimeException('Existing mobile binding differs; no reassignment or reactivation performed.');
  }else{
   if(DB::table('mapa_mobile_bindings')->where('id',$input['binding'])->exists())
    throw new RuntimeException('Binding identifier belongs to another identity.');
   DB::table('mapa_mobile_bindings')->insert([...$expected,'created_at'=>now(),'updated_at'=>now()]);
   $created=true;
  }
  return ['created'=>$created,'binding'=>$input['binding'],'role'=>'admin'];
 });
 echo 'MAPA_RESULT:'.json_encode($out);
}catch(Throwable $e){fwrite(STDERR,'MAPA scoped Call Intake binding failed; diagnostics suppressed.');exit(1);}
"""


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--enable-call-intake', action='store_true')
    mode.add_argument('--verify-only', action='store_true')
    parser.add_argument('--skip-public-checks', action='store_true')
    parser.add_argument('--docker', required=True)
    args = parser.parse_args()
    if args.skip_public_checks and not args.enable_call_intake:
        parser.error('--skip-public-checks requires --enable-call-intake')
    if args.enable_call_intake:
        payload = {'company': ACCOUNT['COMPANY'], 'user': ACCOUNT['USER_ID'],
                   'membership': ACCOUNT['MEMBERSHIP_ID'], 'email': ACCOUNT['EMAIL'],
                   'marker': ACCOUNT['MARKER'], 'role': ACCOUNT['ROLE'], 'binding': BINDING_ID}
        result = ACCOUNT['docker_php'](args.docker, ENABLE, payload)
        assert result['binding'] == BINDING_ID and result['role'] == 'admin'
        print(('Created' if result['created'] else 'Verified existing') + ' ASR Health Call Intake admin binding.', flush=True)
        if args.skip_public_checks:
            print('Public Call Intake login verification deferred.', flush=True)
            return
    path = pathlib.Path(__file__).resolve().parents[2] / 'private/live-access/Customer-login.txt'
    saved = dict(line.split(': ', 1) for line in path.read_text(encoding='utf-8').splitlines() if ': ' in line)
    if saved.get('Email') != ACCOUNT['EMAIL'] or saved.get('Company UUID') != ACCOUNT['COMPANY'] or saved.get('Marker') != ACCOUNT['MARKER']:
        raise RuntimeError('Saved customer credential metadata mismatch.')
    request = ACCOUNT['request']
    token = None
    try:
        status, body = request('/mapa/mobile/v1/login', payload={
            'email': ACCOUNT['EMAIL'], 'password': saved['Password'], 'device_name': 'MAPA Customer Call Intake acceptance'})
        if status != 200 or not isinstance(body, dict) or not isinstance(body.get('token'), str):
            raise RuntimeError(f'Call Intake password login failed (HTTP {status}); credentials and response suppressed.')
        token = body['token']
        assert body.get('user', {}).get('role') == 'admin'
        assert body.get('user', {}).get('company_id') == ACCOUNT['COMPANY']
        print('PASS public Call Intake password login as scoped ASR admin', flush=True)
        for endpoint in ['/mapa/mobile/v1/capabilities', '/mapa/mobile/v1/snapshot', '/mapa/voice/v1/calls']:
            status, body = request(endpoint, token=token)
            if status != 200 or not isinstance(body, dict):
                raise RuntimeError(f'Call Intake read failed: {endpoint} HTTP {status}')
            print('PASS', endpoint, flush=True)
            if endpoint.endswith('/calls'):
                # Only nonsecret capability booleans are printed, never caller data.
                print('Call Intake capability flags:', json.dumps(body.get('capabilities', {})), flush=True)
    finally:
        if token:
            status, _ = request('/mapa/mobile/v1/logout', token=token, payload={})
            if status != 200:
                raise RuntimeError('Call Intake acceptance session logout failed; revoke this exact session before continuing.')
            status, _ = request('/mapa/mobile/v1/capabilities', token=token)
            if status != 401:
                raise RuntimeError('Call Intake test-session revocation check failed.')
            print('PASS exact Call Intake test session revoked', flush=True)
    print('No calls, demos, operational records, provider settings or dispatches changed.', flush=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(str(error) if isinstance(error, RuntimeError) else 'Scoped Call Intake setup or acceptance failed; sensitive output suppressed.')
        raise SystemExit(1)

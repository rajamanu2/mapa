"""Provision and password-test one company-scoped MAPA customer administrator.

Explicit --provision is required. No owner password, MFA, provider, patient,
dispatch or notification is changed. Secrets exist only in subprocess stdin,
process memory and private/live-access/Customer-login.txt. Output is redacted.
Re-running verifies the same credential; it never silently resets a password.
"""
import argparse
import base64
import json
import pathlib
import secrets
import subprocess
import urllib.error
import urllib.request
import uuid

COMPANY = "29eff7bc-7b9b-4d8a-b1b4-55f33681bd2d"
OWNER = "3c648de0-64c5-4b0c-9197-35994ffd6bba"
ROLE = "09f40b88-0305-4226-8dde-6433eb08c53b"
EMAIL = "customer.admin@mapa.invalid"
MARKER = "mapa-customer-access-v1"
BASE = "https://mapa-asr-health.pages.dev"
USER_ID = str(uuid.uuid5(uuid.NAMESPACE_URL, MARKER + ":" + COMPANY + ":user"))
MEMBERSHIP_ID = str(uuid.uuid5(uuid.NAMESPACE_URL, MARKER + ":" + COMPANY + ":membership"))
BOOTSTRAP = r"""<?php
require '/fleetbase/api/vendor/autoload.php';
$app=require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
"""
PROVISION = r"""
use Fleetbase\Models\User;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\Role;
use Fleetbase\Services\UserCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
try {
 $out=DB::transaction(function() use($input) {
  $company=Company::where('uuid',$input['company'])->lockForUpdate()->firstOrFail();
  $owner=User::where('uuid',$input['owner'])->firstOrFail();
  if($owner->company_uuid!==$company->uuid||!$owner->isCompanyOwner($company))
   throw new RuntimeException('Unexpected company ownership.');
  $role=Role::where('id',$input['role'])->firstOrFail();
  if($role->name!=='Administrator'||$role->guard_name!=='sanctum'||$role->company_uuid!==null)
   throw new RuntimeException('Unexpected native role.');
  if(!$role->policies()->where('name','AdministratorAccess')->exists())
   throw new RuntimeException('Native Administrator policy missing.');
  if(User::withTrashed()->where('email',$input['email'])->where('uuid','<>',$input['user'])->exists())
   throw new RuntimeException('Email belongs to another identity.');
  $u=User::withTrashed()->where('uuid',$input['user'])->lockForUpdate()->first();
  $created=!$u;
  if($u) {
   if($u->deleted_at||$u->company_uuid!==$company->uuid||$u->email!==$input['email']||
      $u->_key!==$input['marker']||data_get($u->meta,'mapa_customer_access')!==true||
      data_get($u->meta,'provisioning_marker')!==$input['marker']||$u->type!=='user'||
      $u->status!=='active'||!$u->email_verified_at||!Hash::check($input['password'],$u->password))
    throw new RuntimeException('Existing dedicated identity or password differs; review required.');
  } else {
   $u=new User();
   $u->uuid=$input['user']; $u->public_id='user_mapa_customer_admin';
   $u->_key=$input['marker']; $u->company_uuid=$company->uuid;
   $u->name='MAPA Customer Admin'; $u->email=$input['email'];
   $u->username='mapa-customer-admin'; $u->slug='mapa-customer-admin';
   $u->type='user'; $u->status='active'; $u->country='IN'; $u->timezone='Asia/Kolkata';
   $u->meta=['mapa_customer_access'=>true,'provisioning_marker'=>$input['marker'],
    'purpose'=>'User-authorized customer acceptance of ASR Health workspace',
    'verification'=>'Locally provisioned login identifier; no email sent'];
   // Native User mutator performs canonical Hash::make; do not double-hash.
   $u->password=$input['password']; $u->email_verified_at=now();
   // Explicit identifiers and defaults above allow suppressing model observers,
   // invitation mail, notifications and broadcasts during CLI provisioning.
   $u->saveQuietly();
  }
  if(CompanyUser::withTrashed()->where('user_uuid',$u->uuid)->where('uuid','<>',$input['membership'])->exists())
   throw new RuntimeException('Dedicated identity has an unrelated membership.');
  $m=CompanyUser::withTrashed()->where('uuid',$input['membership'])->lockForUpdate()->first();
  if($m) {
   if($m->deleted_at||$m->company_uuid!==$company->uuid||$m->user_uuid!==$u->uuid||
      $m->_key!==$input['marker']||$m->status!=='active'||$m->external)
    throw new RuntimeException('Existing dedicated membership differs.');
   if($m->roles()->count()!==1||!$m->roles()->where('roles.id',$role->id)->exists())
    throw new RuntimeException('Existing dedicated role differs; review required.');
  } else {
   if(!$created) throw new RuntimeException('Existing dedicated membership is missing.');
   $m=new CompanyUser(); $m->uuid=$input['membership']; $m->_key=$input['marker'];
   $m->company_uuid=$company->uuid; $m->user_uuid=$u->uuid; $m->status='active'; $m->external=false;
   $m->saveQuietly();
   CompanyUser::withoutEvents(fn()=> $m->assignSingleRole($role));
  }
  $u->setRelation('companyUser',$m);
  if($u->isAdmin()||$u->role?->id!==$role->id||$u->session_status!=='active')
   throw new RuntimeException('Company scope verification failed.');
  UserCacheService::invalidateUser($u);
  app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
  return ['created'=>$created,'uuid'=>$u->uuid,'company_uuid'=>$company->uuid,
          'role_id'=>$role->id,'is_admin'=>$u->isAdmin()];
 });
 echo 'MAPA_RESULT:'.json_encode($out);
} catch(Throwable $e) { fwrite(STDERR,'MAPA account provision failed; private diagnostics suppressed.'); exit(1); }
"""
CLEANUP = r"""
try {
 $u=Fleetbase\Models\User::where('uuid',$input['user'])->firstOrFail();
 if($u->company_uuid!==$input['company']||$u->_key!==$input['marker'])
  throw new RuntimeException('Scope mismatch.');
 $t=Laravel\Sanctum\PersonalAccessToken::findToken($input['token']);
 if(!$t||(string)$t->tokenable_id!==(string)$u->getKey()||$t->tokenable_type!==$u->getMorphClass())
  throw new RuntimeException('Test token owner mismatch.');
 Illuminate\Support\Facades\Cache::forget('session_validation_'.$input['token']);
 Illuminate\Support\Facades\Cache::forget('auth_bootstrap_'.$u->uuid.'_'.$input['token']);
 $t->delete(); echo 'MAPA_RESULT:'.json_encode(['revoked'=>true]);
} catch(Throwable $e) { fwrite(STDERR,'MAPA exact test-token cleanup failed.'); exit(1); }
"""


def docker_php(docker, code, payload):
    encoded = base64.b64encode(json.dumps(payload).encode()).decode()
    script = BOOTSTRAP + "$input=json_decode(base64_decode('" + encoded + "'),true,512,JSON_THROW_ON_ERROR);\n" + code
    result = subprocess.run(
        [docker, 'exec', '-i', 'asr-fleetbase-pilot-application-1', 'php', '-d', 'xdebug.mode=off'],
        input=script, text=True, capture_output=True, timeout=60,
    )
    if result.returncode or 'MAPA_RESULT:' not in result.stdout:
        raise RuntimeError('Native account operation failed; output suppressed to protect credentials.')
    return json.loads(result.stdout.rsplit('MAPA_RESULT:', 1)[1])


def request(path, token=None, payload=None):
    headers = {'Accept': 'application/json', 'User-Agent': 'MAPA-Customer-Acceptance/1.0'}
    if token:
        headers['Authorization'] = 'Bearer ' + token
    data = None
    if payload is not None:
        headers['Content-Type'] = 'application/json'
        data = json.dumps(payload).encode()
    req = urllib.request.Request(BASE + path, data=data, headers=headers)
    try:
        response = urllib.request.urlopen(req, timeout=35)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        raw = response.read(4 * 1024 * 1024)
        try:
            body = json.loads(raw)
        except ValueError:
            body = None
        return response.status, body


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--provision', action='store_true')
    mode.add_argument('--verify-only', action='store_true')
    parser.add_argument('--skip-public-checks', action='store_true',
                        help='Provision first, then allow this exact UUID in the gateway before --verify-only.')
    parser.add_argument('--docker', required=True)
    args = parser.parse_args()
    root = pathlib.Path(__file__).resolve().parents[2]
    destination = root / 'private' / 'live-access' / 'Customer-login.txt'
    if args.skip_public_checks and not args.provision:
        parser.error('--skip-public-checks requires --provision')
    if destination.exists():
        saved = dict(line.split(': ', 1) for line in destination.read_text(encoding='utf-8').splitlines() if ': ' in line)
        if saved.get('Email') != EMAIL or saved.get('Company UUID') != COMPANY or saved.get('Marker') != MARKER:
            raise RuntimeError('Private credential file belongs to another setup; refusing to overwrite.')
        password = saved.get('Password', '')
        if not 24 <= len(password) <= 200:
            raise RuntimeError('Private credential file has an invalid password format.')
    else:
        if not args.provision:
            raise RuntimeError('No saved customer credential; explicit --provision is required first.')
        destination.parent.mkdir(parents=True, exist_ok=True)
        password = 'Mapa-' + secrets.token_urlsafe(24) + '!7'
        # Exclusive creation makes retries retain the one provisioned credential.
        with destination.open('x', encoding='utf-8') as handle:
            handle.write(
                'MAPA customer access — keep private\n'
                f'URL: {BASE}/auth\nEmail: {EMAIL}\nPassword: {password}\n'
                f'Company UUID: {COMPANY}\nMarker: {MARKER}\n'
                'Access: ASR Health organization Administrator; not platform administrator.\n'
                'This account can change existing ASR Health operational records.\n'
                'Provider-connected calling and real dispatch must be separately commissioned.\n'
            )
    payload = {'company': COMPANY, 'owner': OWNER, 'role': ROLE, 'email': EMAIL,
               'marker': MARKER, 'user': USER_ID, 'membership': MEMBERSHIP_ID, 'password': password}
    if args.provision:
        result = docker_php(args.docker, PROVISION, payload)
        assert result['uuid'] == USER_ID and result['company_uuid'] == COMPANY and result['is_admin'] is False
        print(('Created' if result['created'] else 'Verified existing') + ' ASR Health company Administrator; owner credentials unchanged.', flush=True)
        print('Customer UUID:', USER_ID, flush=True)
        if args.skip_public_checks:
            print('Public acceptance deferred; run --verify-only after allowing this exact customer UUID and native role.', flush=True)
            return
    token = None
    try:
        status, body = request('/api/int/v1/auth/login', payload={'identity': EMAIL, 'password': password})
        if status != 200 or not isinstance(body, dict) or not isinstance(body.get('token'), str):
            raise RuntimeError(f'Public password login did not return a token (HTTP {status}); credentials remain private.')
        token = body['token']
        print('PASS public native password login', flush=True)
        status, body = request('/api/int/v1/users/me', token=token)
        if status != 200 or not isinstance(body, dict):
            raise RuntimeError(f'Public scoped access failed (HTTP {status}); verify gateway role allowlist.')
        user = body.get('user', {})
        assert user.get('uuid') == USER_ID and user.get('company_uuid') == COMPANY
        assert user.get('is_admin') is False and user.get('type') == 'user'
        assert user.get('status') == 'active' and user.get('session_status') == 'active'
        assert (user.get('role') or {}).get('id') == ROLE
        print('PASS active ASR Health company Administrator; platform administrator disabled', flush=True)
        for path in ['/api/int/v1/auth/session', '/api/int/v1/auth/bootstrap',
                     '/api/int/v1/vehicles', '/api/int/v1/drivers', '/api/int/v1/places',
                     '/api/int/v1/orders', '/api/int/v1/users', '/api/int/v1/fleet-ops/settings/map']:
            status, _ = request(path, token=token)
            if status != 200:
                raise RuntimeError(f'Public customer feature check failed: {path} HTTP {status}')
            print('PASS', path, flush=True)
        status, body = request('/api/int/v1/orders', token=token, payload={'order': {'dispatch': False}})
        assert status == 422 and body.get('errors'), 'Expected required-field validation without creating an order.'
        print('PASS native write validation; no order created or dispatched', flush=True)
    finally:
        if token:
            result = docker_php(args.docker, CLEANUP, {'user': USER_ID, 'company': COMPANY, 'marker': MARKER, 'token': token})
            assert result['revoked'] is True
            print('Exact API test token revoked.', flush=True)
    print('Credential handoff saved only in private/live-access/Customer-login.txt; no password printed.', flush=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        # Never include subprocess diagnostics, HTTP bodies, tokens or passwords.
        print(str(error) if isinstance(error, RuntimeError) else 'Customer provisioning or acceptance failed; inspect the nonsecret checks above.')
        raise SystemExit(1)

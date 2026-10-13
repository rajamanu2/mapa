"""Explicit user-authorized native platform Super Admin and ASR mobile binding.

The previous company-only customer and owner remain untouched. Credentials are
written only to private/live-access/Super-admin-login.txt. This account has real
installation-wide native admin privileges, not merely a changed display label.
"""
import argparse
import json
import os
from pathlib import Path
import re
import runpy
import secrets
import tempfile
import uuid

COMMON = runpy.run_path(str(Path(__file__).with_name('provision-customer-access.py')))
ROOT = Path(__file__).resolve().parents[2]
COMPANY, OWNER, ROLE = COMMON['COMPANY'], COMMON['OWNER'], COMMON['ROLE']
EMAIL = 'customer.superadmin@mapa.invalid'
MARKER = 'mapa-customer-superadmin-v1'
USER_ID = str(uuid.uuid5(uuid.NAMESPACE_URL, MARKER + ':' + COMPANY + ':user'))
MEMBERSHIP_ID = str(uuid.uuid5(uuid.NAMESPACE_URL, MARKER + ':' + COMPANY + ':membership'))
BINDING_ID = str(uuid.uuid5(uuid.NAMESPACE_URL, MARKER + ':' + COMPANY + ':mobile-binding'))
PRIVATE = ROOT / 'private/live-access/Super-admin-login.txt'
PROVISION = r"""
use Fleetbase\Models\User;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
try {
 $out=DB::transaction(function()use($input){
  $company=Company::where('uuid',$input['company'])->lockForUpdate()->firstOrFail();
  $owner=User::where('uuid',$input['owner'])->firstOrFail();
  if($owner->company_uuid!==$company->uuid||!$owner->isCompanyOwner($company))
   throw new RuntimeException('Company owner mismatch.');
  $role=Role::where('id',$input['role'])->firstOrFail();
  if($role->name!=='Administrator'||$role->guard_name!=='sanctum'||$role->company_uuid!==null||
     !$role->policies()->where('name','AdministratorAccess')->exists())
   throw new RuntimeException('Native Administrator role mismatch.');
  if(User::withTrashed()->where(function($q)use($input){$q->where('email',$input['email'])
      ->orWhere('public_id','user_mapa_customer_superadmin')->orWhere('username','mapa-customer-superadmin');})
      ->where('uuid','<>',$input['user'])->exists())
   throw new RuntimeException('Dedicated identity collides with another account.');
  $u=User::withTrashed()->where('uuid',$input['user'])->lockForUpdate()->first();
  $created=!$u;
  if($u){
   if($u->deleted_at||$u->company_uuid!==$company->uuid||$u->email!==$input['email']||
      $u->_key!==$input['marker']||data_get($u->meta,'mapa_customer_superadmin')!==true||
      data_get($u->meta,'provisioning_marker')!==$input['marker']||$u->type!=='admin'||
      $u->status!=='active'||!$u->email_verified_at||!Hash::check($input['password'],$u->password))
    throw new RuntimeException('Existing dedicated Super Admin differs; no reset performed.');
  }else{
   $u=new User();$u->uuid=$input['user'];$u->public_id='user_mapa_customer_superadmin';
   $u->_key=$input['marker'];$u->company_uuid=$company->uuid;
   $u->name='MAPA Customer Super Admin';$u->email=$input['email'];
   $u->username='mapa-customer-superadmin';$u->slug='mapa-customer-superadmin';
   // Explicitly requested native platform privilege, separate from company role.
   $u->type='admin';$u->status='active';$u->country='IN';$u->timezone='Asia/Kolkata';
   $u->meta=['mapa_customer_superadmin'=>true,'provisioning_marker'=>$input['marker'],
     'purpose'=>'Explicit user-authorized customer platform administration access',
     'verification'=>'Locally provisioned login identifier; no email sent'];
   $u->password=$input['password'];$u->email_verified_at=now();$u->saveQuietly();
  }
  if(CompanyUser::withTrashed()->where('user_uuid',$u->uuid)->where('uuid','<>',$input['membership'])->exists())
   throw new RuntimeException('Unexpected dedicated account company membership.');
  $m=CompanyUser::withTrashed()->where('uuid',$input['membership'])->lockForUpdate()->first();
  if($m){
   if($m->deleted_at||$m->company_uuid!==$company->uuid||$m->user_uuid!==$u->uuid||
      $m->_key!==$input['marker']||$m->status!=='active'||$m->external||
      $m->roles()->count()!==1||!$m->roles()->where('roles.id',$role->id)->exists())
    throw new RuntimeException('Dedicated membership or role differs.');
  }else{
   if(!$created)throw new RuntimeException('Existing dedicated membership missing.');
   $m=new CompanyUser();$m->uuid=$input['membership'];$m->_key=$input['marker'];
   $m->company_uuid=$company->uuid;$m->user_uuid=$u->uuid;$m->status='active';$m->external=false;
   $m->saveQuietly();CompanyUser::withoutEvents(fn()=> $m->assignSingleRole($role));
  }
  $expected=['id'=>$input['binding'],'company_uuid'=>$company->uuid,'user_uuid'=>$u->uuid,
    'role'=>'superadmin','driver_uuid'=>null,'place_uuid'=>null,'enabled'=>1,'revision'=>1];
  $bindings=DB::table('mapa_mobile_bindings')->where('user_uuid',$u->uuid)->lockForUpdate()->get();
  if($bindings->isNotEmpty()){
   if($bindings->count()!==1)throw new RuntimeException('Unexpected extra mobile bindings.');
   $binding=$bindings->first();
   foreach($expected as $field=>$value)if((string)($binding->$field??'')!==(string)($value??''))
    throw new RuntimeException('Dedicated mobile binding differs.');
  }else{
   if(DB::table('mapa_mobile_bindings')->where('id',$input['binding'])->exists())
    throw new RuntimeException('Mobile binding identifier collision.');
   DB::table('mapa_mobile_bindings')->insert([...$expected,'created_at'=>now(),'updated_at'=>now()]);
  }
  $u->setRelation('companyUser',$m);
  if(!$u->isAdmin()||$u->role?->id!==$role->id||$u->session_status!=='active')
   throw new RuntimeException('Native platform administrator verification failed.');
  Fleetbase\Services\UserCacheService::invalidateUser($u);
  app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
  return ['created'=>$created,'uuid'=>$u->uuid,'company_uuid'=>$company->uuid,'is_admin'=>$u->isAdmin()];
 });echo 'MAPA_RESULT:'.json_encode($out);
}catch(Throwable $e){fwrite(STDERR,'Dedicated Super Admin provisioning failed; sensitive diagnostics suppressed.');exit(1);}
"""


def credential(create):
    if PRIVATE.exists():
        saved = dict(line.split(': ', 1) for line in PRIVATE.read_text(encoding='utf-8').splitlines() if ': ' in line)
        if saved.get('Email') != EMAIL or saved.get('Marker') != MARKER or saved.get('Company UUID') != COMPANY:
            raise RuntimeError('Dedicated Super Admin credential metadata differs; refusing overwrite.')
        password = saved.get('Password', '')
        if not 24 <= len(password) <= 200:
            raise RuntimeError('Dedicated saved password format is invalid.')
        return password
    if not create:
        raise RuntimeError('Super Admin credential not yet provisioned.')
    PRIVATE.parent.mkdir(parents=True, exist_ok=True)
    password = 'Mapa-' + secrets.token_urlsafe(24) + '!9'
    with PRIVATE.open('x', encoding='utf-8') as handle:
        handle.write('MAPA Customer Super Admin — keep private\n'
                     f'URL: {COMMON["BASE"]}/auth\nEmail: {EMAIL}\nPassword: {password}\n'
                     f'Company UUID: {COMPANY}\nMarker: {MARKER}\n'
                     'Access: Native installation-wide platform administration and ASR Health operations.\n'
                     'This login can change platform settings, organizations, users and operational records.\n'
                     'Mobile/Call Intake remains bound to ASR Health. No provider calling is enabled by this login.\n')
    return password


def configure_allowlist():
    env = ROOT / 'fleetbase/asr/.env'
    original = env.read_bytes()
    text = original.decode('utf-8')
    matches = list(re.finditer(r'^MAPA_MOBILE_SUPERADMIN_UUIDS=([^\r\n]*)', text, flags=re.M))
    if len(matches) != 1:
        raise RuntimeError('Expected exactly one existing mobile Super Admin allowlist; manual configuration review required.')
    match = matches[0]
    ids = [value.strip() for value in match.group(1).strip('"\'').split(',') if value.strip()]
    if not all(str(uuid.UUID(value)) == value for value in ids):
        raise RuntimeError('Unexpected mobile Super Admin allowlist format.')
    if USER_ID in ids:
        print('Existing mobile Super Admin allowlist already contains this exact account.', flush=True)
        return
    ids.append(USER_ID)
    updated = text[:match.start(1)] + ','.join(ids) + text[match.end(1):]
    if env.read_bytes() != original:
        raise RuntimeError('Private environment changed concurrently; retry exact allowlist update.')
    temp_name = None
    try:
        with tempfile.NamedTemporaryFile(mode='wb', dir=env.parent, prefix='.mapa-superadmin-', delete=False) as handle:
            temp_name = handle.name
            handle.write(updated.encode('utf-8'))
        os.replace(temp_name, env)
        temp_name = None
    finally:
        if temp_name:
            Path(temp_name).unlink(missing_ok=True)
    print('Added only this account UUID to existing private mobile allowlist; API reload required.', flush=True)


def verify(docker, password, web_only):
    request = COMMON['request']
    token = None
    try:
        status, body = request('/api/int/v1/auth/login', payload={'identity': EMAIL, 'password': password})
        if status != 200 or not isinstance(body, dict) or not isinstance(body.get('token'), str):
            raise RuntimeError(f'Native Super Admin password login failed (HTTP {status}); sensitive output suppressed.')
        token = body['token']
        print('PASS public native Super Admin password login', flush=True)
        status, body = request('/api/int/v1/users/me', token=token)
        if status != 200:
            raise RuntimeError(f'Public Super Admin current-user check failed (HTTP {status}).')
        user = body.get('user', {})
        assert user.get('uuid') == USER_ID and user.get('company_uuid') == COMPANY
        assert user.get('is_admin') is True and user.get('type') == 'admin'
        assert user.get('status') == 'active' and user.get('session_status') == 'active'
        print('PASS native type=admin, is_admin=true, active ASR membership', flush=True)
        # AdminRequest::authorize checks actual User::isAdmin(), not role labels.
        for endpoint in ['/api/int/v1/settings/overview', '/api/int/v1/companies',
                         '/api/int/v1/metrics/admin/kpis/users-total', '/api/int/v1/metrics/admin/kpis/organizations-total',
                         '/api/int/v1/auth/bootstrap', '/api/int/v1/users', '/api/int/v1/vehicles',
                         '/api/int/v1/orders', '/api/int/v1/drivers', '/api/int/v1/places']:
            status, _ = request(endpoint, token=token)
            if status != 200:
                raise RuntimeError(f'Super Admin read failed: {endpoint} HTTP {status}')
            print('PASS', endpoint, flush=True)
    finally:
        if token:
            result = COMMON['docker_php'](docker, COMMON['CLEANUP'], {'user': USER_ID, 'company': COMPANY, 'marker': MARKER, 'token': token})
            assert result['revoked']
            print('Exact native Super Admin test token revoked.', flush=True)
    if web_only:
        return
    token = None
    try:
        status, body = request('/mapa/mobile/v1/login', payload={'email': EMAIL, 'password': password, 'device_name': 'MAPA Super Admin acceptance'})
        if status != 200 or not isinstance(body, dict) or not isinstance(body.get('token'), str):
            raise RuntimeError(f'Mobile Super Admin login failed (HTTP {status}); check API allowlist reload.')
        token = body['token']
        assert body.get('user', {}).get('role') == 'superadmin' and body.get('user', {}).get('company_id') == COMPANY
        print('PASS exact allowlisted ASR mobile Super Admin login', flush=True)
        for endpoint in ['/mapa/mobile/v1/capabilities', '/mapa/mobile/v1/snapshot', '/mapa/voice/v1/calls']:
            status, _ = request(endpoint, token=token)
            if status != 200:
                raise RuntimeError(f'Mobile Super Admin read failed: {endpoint} HTTP {status}')
            print('PASS', endpoint, flush=True)
    finally:
        if token:
            status, _ = request('/mapa/mobile/v1/logout', token=token, payload={})
            if status != 200:
                raise RuntimeError('Exact mobile Super Admin acceptance-session logout failed.')
            status, _ = request('/mapa/mobile/v1/capabilities', token=token)
            assert status == 401
            print('Exact mobile Super Admin test session revoked.', flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    modes = parser.add_mutually_exclusive_group(required=True)
    modes.add_argument('--provision', action='store_true')
    modes.add_argument('--verify-only', action='store_true')
    parser.add_argument('--web-only', action='store_true', help='Verify native web login while waiting for exact mobile allowlist reload.')
    parser.add_argument('--skip-public-checks', action='store_true')
    parser.add_argument('--docker', required=True)
    args = parser.parse_args()
    if args.skip_public_checks and not args.provision:
        parser.error('--skip-public-checks requires --provision')
    password = credential(args.provision)
    if args.provision:
        result = COMMON['docker_php'](args.docker, PROVISION, {'company': COMPANY, 'owner': OWNER, 'role': ROLE,
                    'email': EMAIL, 'marker': MARKER, 'user': USER_ID, 'membership': MEMBERSHIP_ID,
                    'binding': BINDING_ID, 'password': password})
        assert result['uuid'] == USER_ID and result['is_admin'] is True
        print(('Created' if result['created'] else 'Verified existing') + ' dedicated native MAPA Customer Super Admin.', flush=True)
        print('Super Admin UUID:', USER_ID, flush=True)
        configure_allowlist()
        if args.skip_public_checks:
            return
    verify(args.docker, password, args.web_only)
    print('Private credentials: private/live-access/Super-admin-login.txt. Owner and previous customer credentials unchanged.', flush=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(str(error) if isinstance(error, RuntimeError) else 'Super Admin setup or acceptance failed; sensitive output suppressed.')
        raise SystemExit(1)

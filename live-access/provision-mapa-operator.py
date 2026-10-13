"""Provision one company-scoped MAPA call operator after the operator API is deployed.

The login identifier is temporary until the owner supplies a real recovery email.
No existing user, role, password, dispatcher setting, call, or crew assignment is changed.
"""
import argparse
import base64
import json
import os
from pathlib import Path
import secrets
import subprocess
import urllib.error
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[2]
COMPANY = "29eff7bc-7b9b-4d8a-b1b4-55f33681bd2d"
EMAIL = "mapa.operator@mapa.invalid"
MARKER = "mapa-operator-v1"
USER_ID = str(uuid.uuid5(uuid.NAMESPACE_URL, MARKER + ":" + COMPANY + ":user"))
MEMBERSHIP_ID = str(uuid.uuid5(uuid.NAMESPACE_URL, MARKER + ":" + COMPANY + ":membership"))
BINDING_ID = str(uuid.uuid5(uuid.NAMESPACE_URL, MARKER + ":" + COMPANY + ":binding"))
ROLE_ID = str(uuid.uuid5(uuid.NAMESPACE_URL, MARKER + ":" + COMPANY + ":role"))
PRIVATE = ROOT / "private/live-access/Operator-login.txt"
BASE = "https://app.itda.asrhealth.in"
BOOTSTRAP = """<?php
require '/fleetbase/api/vendor/autoload.php';
$app=require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
"""

PROVISION = r"""
use Fleetbase\Models\User;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
try {
 $result=DB::transaction(function()use($input) {
  $company=Company::where('uuid',$input['company'])->lockForUpdate()->firstOrFail();
  $role=Role::where('id',$input['role'])->lockForUpdate()->first();
  if(!$role){
   if(Role::where('name','MAPA Operator')->where('guard_name','sanctum')->where('company_uuid',$company->uuid)->exists())
    throw new RuntimeException('Operator role already exists with another identity.');
   $role=new Role();$role->id=$input['role'];$role->name='MAPA Operator';
   $role->guard_name='sanctum';$role->company_uuid=$company->uuid;$role->saveQuietly();
  }
  if($role->name!=='MAPA Operator'||$role->guard_name!=='sanctum'
    ||$role->company_uuid!==$company->uuid||$role->policies()->exists())
   throw new RuntimeException('Operator role is not least-privileged.');
  if(User::withTrashed()->where('email',$input['email'])->where('uuid','<>',$input['user'])->exists())
   throw new RuntimeException('Operator login identifier belongs to another account.');
  $user=User::withTrashed()->where('uuid',$input['user'])->lockForUpdate()->first();
  $created=!$user;
  if($user){
   if($user->deleted_at||$user->company_uuid!==$company->uuid||$user->email!==$input['email']
      ||$user->_key!==$input['marker']||$user->type!=='user'||$user->status!=='active'
      ||data_get($user->meta,'provisioning_marker')!==$input['marker']
      ||!$user->email_verified_at||!Hash::check($input['password'],$user->password))
    throw new RuntimeException('Existing operator identity differs; no reset performed.');
  }else{
   $user=new User();$user->uuid=$input['user'];$user->public_id='user_mapa_operator';
   $user->_key=$input['marker'];$user->company_uuid=$company->uuid;
   $user->name='MAPA Operator';$user->email=$input['email'];
   $user->username='mapa-operator';$user->slug='mapa-operator';
   $user->type='user';$user->status='active';$user->country='IN';$user->timezone='Asia/Kolkata';
   $user->meta=['mapa_operator'=>true,'provisioning_marker'=>$input['marker'],
     'purpose'=>'MAPA call intake and operational monitoring',
     'recovery_email_pending'=>true];
   $user->password=$input['password'];$user->email_verified_at=now();$user->saveQuietly();
  }
  $membership=CompanyUser::withTrashed()->where('uuid',$input['membership'])->lockForUpdate()->first();
  if($membership){
   if($membership->deleted_at||$membership->company_uuid!==$company->uuid
      ||$membership->user_uuid!==$user->uuid||$membership->_key!==$input['marker']
      ||$membership->status!=='active'||$membership->external
      ||$membership->roles()->count()!==1||!$membership->roles()->where('roles.id',$role->id)->exists())
    throw new RuntimeException('Existing operator membership differs.');
  }else{
   if(!$created||CompanyUser::withTrashed()->where('user_uuid',$user->uuid)->exists())
    throw new RuntimeException('Operator membership requires review.');
   $membership=new CompanyUser();$membership->uuid=$input['membership'];
   $membership->_key=$input['marker'];$membership->company_uuid=$company->uuid;
   $membership->user_uuid=$user->uuid;$membership->status='active';$membership->external=false;
   $membership->saveQuietly();CompanyUser::withoutEvents(fn()=> $membership->assignSingleRole($role));
  }
  $binding=DB::table('mapa_mobile_bindings')->where(['company_uuid'=>$company->uuid,'user_uuid'=>$user->uuid])->lockForUpdate()->first();
  $expected=['id'=>$input['binding'],'company_uuid'=>$company->uuid,'user_uuid'=>$user->uuid,
    'role'=>'operator','driver_uuid'=>null,'place_uuid'=>null,'enabled'=>1,'revision'=>1];
  if($binding){foreach($expected as $field=>$value)
    if((string)($binding->$field??'')!==(string)($value??''))throw new RuntimeException('Existing operator binding differs.');
  }else DB::table('mapa_mobile_bindings')->insert([...$expected,'created_at'=>now(),'updated_at'=>now()]);
  $user->setRelation('companyUser',$membership);
  if($user->isAdmin()||$user->role?->id!==$role->id||$user->session_status!=='active')
   throw new RuntimeException('Least-privilege native role verification failed.');
  Fleetbase\Services\UserCacheService::invalidateUser($user);
  app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
  return ['created'=>$created,'user_uuid'=>$user->uuid,'role'=>'operator','company_uuid'=>$company->uuid];
 });
 echo 'MAPA_RESULT:'.json_encode($result);
}catch(Throwable $error){fwrite(STDERR,'MAPA operator provisioning failed; sensitive details suppressed.');exit(1);}
"""

def password_for_provision():
    if PRIVATE.exists():
        rows = dict(line.split(": ", 1) for line in PRIVATE.read_text(encoding="utf-8").splitlines() if ": " in line)
        if rows.get("Email") != EMAIL or rows.get("Company UUID") != COMPANY or rows.get("Marker") != MARKER:
            raise RuntimeError("Existing operator credential metadata differs.")
        password = rows.get("Password", "")
        if len(password) < 28:
            raise RuntimeError("Existing operator password is too short.")
        return password
    PRIVATE.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    password = "Mapa-" + secrets.token_urlsafe(27) + "!9"
    descriptor = os.open(PRIVATE, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, "w", encoding="utf-8") as output:
        output.write("MAPA Operator · temporary login identifier; replace with a real recovery email\n"
                     f"URL: {BASE}/call-intake/\nEmail: {EMAIL}\nPassword: {password}\n"
                     f"Company UUID: {COMPANY}\nMarker: {MARKER}\n"
                     "Access: New Call and Operations in the MAPA operator desk; personal settings only.\n"
                     "Not allowed: crew enrollment, GPS device setup, integrations, or automatic-dispatch settings.\n")
    return password

def docker_php(docker, code, payload):
    encoded = base64.b64encode(json.dumps(payload).encode()).decode()
    script = BOOTSTRAP + "$input=json_decode(base64_decode('" + encoded + "'),true,512,JSON_THROW_ON_ERROR);\n" + code
    result = subprocess.run(
        [docker, "exec", "-i", "mapa-vps-api-1", "php", "-d", "xdebug.mode=off"],
        input=script, text=True, capture_output=True, timeout=60,
    )
    if result.returncode or "MAPA_RESULT:" not in result.stdout:
        raise RuntimeError("MAPA operator account operation failed; details suppressed to protect credentials.")
    return json.loads(result.stdout.rsplit("MAPA_RESULT:", 1)[1])

def request(path, token=None, payload=None):
    headers = {"Accept": "application/json", "User-Agent": "MAPA-Operator-Acceptance/1.0"}
    if token:
        headers["Authorization"] = "Bearer " + token
    data = None
    if payload is not None:
        headers["Content-Type"] = "application/json"
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

def verify(password):
    token = None
    try:
        status, body = request("/mapa/mobile/v1/login", payload={
            "email": EMAIL, "password": password, "device_name": "MAPA operator acceptance"})
        if status != 200 or not isinstance(body, dict) or not isinstance(body.get("token"), str):
            raise RuntimeError(f"Operator login failed (HTTP {status}).")
        token = body["token"]
        if body.get("user", {}).get("role") != "operator" or body.get("user", {}).get("company_id") != COMPANY:
            raise RuntimeError("Operator login returned an unexpected scope.")
        for endpoint in ["/mapa/voice/v1/calls", "/mapa/voice/v1/command-desk"]:
            status, _ = request(endpoint, token=token)
            if status != 200:
                raise RuntimeError(f"Operator read failed: {endpoint} HTTP {status}")
        for endpoint in ["/mapa/mobile/v1/enrollments", "/mapa/mobile/v1/tracking/devices"]:
            status, _ = request(endpoint, token=token)
            if status not in (403, 404):
                raise RuntimeError(f"Administrative endpoint exposed: {endpoint} HTTP {status}")
        status, _ = request("/mapa/voice/v1/demo", token=token, payload={"action_id": str(uuid.uuid4())})
        if status != 403:
            raise RuntimeError(f"Training mutation exposed (HTTP {status}).")
        print("PASS operator login, call queue, operations; admin endpoints and training mutation denied.")
    finally:
        if token:
            status, _ = request("/mapa/mobile/v1/logout", token=token, payload={})
            if status != 200:
                raise RuntimeError("Operator test session logout failed.")

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--docker", required=True)
    args = parser.parse_args()
    password = password_for_provision()
    result = docker_php(args.docker, PROVISION, {
        "company": COMPANY, "role": ROLE_ID, "user": USER_ID, "membership": MEMBERSHIP_ID,
        "binding": BINDING_ID, "marker": MARKER, "email": EMAIL, "password": password})
    if result.get("user_uuid") != USER_ID or result.get("role") != "operator":
        raise RuntimeError("Operator provisioning result did not match expected identity.")
    verify(password)
    print(f"Operator ready. Private credentials: {PRIVATE}")

if __name__ == "__main__":
    main()

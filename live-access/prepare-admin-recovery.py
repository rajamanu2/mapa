"""Prepare native, single-use recovery for the authorized ASR Health owner.

No password is chosen or changed. No notifications are sent. The recovery link
is written only to the private local directory, never to public console files.
"""
import argparse
import json
from pathlib import Path
import subprocess
import urllib.parse
import urllib.request
import urllib.error

p = argparse.ArgumentParser()
p.add_argument('--docker', required=True)
p.add_argument('--issue', action='store_true', help='Issue a recovery link only after the owner requests account recovery.')
a = p.parse_args()
if not a.issue:
    raise SystemExit('No change: explicit --issue required for an authorized recovery request.')
php = r'''<?php
require '/fleetbase/api/vendor/autoload.php';
$app=require '/fleetbase/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$u=Fleetbase\Models\User::where('uuid','3c648de0-64c5-4b0c-9197-35994ffd6bba')->where('email','rajamanu2@gmail.com')->whereNull('deleted_at')->firstOrFail();
if($u->company_uuid!=='29eff7bc-7b9b-4d8a-b1b4-55f33681bd2d'||$u->status!=='active'||!$u->isAdmin())throw new RuntimeException('Administrator identity mismatch');
$v=Illuminate\Support\Facades\DB::transaction(function()use($u){
    Fleetbase\Models\VerificationCode::where('subject_uuid',$u->uuid)->where('for','password_reset')->where('status','active')->update(['status'=>'expired']);
    return Fleetbase\Models\VerificationCode::create([
        'subject_uuid'=>$u->uuid,'subject_type'=>Fleetbase\Support\Utils::getModelClassName($u),
        'for'=>'password_reset','expires_at'=>now()->addMinutes(15),'status'=>'active',
        'meta'=>['email'=>$u->email,'requested_via'=>'owner_authorized_local_recovery'],
    ]);
});
echo json_encode(['id'=>$v->uuid,'code'=>(string)$v->code,'expires_at'=>$v->expires_at->toIso8601String()]);
'''
result = subprocess.run([a.docker,'exec','-i','mapa-live-access-api-1','php','-d','xdebug.mode=off'],input=php,text=True,capture_output=True)
if result.returncode:
    raise SystemExit('Recovery preparation failed; diagnostic output suppressed to protect account data.')
data = json.loads(result.stdout)
origin = 'https://mapa-asr-health.pages.dev'
validation = origin + '/api/int/v1/auth/validate-verification?' + urllib.parse.urlencode({'id':data['id'],'for':'password_reset'})
request = urllib.request.Request(validation, headers={'Accept':'application/json','User-Agent':'MAPA-Live-Acceptance/1.0'})
try:
    with urllib.request.urlopen(request, timeout=25) as response:
        if not json.load(response).get('is_valid'):
            raise SystemExit('Native recovery validation failed; no password changed.')
except urllib.error.HTTPError as error:
    raise SystemExit('Recovery endpoint returned status ' + str(error.code) + '; no password changed.') from None
url = origin + '/auth/reset-password/' + data['id'] + '?' + urllib.parse.urlencode({'code':data['code']})
private = Path(__file__).resolve().parents[2] / 'private/live-access'
private.mkdir(parents=True, exist_ok=True)
file = private / 'Administrator-recovery.md'
file.write_text('# ASR Health administrator recovery\n\n'
    'Account: **rajamanu2@gmail.com**\n\n'
    f'[Set your MAPA password]({url})\n\n'
    'Open the link, enter your new password and its confirmation, then submit it yourself. '
    'The verification code is already in the link. Do not share this recovery file.\n\n'
    f'The link expires at **{data["expires_at"]}** and can be used once. '
    'Afterward sign in at https://mapa-asr-health.pages.dev/auth using the email above.\n\n'
    'No password has been selected, stored or changed by preparing this link.\n', encoding='utf-8')
print('Private recovery file: private/live-access/Administrator-recovery.md')
print('Native link validated. Expires:', data['expires_at'])
print('No password changed and no email, call or SMS sent.')

"""Create the user-authorized 25 numbered ASR accounts, with pending mobile enrollment.

No driver, ambulance, location, operational call, notification or mailbox is created.
Private credentials are saved before any native write; re-runs never reset passwords.
"""
import argparse
import base64
import json
from pathlib import Path
import runpy
import secrets
import subprocess
import sys
import uuid

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
PRIVATE = ROOT / 'private' / 'crew-rollout'
DATA = PRIVATE / 'Numbered-crew-credentials.json'
HANDOFF = PRIVATE / 'User-1-to-25-logins.txt'
MARKER = 'mapa-asr-numbered-crew-v1'
COMPANY = '29eff7bc-7b9b-4d8a-b1b4-55f33681bd2d'
COMMON = runpy.run_path(str(HERE / 'provision-customer-access.py'))


def native(docker, code, payload):
    encoded = base64.b64encode(json.dumps(payload).encode()).decode()
    script = COMMON['BOOTSTRAP'] + "$input=json_decode(base64_decode('" + encoded + "'),true,512,JSON_THROW_ON_ERROR);\n" + code
    result = subprocess.run([docker, 'exec', '-i', 'mapa-live-access-api-1', 'php', '-d', 'xdebug.mode=off'],
                            input=script, text=True, capture_output=True, timeout=90)
    if result.returncode or 'MAPA_RESULT:' not in result.stdout:
        raise RuntimeError('Native operation failed; sensitive output suppressed.')
    return json.loads(result.stdout.rsplit('MAPA_RESULT:', 1)[1])


def private_accounts(create):
    if DATA.exists():
        stored = json.loads(DATA.read_text(encoding='utf-8'))
        if stored.get('marker') != MARKER or stored.get('company') != COMPANY:
            raise RuntimeError('Private scope mismatch.')
        return stored['accounts']
    if not create:
        raise RuntimeError('Private credential handoff is missing.')
    accounts = []
    for number in range(1, 26):
        account = {'number': number, 'name': f'User {number}', 'email': f'user{number}@asrhealth.in',
                   'password': secrets.token_urlsafe(18)}
        for kind in ('user', 'membership', 'binding'):
            account[kind] = str(uuid.uuid5(uuid.NAMESPACE_URL, f'{MARKER}:{COMPANY}:{number}:{kind}'))
        accounts.append(account)
    PRIVATE.mkdir(parents=True, exist_ok=True)
    with DATA.open('x', encoding='utf-8') as handle:
        json.dump({'marker': MARKER, 'company': COMPANY, 'accounts': accounts}, handle, indent=2)
    return accounts


def handoff(accounts, applied):
    lines = ['MAPA / ASR Health — PRIVATE administrator handoff', '',
             'Distribute only the relevant individual credential to its assigned crew member.',
             'These email-shaped IDs do not create email mailboxes.',
             'Server: https://mapa-asr-health.pages.dev',
             'APK: https://mapa-asr-health.pages.dev/downloads/MAPA-Crew-1.11.0.apk', '',
             'Status: ' + ('25 native accounts created/verified.' if applied else 'Prepared only; native account creation has not yet been confirmed.'),
             'ANDROID SIGN-IN IS PENDING: link the actual native crew/ambulance and enable the matching crew binding first.',
             'No placeholder driver, ambulance, GPS, duty or dispatch was created.', '',]
    for a in accounts:
        lines.extend([a['name'], 'Login ID: ' + a['email'], 'Password: ' + a['password'],
                      'Assigned real crew member: ____________________', 'Ambulance registration / shift: ____________________', ''])
    HANDOFF.write_text('\n'.join(lines), encoding='utf-8')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    modes = parser.add_mutually_exclusive_group(required=True)
    modes.add_argument('--prepare', action='store_true')
    modes.add_argument('--provision', action='store_true')
    modes.add_argument('--verify-only', action='store_true')
    parser.add_argument('--docker', required=True)
    args = parser.parse_args()
    accounts = private_accounts(args.prepare or args.provision)
    if args.prepare:
        handoff(accounts, False)
    code = (HERE / 'provision-numbered-crew.php').read_text(encoding='utf-8').removeprefix('<?php')
    payload = {'marker': MARKER, 'company': COMPANY, 'accounts': accounts, 'apply': args.provision, 'verify': args.verify_only}
    result = native(args.docker, code, payload)
    if args.verify_only and result['created']:
        raise RuntimeError('Verification must not create missing accounts.')
    if args.provision or args.verify_only:
        handoff(accounts, True)
    print(json.dumps({**result, 'credential_handoff': str(HANDOFF), 'passwords_printed': False}, indent=2))


if __name__ == '__main__':
    try:
        main()
    except Exception:
        print('MAPA numbered account operation failed; credentials and native diagnostics were suppressed. Review private state before retrying.', file=sys.stderr)
        sys.exit(1)

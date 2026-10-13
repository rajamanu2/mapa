#!/usr/bin/env python3
"""Restore a private MAPA export onto an EMPTY Linux Docker host project.

Never starts a tunnel. Run from the release directory with the reviewed cloud
files copied alongside the export. The default verifies files without mutation.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import subprocess
import sys
import tarfile

HERE = Path(__file__).resolve().parent
COMPOSE = ['docker', 'compose', '--project-name', 'mapa-cloud', '--env-file', str(HERE / '.env'), '-f', str(HERE / 'compose.yml')]
TRANSFER_TAGS = ('mapa-cloud-api:transfer', 'mapa-cloud-console:transfer', 'mapa-cloud-voice-bridge:transfer')
# Verified in the serving core AuthController: native console logins use the
# Sanctum User tokenable type. Business keys live in api_credentials instead.
NATIVE_USER_TYPE_HEX = 'Fleetbase\\Models\\User'.encode('utf-8').hex()
REVOKE_SESSIONS_SQL = (
    'START TRANSACTION; '
    'UPDATE mapa_mobile_sessions SET revoked_at=CAST(UNIX_TIMESTAMP(CURRENT_TIMESTAMP(3))*1000 AS UNSIGNED) '
    'WHERE revoked_at IS NULL; '
    'DELETE FROM personal_access_tokens WHERE tokenable_type=CONVERT(0x' + NATIVE_USER_TYPE_HEX + ' USING utf8mb4); '
    'COMMIT;'
)


def command(args, *, stdin=None, capture=False, timeout=1800):
    result = subprocess.run(args, cwd=HERE, stdin=stdin, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, check=False, timeout=timeout)
    if result.returncode:
        # Docker output can include interpolated env or SQL data. Keep it private.
        raise RuntimeError(f'Command failed (exit {result.returncode}); inspect service status privately.')
    return result.stdout.decode('utf-8', errors='strict').strip() if capture else None


def safe_path(name):
    if not isinstance(name, str) or not name:
        raise RuntimeError('Invalid export path')
    item = PurePosixPath(name)
    if item.is_absolute() or '..' in item.parts or '\\' in name or ':' in name:
        raise RuntimeError('Invalid export path')
    target = HERE.joinpath(*item.parts)
    if not target.resolve().is_relative_to(HERE.resolve()) or target.is_symlink():
        raise RuntimeError('Export path leaves release directory')
    if any(parent.is_symlink() for parent in target.parents if parent != HERE and parent.is_relative_to(HERE)):
        raise RuntimeError('Export path contains a symbolic link')
    return target


def validate():
    if (HERE / 'INCOMPLETE').exists():
        raise RuntimeError('Export is incomplete')
    manifest = json.loads((HERE / 'manifest.json').read_text(encoding='utf-8'))
    if manifest.get('format') != 'mapa-cloud-export-v1' or manifest.get('complete') is not True:
        raise RuntimeError('Unrecognized or incomplete export manifest')
    required = {'.env', 'images.tar', 'database.sql', 'uploads.tar', 'voice-journal.tar'}
    if not required.issubset(manifest.get('files', {})):
        raise RuntimeError('Required migration files missing from manifest')
    if not isinstance(manifest.get('images'), dict) or set(manifest['images']) != set(TRANSFER_TAGS):
        raise RuntimeError('Expected transfer image identities missing')
    if any(not re.fullmatch(r'sha256:[0-9a-f]{64}', image_id) for image_id in manifest['images'].values()):
        raise RuntimeError('Invalid transfer image identity')
    if 'raw-console/index.html' not in manifest['files']:
        raise RuntimeError('Exported console is missing from manifest')
    for name, expected in manifest['files'].items():
        if not isinstance(expected, dict) or type(expected.get('bytes')) is not int or expected['bytes'] < 0 or not re.fullmatch(r'[0-9a-f]{64}', str(expected.get('sha256', ''))):
            raise RuntimeError('Invalid export file metadata')
        path = safe_path(name)
        if not path.is_file() or path.stat().st_size != expected['bytes']:
            raise RuntimeError('Export file missing or wrong size')
        with path.open('rb') as stream:
            actual = hashlib.file_digest(stream, 'sha256').hexdigest()
        if actual != expected['sha256']:
            raise RuntimeError('Export checksum mismatch')
    for name in ('uploads.tar', 'voice-journal.tar'):
        with tarfile.open(HERE / name) as archive:
            for member in archive:
                p = PurePosixPath(member.name)
                if p.is_absolute() or '..' in p.parts or not (member.isfile() or member.isdir()):
                    raise RuntimeError('Unsupported or unsafe archive member')
    if (HERE / 'database.sql').stat().st_size == 0:
        raise RuntimeError('Database dump is empty')
    if not safe_path('cloud-console/index.html').is_file():
        raise RuntimeError('Prepare cloud-console with runtime-config.mjs first')
    if any(path.is_symlink() for path in (HERE / 'cloud-console').rglob('*')):
        raise RuntimeError('Prepared cloud console contains symbolic links')
    print('Export checksums, archive paths and cloud console verified.')
    return manifest


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--restore-empty-server', action='store_true')
    args = parser.parse_args()
    manifest = validate()
    if not args.restore_empty_server:
        print('Verification only. No Docker resources changed.')
        return
    if os.name != 'posix':
        raise RuntimeError('Restore must run on the destination Linux server')
    if (HERE / 'RESTORED').exists():
        raise RuntimeError('This export was already restored; use the upgrade procedure instead')
    os.chmod(HERE / '.env', 0o600)
    command(COMPOSE + ['config', '--quiet'])
    # Refuse an existing target rather than overwrite data or silently merge projects.
    existing_containers = command(['docker', 'ps', '-aq', '--filter', 'label=com.docker.compose.project=mapa-cloud'], capture=True)
    if existing_containers:
        raise RuntimeError('Target cloud containers already exist. No restore was performed.')
    existing = command(['docker', 'volume', 'ls', '--format', '{{.Name}}'], capture=True).splitlines()
    if any(name.startswith('mapa-cloud_') for name in existing):
        raise RuntimeError('Target cloud volumes already exist. No restore was performed.')
    command(['docker', 'load', '--input', str(HERE / 'images.tar')])
    for tag in TRANSFER_TAGS:
        loaded = command(['docker', 'image', 'inspect', '--format', '{{.Id}}', tag], capture=True)
        if loaded != manifest['images'][tag]:
            raise RuntimeError('Loaded image does not match the exported image identity')
    command(COMPOSE + ['build', 'application'])
    command(COMPOSE + ['up', '-d', '--wait', '--wait-timeout', '300', 'database', 'cache'])
    query = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='fleetbase';"
    count = command(COMPOSE + ['exec', '-T', 'database', 'sh', '-c',
                              'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -B -e "$1"', 'sh', query], capture=True)
    if count != '0':
        raise RuntimeError('Cloud database is not empty; restore refused')
    with (HERE / 'database.sql').open('rb') as data:
        command(COMPOSE + ['exec', '-T', 'database', 'sh', '-c',
                           'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot fleetbase'], stdin=data)
    for archive, volume in [('uploads.tar', 'mapa-cloud_uploads'), ('voice-journal.tar', 'mapa-cloud_voice_journal')]:
        command(['docker', 'volume', 'create', volume])
        with (HERE / archive).open('rb') as data:
            command(['docker', 'run', '--rm', '-i', '--network', 'none', '--user', '0:0', '--read-only',
                     '--cap-drop', 'ALL', '--cap-add', 'CHOWN', '--cap-add', 'DAC_OVERRIDE', '--cap-add', 'FOWNER',
                     '--security-opt', 'no-new-privileges',
                     '--mount', f'type=volume,source={volume},target=/restore',
                     '--entrypoint', 'sh', 'redis:7-alpine', '-c', 'tar xf - -C /restore'], stdin=data)
    # Existing user accounts and role bindings remain; devices must sign in again.
    command(COMPOSE + ['exec', '-T', 'database', 'sh', '-c',
                       'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot fleetbase -e "$1"', 'sh', REVOKE_SESSIONS_SQL])
    command(COMPOSE + ['up', '-d', 'application', 'queue', 'scheduler', 'socket', 'voice-bridge', 'gateway'])
    (HERE / 'RESTORED').write_text('Restored; tunnel not started; external acceptance still required.\n', encoding='utf-8')
    print('Cloud copy started privately. Verify sign-in before starting the Cloudflare tunnel.')


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, RuntimeError, KeyError, TypeError, tarfile.TarError, subprocess.SubprocessError):
        print('Cloud restore stopped. Check files, manifest and private Docker status; no automatic deletion was attempted.', file=sys.stderr)
        sys.exit(1)

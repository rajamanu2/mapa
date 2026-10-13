#!/usr/bin/env python3
"""Read-only fail-closed VPS preflight. Does not display configuration values."""
import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import sys

HERE = Path(__file__).resolve().parent

def env(path):
    result = {}
    for raw in path.read_text(encoding='utf-8').splitlines():
        line = raw.strip()
        if not line or line.startswith('#'):
            continue
        key, sep, value = line.partition('=')
        if not sep or not re.fullmatch(r'[A-Z][A-Z0-9_]*', key) or key in result:
            raise ValueError('Invalid or duplicate env key')
        # A simple shared subset of Compose/systemd env parsing. Never source as shell code.
        if any(c in value for c in ('$', '\n', '\r', '"', "'", '`')):
            raise ValueError('Use literal unquoted env values without interpolation')
        result[key] = value
    return result

def checks(root, launch=False):
    errors = []
    def require(ok, message):
        if not ok: errors.append(message)
    for name in ('.env', 'private/api.env'):
        require((root / name).is_file(), f'Missing private configuration: {name}')
    if errors: return errors
    try:
        cfg, api = env(root / '.env'), env(root / 'private/api.env')
    except (ValueError, OSError):
        return ['Private env files have invalid syntax; values have not been printed']
    for path in (root / '.env', root / 'private/api.env'):
        require(not path.is_symlink(), 'Private env must not be a symbolic link')
        if os.name != 'nt':
            require(path.stat().st_mode & 0o077 == 0, 'Private env must have mode 0600')
    host = cfg.get('MAPA_PUBLIC_HOST', '')
    require(bool(re.fullmatch(r'(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}', host))
            and not host.endswith(('.invalid', '.localhost', '.trycloudflare.com', '.pages.dev')), 'Set a permanent exact DNS hostname')
    require('@' in cfg.get('MAPA_TLS_EMAIL', '') and '.invalid' not in cfg.get('MAPA_TLS_EMAIL', ''), 'Set a working certificate contact')
    for key in ('MYSQL_PASSWORD', 'MYSQL_ROOT_PASSWORD'):
        value = cfg.get(key, '')
        require(len(value) >= 32 and not any(x in value.lower() for x in ('required', 'replace', 'example')), f'Set a generated {key}')
    require(bool(re.fullmatch(r'[a-zA-Z0-9][a-zA-Z0-9._/@:-]+', cfg.get('MAPA_API_BASE_IMAGE', ''))), 'Specify the reviewed imported API image')
    require(cfg.get('MYSQL_PASSWORD') != cfg.get('MYSQL_ROOT_PASSWORD'), 'Database passwords must differ')
    uuid = r'[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}'
    require(bool(re.fullmatch(uuid, cfg.get('MAPA_COMPANY_UUID', ''))), 'Retain existing company UUID')
    require(api.get('MAPA_COMPANY_UUID') == cfg.get('MAPA_COMPANY_UUID'), 'API and gateway company must match')
    admins = api.get('MAPA_MOBILE_SUPERADMIN_UUIDS', '').split(',')
    require(bool(admins) and all(re.fullmatch(uuid, item) for item in admins), 'Retain reviewed explicit mobile administrator UUIDs')
    try:
        key = api.get('APP_KEY', '')
        require(key.startswith('base64:') and len(base64.b64decode(key[7:], validate=True)) == 32, 'Retain existing 32-byte application key')
    except Exception:
        errors.append('Application key format invalid')
    require(not api.get('DATABASE_URL'), 'Remove legacy DATABASE_URL so it cannot override VPS database configuration')
    for key in ('DB_HOST', 'DB_PASSWORD', 'REDIS_HOST', 'APP_URL', 'APP_DEBUG'):
        require(key not in api, f'Remove duplicated deployment variable {key} from private/api.env')
    require((root / 'private/provider-secrets').is_dir(), 'Create private/provider-secrets directory (empty until configured)')
    try:
        manifest = json.loads((root / 'runtime/manifest.json').read_text())
        require(bool(manifest), 'Runtime source manifest empty')
        for name, digest in manifest.items():
            p = root / 'runtime' / name
            require(p.resolve().is_relative_to((root / 'runtime').resolve()) and not p.is_symlink()
                    and p.is_file() and hashlib.sha256(p.read_bytes()).hexdigest() == digest, 'Runtime snapshot missing or changed')
    except Exception:
        errors.append('Prepare and verify runtime source snapshot')
    console = root / 'public-console'
    require((console / 'index.html').is_file(), 'Stage the reviewed console adapted to permanent origin')
    if console.exists():
        for p in console.rglob('*'):
            require(not p.is_symlink(), 'Public console may not contain symlinks')
            require(not any(x.lower() in ('private', 'backups', 'storage', 'raw-console', 'node_modules', '.git') or x.startswith('.') for x in p.relative_to(console).parts), 'Private path found in public console')
            require(p.suffix.lower() not in ('.env', '.sql', '.sqlite', '.key', '.pem', '.p12', '.jsonl', '.log'), 'Private file type found in public console')
    if launch:
        try:
            receipt = json.loads((root / 'private/acceptance.json').read_text())
            for key in ('fresh_export_verified', 'image_digest_verified', 'database_restored', 'uploads_restored',
                        'migration_reviewed', 'console_origin_verified', 'private_storage_verified', 'login_verified',
                        'backup_restore_verified', 'android_origin_verified', 'dns_verified'):
                require(receipt.get(key) is True, f'Launch gate not verified: {key}')
            require(receipt.get('host') == host, 'Acceptance receipt belongs to another host')
        except Exception:
            errors.append('Missing private acceptance receipt; do not launch public services yet')
    return sorted(set(errors))

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--launch', action='store_true')
    args = parser.parse_args()
    failures = checks(HERE, args.launch)
    for failure in failures: print('BLOCKED: ' + failure)
    if not failures: print('Preflight passed. This is configuration validation, not live acceptance.')
    sys.exit(1 if failures else 0)

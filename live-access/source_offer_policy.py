"""Fail-closed release checks. Source licences do not require production secrets/data."""
from pathlib import PurePosixPath
import json
import re
import stat

ROOTS = {'fleetbase', 'fleetops-source', 'asr-customization', 'mapa-android', 'mapa-voice-bridge'}
ROOT_FILES = {'Start-MAPA.ps1', 'Start MAPA.cmd', 'Start-MAPA-Demo.ps1', 'Start MAPA Demo.cmd', 'MAPA-NAMING.md'}
BLOCKED_PARTS = {'private', '.git', '.aws', '.ssh', '__pycache__', 'node_modules', 'vendor',
                 'backups', 'uploads', 'logs', 'fixtures', 'test-results', 'playwright-report'}
BLOCKED_SUFFIXES = {'.map', '.sql', '.sqlite', '.sqlite3', '.db', '.log', '.bak', '.dump',
                    '.pem', '.key', '.p12', '.pfx', '.jks', '.keystore', '.zip', '.tar', '.gz', '.7z'}
SAFE_DOTFILES = {'.gitignore', '.gitattributes', '.dockerignore', '.editorconfig', '.npmrc',
                 '.nvmrc', '.prettierrc', '.prettierignore', '.eslintrc', '.eslintignore',
                 '.template-lintrc.js', '.eslintrc.js', '.eslintrc.json', '.prettierrc.js',
                 '.ember-cli', '.watchmanconfig', '.env.example', '.env.sample', '.github',
                 '.gitmodules', '.gitkeep', '.htaccess', '.styleci.yml', '.stylelintignore',
                 '.stylelintrc.js', '.helmignore', '.s3deploy.yml', '.ssm-parent.yaml'}
PUBLIC_BUILD_ENV = {'fleetbase/console/environments/.env.development',
                    'fleetbase/console/environments/.env.production'}
PUBLIC_ENV_KEYS = {'API_HOST', 'API_NAMESPACE', 'API_SECURE', 'SOCKETCLUSTER_PATH',
                   'SOCKETCLUSTER_HOST', 'SOCKETCLUSTER_SECURE', 'SOCKETCLUSTER_PORT', 'OSRM_HOST'}
MAX_FILE_BYTES = 16 * 1024 * 1024

def validate_name(name):
    parts = name.split('/')
    if (not name or '\\' in name or ':' in name or any(p in ('', '.', '..') for p in parts)
            or any(ord(c) < 32 for c in name)):
        raise ValueError('Unsafe source entry path')
    if len(parts) == 1:
        if name not in ROOT_FILES:
            raise ValueError('Unreviewed source root file')
    elif parts[0] not in ROOTS:
        raise ValueError('Unreviewed source root')
    for part in parts:
        lower = part.lower()
        empty_log_placeholder = name == 'fleetbase/api/storage/logs/.gitignore'
        if ((lower in BLOCKED_PARTS and not empty_log_placeholder)
                or (lower.startswith('.') and lower not in SAFE_DOTFILES and name not in PUBLIC_BUILD_ENV)):
            raise ValueError('Private or generated source entry')
    leaf = parts[-1].lower()
    if PurePosixPath(leaf).suffix in BLOCKED_SUFFIXES or leaf in {
        'google-services.json', 'credentials.json', 'service-account.json', 'serviceaccount.json',
        'provider-settings.json', 'cutover-state.json', 'known_hosts'}:
        raise ValueError('Private data or generated archive entry')

def validate_content(name, content):
    validate_name(name)
    if len(content) > MAX_FILE_BYTES:
        raise ValueError('Oversized source file')
    if name in PUBLIC_BUILD_ENV:
        # These checked-in frontend build inputs only describe already-public services.
        for line in content.decode('utf-8').splitlines():
            line = line.strip()
            if not line or line.startswith('#'):
                continue
            key, separator, value = line.partition('=')
            if not separator or key not in PUBLIC_ENV_KEYS or '@' in value or '?' in value or '#' in value:
                raise ValueError('Unreviewed frontend environment setting')
    if name == 'fleetbase/api/storage/logs/.gitignore' and content.strip() not in (b'*\n!.gitignore', b'*\r\n!.gitignore'):
        raise ValueError('Unexpected log placeholder content')
    # Real private keys must never appear even when hidden inside an innocent filename.
    if re.search(rb'-----BEGIN (?:RSA |EC |OPENSSH |ENCRYPTED )?PRIVATE KEY-----\r?\n[A-Za-z0-9+/=\r\n]{64,}-----END ', content):
        raise ValueError('Private key in source content')
    if re.search(rb'(?:AKIA|ASIA)[A-Z0-9]{16}', content):
        raise ValueError('Cloud credential in source content')
    if re.search(rb'(?:_authToken|_password)\s*=\s*(?!\$\{)[A-Za-z0-9_+/=-]{12,}', content):
        raise ValueError('Package credential in source content')
    if name.lower().endswith('.json'):
        try:
            value = json.loads(content)
        except (ValueError, UnicodeError):
            return  # Source may deliberately contain malformed JSON regression examples.
        def walk(item):
            if isinstance(item, dict):
                for key, val in item.items():
                    if key in {'private_key', 'refresh_token', 'client_secret'} and val:
                        raise ValueError('Credential object in source content')
                    walk(val)
            elif isinstance(item, list):
                for child in item:
                    walk(child)
        walk(value)

def validate_archive(archive):
    seen = set()
    total = 0
    for entry in archive.infolist():
        name = entry.filename
        key = name.casefold()
        if key in seen:
            raise ValueError('Duplicate source archive path')
        seen.add(key)
        validate_name(name)
        mode = entry.external_attr >> 16
        if stat.S_ISLNK(mode) or entry.flag_bits & 1:
            raise ValueError('Linked or encrypted source archive entry')
        total += entry.file_size
        if entry.file_size > MAX_FILE_BYTES or total > 256 * 1024 * 1024 or len(seen) > 20000:
            raise ValueError('Source archive exceeds review bounds')
        validate_content(name, archive.read(entry))
    return len(seen)

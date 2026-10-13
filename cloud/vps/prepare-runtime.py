#!/usr/bin/env python3
"""Snapshot only reviewed runtime source. Never copies credentials or console data."""
from pathlib import Path
import hashlib
import json
import shutil

HERE = Path(__file__).resolve().parent
FLEETBASE = HERE.parents[1]
LIVE = FLEETBASE / 'live-access'
VOICE_PUBLIC_FILES = ('asr-places.json', 'asr-landmarks.json', 'asr-directory.json', 'PICKUP-MAPS.md')

def voice_public_files():
    module = FLEETBASE / 'asr' / 'voice'
    if module.is_symlink() or not module.resolve().is_relative_to(FLEETBASE.resolve()):
        raise SystemExit('Refusing redirected voice data directory')
    sources = []
    for name in VOICE_PUBLIC_FILES:
        source = module / name
        if source.is_symlink() or not source.is_file() or source.resolve().parent != module.resolve():
            raise SystemExit('Missing or redirected reviewed public map file: ' + name)
        sources.append(source)
    return sources

def prepare():
    dest = HERE / 'runtime'
    if dest.exists():
        raise SystemExit('runtime already exists; archive it privately and review changes before preparing a new release')
    # Exact public-data allowlist: never copy arbitrary JSON, provider settings or call exports.
    map_files = voice_public_files()
    # Refuse unfamiliar upstream changes rather than silently losing the gate.
    gateway = (LIVE / 'gateway.conf.template').read_text(encoding='utf-8')
    required = ['auth_request /_mapa_access;', 'internal;', 'autoindex off;',
                '$http_cf_connecting_ip', 'asr-fleetbase-pilot-socket-1:8000']
    if not all(x in gateway for x in required):
        raise SystemExit('Live gateway structure changed; review the VPS adapter first')
    gateway = gateway.replace('limit_req_zone $http_cf_connecting_ip', 'limit_req_zone $binary_remote_addr')
    gateway = gateway.replace('asr-fleetbase-pilot-socket-1:8000', 'socket:8000')
    gateway = gateway.replace('proxy_set_header Origin http://localhost:4200;', 'proxy_set_header Origin https://$mapa_public_host;')
    # Caddy is the only published entrypoint. Trust only the private front network.
    # Configure a fixed front subnet so clients cannot inject rate-limit identity.
    gateway = gateway.replace('    listen 8080 default_server;',
        '    listen 8080 default_server;\n    set_real_ip_from 172.30.89.0/24;\n    real_ip_header X-Forwarded-For;\n    real_ip_recursive off;')
    proxy = (LIVE / 'proxy.conf').read_text().replace('$http_cf_connecting_ip', '$remote_addr')
    dest.mkdir()
    (dest / 'gateway.conf.template').write_text(gateway, encoding='utf-8', newline='\n')
    (dest / 'proxy.conf').write_text(proxy, encoding='utf-8', newline='\n')
    for source, target in [(LIVE / 'access.mjs', dest / 'access.mjs'),
                           (HERE.parent / 'TrustProxies.php', dest / 'TrustProxies.php')]:
        shutil.copyfile(source, target)
    for module in ('mobile', 'voice'):
        for source in (FLEETBASE / 'asr' / module).rglob('*.php'):
            if source.is_symlink():
                raise SystemExit('Refusing symlink in module source')
            target = dest / module / source.relative_to(FLEETBASE / 'asr' / module)
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(source, target)
    for source in map_files:
        target = dest / 'voice' / source.name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source, target)
    overrides = {
        'SettingController.php': 'Controllers/Internal/v1/SettingController.php',
        'LiveController.php': 'Controllers/Internal/v1/LiveController.php',
        'OrderResource.php': 'Resources/v1/Order.php',
        'CurrentJobResource.php': 'Resources/v1/CurrentJob.php',
        'VehicleResource.php': 'Resources/v1/Vehicle.php',
        'VehicleWithoutDriverResource.php': 'Resources/v1/VehicleWithoutDriver.php',
        'VehicleIndexResource.php': 'Resources/v1/Index/Vehicle.php',
    }
    for name, relative in overrides.items():
        target = dest / 'overrides' / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(LIVE / 'overrides' / name, target)
    manifest = {str(p.relative_to(dest)).replace('\\', '/'): hashlib.sha256(p.read_bytes()).hexdigest()
                for p in sorted(dest.rglob('*')) if p.is_file()}
    (dest / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    print(f'Prepared {len(manifest)} source files. No database, credentials, or public console copied.')

if __name__ == '__main__':
    prepare()

"""Guarded publication of the MAPA dispatch-settings JavaScript only.

Run on the MAPA VPS after staging this directory. No API, call, GPS, or
message data is changed. The current live references are discovered at check
time instead of assuming an old asset name.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shutil
import urllib.request
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = Path('/opt/mapa-staging/release/public-console')
BACKUP = HERE / 'rollback'
RECEIPT = HERE / 'receipt.json'
INDEXES = ('index.html',)
SOURCE_SHA = 'ceb62c820970e0e4b1983c8654c45a5dc9d24341049c1e76e73dabf4f9da0485'
NEW_ASSET = 'mapa-dispatch-settings-' + SOURCE_SHA[:16] + '.js'
SCRIPT_REF = re.compile(
    r'(?P<before>\bsrc\s*=\s*["\'])(?P<url>/mapa-dispatch-settings(?:-[0-9a-f]{16,32})?\.js(?:\?v=[^"\'<>\s]+)?)(?P<after>["\'])',
    re.IGNORECASE,
)


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def atomic(path: Path, payload: bytes) -> None:
    temp = path.with_name('.' + path.name + '.mapa-next')
    temp.write_bytes(payload)
    os.chmod(temp, 0o644)
    os.replace(temp, path)


def one_script_ref(html: str, label: str) -> str:
    refs = list(SCRIPT_REF.finditer(html))
    if len(refs) != 1:
        raise RuntimeError(f'{label}: expected exactly one MAPA voice script reference; found {len(refs)}')
    return refs[0].group('url')


def replace_script_ref(html: str, label: str) -> str:
    one_script_ref(html, label)
    updated, count = SCRIPT_REF.subn(lambda match: match.group('before') + '/' + NEW_ASSET + match.group('after'), html)
    if count != 1 or one_script_ref(updated, label) != '/' + NEW_ASSET:
        raise RuntimeError(f'{label}: script reference replacement failed')
    return updated


def inspect(root: Path = ROOT) -> dict:
    if sha(HERE / 'mapa-dispatch-settings.js') != SOURCE_SHA:
        raise RuntimeError('Release JavaScript failed its reviewed SHA-256 check')
    if BACKUP.exists() or RECEIPT.exists():
        raise RuntimeError('This release already has a backup or receipt; inspect before retrying')
    plain = root / 'mapa-dispatch-settings.js'
    if not plain.is_file():
        raise RuntimeError('Missing live plain MAPA voice asset')
    indexes = {}
    active = {}
    for label in INDEXES:
        index = root / label
        if not index.is_file():
            raise RuntimeError('Missing live index: ' + label)
        ref = one_script_ref(index.read_text(encoding='utf-8'), label)
        asset_name = ref.split('?', 1)[0].lstrip('/')
        asset = root / asset_name
        if not asset.is_file():
            raise RuntimeError(f'{label}: referenced live asset is missing: {asset_name}')
        indexes[label] = {'sha256': sha(index), 'reference': ref}
        active[asset_name] = sha(asset)
    if (root / NEW_ASSET).exists():
        raise RuntimeError('The new immutable asset name already exists; review the live release')
    state = {'indexes': indexes, 'active_assets': active, 'plain_sha256': sha(plain),
             'new_asset': NEW_ASSET, 'new_sha256': SOURCE_SHA}
    state['state_sha256'] = hashlib.sha256(json.dumps(state, sort_keys=True).encode()).hexdigest()
    return state


def publish(root: Path, expected_state: str) -> None:
    if not re.fullmatch(r'[a-f0-9]{64}', expected_state or ''):
        raise RuntimeError('Supply the exact state SHA-256 returned by --check')
    state = inspect(root)
    if state['state_sha256'] != expected_state:
        raise RuntimeError('Live index or asset changed after inspection; review before publishing')
    names = [*INDEXES, 'mapa-dispatch-settings.js', *state['active_assets']]
    names = list(dict.fromkeys(names))
    originals = {name: (root / name).read_bytes() for name in names}
    updated_indexes = {name: replace_script_ref(originals[name].decode('utf-8'), name).encode('utf-8')
                       for name in INDEXES}
    BACKUP.mkdir(mode=0o700)
    for name, payload in originals.items():
        destination = BACKUP / name
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_bytes(payload)
    created = root / NEW_ASSET
    try:
        atomic(created, (HERE / 'mapa-dispatch-settings.js').read_bytes())
        atomic(root / 'mapa-dispatch-settings.js', (HERE / 'mapa-dispatch-settings.js').read_bytes())
        for name, payload in updated_indexes.items():
            atomic(root / name, payload)
        after = {name: sha(root / name) for name in INDEXES}
        if sha(created) != SOURCE_SHA or sha(root / 'mapa-dispatch-settings.js') != SOURCE_SHA:
            raise RuntimeError('Published MAPA voice asset failed SHA-256 verification')
        for name in INDEXES:
            if sha(root / name) != hashlib.sha256(updated_indexes[name]).hexdigest() or \
                    one_script_ref((root / name).read_text(encoding='utf-8'), name) != '/' + NEW_ASSET:
                raise RuntimeError('Published index failed reference verification: ' + name)
        receipt = {'state_before': state, 'before': {name: hashlib.sha256(payload).hexdigest()
                                                     for name, payload in originals.items()},
                   'after': {**after, 'mapa-dispatch-settings.js': SOURCE_SHA, NEW_ASSET: SOURCE_SHA},
                   'created_asset': NEW_ASSET}
        RECEIPT.write_text(json.dumps(receipt, indent=2) + '\n', encoding='utf-8')
    except Exception:
        for name, payload in originals.items():
            atomic(root / name, payload)
        created.unlink(missing_ok=True)
        shutil.rmtree(BACKUP)
        RECEIPT.unlink(missing_ok=True)
        raise
    print(json.dumps({'published': True, 'asset': NEW_ASSET, 'indexes': after, 'backup': str(BACKUP)}))


def rollback(root: Path) -> None:
    if not BACKUP.is_dir() or not RECEIPT.is_file():
        raise RuntimeError('No completed release backup and receipt found')
    receipt = json.loads(RECEIPT.read_text(encoding='utf-8'))
    if any(not (root / name).is_file() or sha(root / name) != digest
           for name, digest in receipt['after'].items()):
        raise RuntimeError('Live release changed after publication; refuse blind rollback')
    if any(not (BACKUP / name).is_file() or sha(BACKUP / name) != digest
           for name, digest in receipt['before'].items()):
        raise RuntimeError('Rollback backup changed; refuse rollback')
    for name in receipt['before']:
        atomic(root / name, (BACKUP / name).read_bytes())
    (root / receipt['created_asset']).unlink()
    if any(sha(root / name) != digest for name, digest in receipt['before'].items()):
        raise RuntimeError('Rollback verification failed')
    print(json.dumps({'rolled_back': True, 'indexes': {name: sha(root / name) for name in INDEXES}}))


def verify_public(base: str) -> None:
    if not re.fullmatch(r'https://[a-zA-Z0-9.-]+/?', base):
        raise RuntimeError('Supply an HTTPS site origin without path or query')
    base = base.rstrip('/')
    for name in INDEXES:
        req = urllib.request.Request(base + '/' + name, headers={'Cache-Control': 'no-cache'})
        with urllib.request.urlopen(req, timeout=15) as response:
            html = response.read().decode('utf-8')
        if one_script_ref(html, name) != '/' + NEW_ASSET:
            raise RuntimeError('Public page has not served the new script reference: ' + name)
    req = urllib.request.Request(base + '/' + NEW_ASSET, headers={'Cache-Control': 'no-cache'})
    with urllib.request.urlopen(req, timeout=15) as response:
        payload = response.read()
    if hashlib.sha256(payload).hexdigest() != SOURCE_SHA:
        raise RuntimeError('Public JavaScript SHA-256 does not match the reviewed release')
    print(json.dumps({'public_verified': True, 'asset': NEW_ASSET, 'sha256': SOURCE_SHA}))


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check', action='store_true')
    mode.add_argument('--apply', action='store_true')
    mode.add_argument('--rollback', action='store_true')
    mode.add_argument('--verify-public', action='store_true')
    parser.add_argument('--expected-state-sha')
    parser.add_argument('--public-base')
    parser.add_argument('--root', type=Path, default=ROOT)
    args = parser.parse_args()
    if args.check:
        print(json.dumps(inspect(args.root), sort_keys=True))
    elif args.apply:
        publish(args.root, args.expected_state_sha)
    elif args.rollback:
        rollback(args.root)
    else:
        verify_public(args.public_base or '')

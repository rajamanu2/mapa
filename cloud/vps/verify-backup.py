#!/usr/bin/env python3
"""Validate an already-restored private backup payload; never extract or execute it.

This is an integrity/archive safety gate, not proof that SQL restores or apps work.
Run in an isolated rehearsal host before importing SQL or extracting uploads.
"""
import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import tarfile

REQUIRED = {'database.sql', 'uploads.tar', 'compose.env', 'private/api.env',
            'compose.yml', 'compose.override.yml', 'staging.yml', 'images.json',
            'runtime-manifest.json', 'CONSISTENCY.txt'}

def safe_name(name):
    if '\\' in name or '\x00' in name or ':' in name or any(ord(c) < 32 for c in name):
        raise ValueError('Unsafe payload path')
    path = PurePosixPath(name)
    if path.is_absolute() or '..' in path.parts:
        raise ValueError('Unsafe payload path')
    return path.as_posix().removeprefix('./')

def validate(root, max_files=100000, max_upload_bytes=100 * 1024**3):
    root = Path(root)
    if root.is_symlink() or not root.is_dir():
        raise ValueError('Payload must be a real directory')
    manifest = root / 'SHA256SUMS'
    if manifest.is_symlink() or not manifest.is_file() or manifest.stat().st_size > 16 * 1024**2:
        raise ValueError('Missing or oversized checksum manifest')
    hashes = {}
    for line in manifest.read_text(encoding='utf-8').splitlines():
        match = re.fullmatch(r'([0-9a-f]{64}) [ *](.+)', line)
        if not match:
            raise ValueError('Malformed checksum manifest')
        name = safe_name(match[2])
        if name in hashes or name in ('.', 'SHA256SUMS'):
            raise ValueError('Duplicate or invalid checksum entry')
        hashes[name] = match[1]
    if not REQUIRED.issubset(hashes) or len(hashes) > max_files:
        raise ValueError('Missing required payload or file limit exceeded')
    actual = set()
    for path in root.rglob('*'):
        if path.is_symlink():
            raise ValueError('Payload symlinks forbidden')
        if path.is_dir():
            continue
        if not path.is_file():
            raise ValueError('Non-regular payload file')
        name = path.relative_to(root).as_posix()
        if name == 'SHA256SUMS':
            continue
        actual.add(name)
        if name not in hashes or name in ('private/backup.env', 'private/restic-password'):
            raise ValueError('Unlisted payload or backup storage key found')
        with path.open('rb') as file:
            digest = hashlib.file_digest(file, 'sha256').hexdigest()
        if digest != hashes[name]:
            raise ValueError('Payload checksum mismatch')
    if actual != set(hashes) or not (root / 'database.sql').stat().st_size:
        raise ValueError('Missing payload or empty SQL')
    names, files, size = set(), 0, 0
    with tarfile.open(root / 'uploads.tar', 'r:') as archive:
        for entry in archive:
            name = safe_name(entry.name)
            if name in names:
                raise ValueError('Duplicate upload archive member')
            names.add(name)
            if not (entry.isfile() or entry.isdir()) or (name == '.' and not entry.isdir()):
                raise ValueError('Upload links, devices and special members forbidden')
            files += int(entry.isfile())
            size += entry.size
            if len(names) > max_files or size > max_upload_bytes:
                raise ValueError('Upload archive exceeds review limits')
    return {'integrity_verified': True, 'payload_files': len(actual), 'upload_files': files,
            'upload_bytes': size, 'database_restore_verified': False, 'application_acceptance_verified': False}

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('payload', help='Private snapshot payload directory containing SHA256SUMS')
    args = parser.parse_args()
    try:
        report = validate(args.payload)
    except (OSError, ValueError, tarfile.TarError, UnicodeError):
        print(json.dumps({'integrity_verified': False, 'error': 'Backup payload verification failed; keep isolated and inspect privately'}))
        return 1
    print(json.dumps(report))
    return 0

if __name__ == '__main__':
    raise SystemExit(main())

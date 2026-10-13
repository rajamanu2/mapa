"""Offline, synthetic recovery and external monitor failure-path tests."""
import hashlib
from datetime import datetime, timezone
import importlib.util
import io
import json
from pathlib import Path
import tarfile
import tempfile
import unittest
from unittest.mock import patch
import urllib.error

HERE = Path(__file__).parent
def load(name, filename):
    spec = importlib.util.spec_from_file_location(name, HERE / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module
backup = load('backup_verify', 'verify-backup.py')
uptime = load('uptime', 'uptime-check.py')
freshness = load('freshness', 'backup-freshness.py')

class PayloadSafety(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        for name in backup.REQUIRED:
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('DEMO isolated fixture')
        self.archive()
        self.hashes()
    def tearDown(self): self.temp.cleanup()
    def archive(self, name='./DEMO/photo.jpg', kind=tarfile.REGTYPE, repeat=False):
        with tarfile.open(self.root / 'uploads.tar', 'w') as file:
            member = tarfile.TarInfo(name)
            member.type = kind
            member.linkname = '/etc/passwd' if kind in (tarfile.SYMTYPE, tarfile.LNKTYPE) else ''
            member.size = 4 if kind == tarfile.REGTYPE else 0
            file.addfile(member, io.BytesIO(b'DEMO') if member.size else None)
            if repeat: file.addfile(member, io.BytesIO(b'DEMO'))
    def hashes(self):
        lines = []
        for path in sorted(self.root.rglob('*')):
            if path.is_file() and path.name != 'SHA256SUMS':
                lines.append(hashlib.sha256(path.read_bytes()).hexdigest() + '  ./' + path.relative_to(self.root).as_posix())
        (self.root / 'SHA256SUMS').write_text('\n'.join(lines) + '\n')
    def test_valid_payload_is_not_restore_acceptance(self):
        result = backup.validate(self.root)
        self.assertTrue(result['integrity_verified'])
        self.assertFalse(result['database_restore_verified'])
        self.assertEqual(result['upload_files'], 1)
    def test_corrupt_sql_rejected(self):
        (self.root / 'database.sql').write_text('changed')
        with self.assertRaises(ValueError): backup.validate(self.root)
    def test_missing_override_rejected_even_with_rehashed_manifest(self):
        (self.root / 'compose.override.yml').unlink(); self.hashes()
        with self.assertRaises(ValueError): backup.validate(self.root)
    def test_unlisted_secret_rejected(self):
        (self.root / 'private/unlisted.env').write_text('DEMO')
        with self.assertRaises(ValueError): backup.validate(self.root)
    def test_storage_keys_rejected_even_if_hashed(self):
        for name in ('private/backup.env', 'private/restic-password'):
            with self.subTest(name=name):
                (self.root / name).write_text('DEMO'); self.hashes()
                with self.assertRaises(ValueError): backup.validate(self.root)
                (self.root / name).unlink()
    def test_unsafe_upload_names_rejected(self):
        for name in ('../outside', '/etc/passwd', 'C:/private', '..\\private'):
            with self.subTest(name=name):
                self.archive(name); self.hashes()
                with self.assertRaises(ValueError): backup.validate(self.root)
    def test_upload_links_and_devices_rejected(self):
        for kind in (tarfile.SYMTYPE, tarfile.LNKTYPE, tarfile.CHRTYPE, tarfile.FIFOTYPE):
            with self.subTest(kind=kind):
                self.archive(kind=kind); self.hashes()
                with self.assertRaises(ValueError): backup.validate(self.root)
    def test_duplicate_uploads_rejected(self):
        self.archive(repeat=True); self.hashes()
        with self.assertRaises(ValueError): backup.validate(self.root)
    def test_archive_limit_rejected(self):
        with self.assertRaises(ValueError): backup.validate(self.root, max_upload_bytes=3)
    def test_checksum_traversal_rejected(self):
        with (self.root / 'SHA256SUMS').open('a') as file: file.write('0'*64 + '  ../outside\n')
        with self.assertRaises(ValueError): backup.validate(self.root)
    def test_checksum_alias_duplicate_rejected(self):
        first = (self.root / 'SHA256SUMS').read_text().splitlines()[0]
        with (self.root / 'SHA256SUMS').open('a') as file: file.write(first.replace('./', '') + '\n')
        with self.assertRaises(ValueError): backup.validate(self.root)
    def test_empty_sql_rejected(self):
        (self.root / 'database.sql').write_bytes(b''); self.hashes()
        with self.assertRaises(ValueError): backup.validate(self.root)

class Response(io.BytesIO):
    def __init__(self, code, body=b'', kind='application/json', url='https://mapa.invalid/api/int/v1/users/me', location=None):
        super().__init__(body)
        self.status, self.url = code, url
        self.headers = {'Content-Type': kind, 'Location': location}

class MonitorSafety(unittest.TestCase):
    def test_freshness_rejects_missing_stale_future_and_malformed_markers(self):
        now = datetime(2026, 10, 5, 0, 0, tzinfo=timezone.utc)
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'last-success'
            self.assertFalse(freshness.check(path, now)['fresh'])
            for value, expected in [('2026-10-04T23:00:00Z', True),
                                    ('2026-10-03T20:00:00Z', False),
                                    ('2026-10-05T01:00:00Z', False),
                                    ('2026-10-04T23:00:00', False),
                                    ('not a timestamp', False), ('X' * 101, False)]:
                with self.subTest(value=value):
                    path.write_text(value)
                    result = freshness.check(path, now)
                    self.assertEqual(result['fresh'], expected)
                    self.assertFalse(result['restore_verified'])
    def test_denial_must_be_json_401_with_errors(self):
        for code, body, kind, expected in [(401, b'{"errors":[]}', 'application/json', True),
                                          (200, b'{"errors":[]}', 'application/json', False),
                                          (401, b'<html>denied</html>', 'text/html', False),
                                          (401, b'{"user":"DEMO"}', 'application/json', False)]:
            with self.subTest(code=code, body=body), patch.object(uptime.urllib.request, 'urlopen', return_value=Response(code, body, kind)):
                self.assertEqual(uptime.check('https://mapa.invalid', '/api/int/v1/users/me'), expected)
    def test_http_error_401_is_expected_denial(self):
        response = urllib.error.HTTPError('https://mapa.invalid/api/int/v1/users/me', 401, 'Unauthorized', {'Content-Type':'application/json'}, io.BytesIO(b'{"errors":[]}'))
        with patch.object(uptime.urllib.request, 'urlopen', side_effect=response):
            self.assertTrue(uptime.check('https://mapa.invalid', '/api/int/v1/users/me'))
    def test_redirected_api_is_failure(self):
        with patch.object(uptime.urllib.request, 'urlopen', return_value=Response(401, b'{"errors":[]}', url='https://other.invalid/api/int/v1/users/me')):
            self.assertFalse(uptime.check('https://mapa.invalid', '/api/int/v1/users/me'))
    def test_redirect_requires_exact_canonical_location(self):
        for location, expected in [('https://mapa.invalid/fleet-ops', True), ('https://other.invalid/fleet-ops', False), ('https://mapa.invalid/auth', False)]:
            with self.subTest(location=location), patch.object(uptime.urllib.request, 'build_opener') as opener:
                opener.return_value.open.return_value = Response(302, location=location)
                self.assertEqual(uptime.check_redirect('https://old.invalid', 'https://mapa.invalid'), expected)
    def test_network_failure_fails_closed(self):
        with patch.object(uptime.urllib.request, 'build_opener') as opener:
            opener.return_value.open.side_effect = TimeoutError()
            self.assertFalse(uptime.check_redirect('https://old.invalid', 'https://mapa.invalid'))

if __name__ == '__main__': unittest.main()

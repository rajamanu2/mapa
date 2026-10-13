#!/usr/bin/env python3
"""Restore contract tests using synthetic files and a fake Docker command."""
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import sys
import tarfile
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch


spec = importlib.util.spec_from_file_location('mapa_restore', Path(__file__).with_name('restore-cloud.py'))
restore = importlib.util.module_from_spec(spec)
spec.loader.exec_module(restore)


class RestoreTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.addCleanup(self.temp.cleanup)
        self.base = patch.object(restore, 'HERE', self.root)
        self.base.start()
        self.addCleanup(self.base.stop)
        for name in ('.env', 'images.tar', 'database.sql', 'raw-console/index.html', 'cloud-console/index.html'):
            path = self.root / name
            path.parent.mkdir(exist_ok=True)
            path.write_text('SYNTHETIC FIXTURE ONLY', encoding='utf-8')
        for name in ('uploads.tar', 'voice-journal.tar'):
            self.tar(name, 'data.txt')
        self.manifest = {
            'format': 'mapa-cloud-export-v1', 'complete': True,
            'images': {tag: 'sha256:' + str(i) * 64 for i, tag in enumerate(restore.TRANSFER_TAGS)},
            'files': {},
        }
        self.write_manifest()
        self.calls = []

    def tar(self, name, member, *, symlink=False):
        with tarfile.open(self.root / name, 'w') as archive:
            item = tarfile.TarInfo(member)
            if symlink:
                item.type = tarfile.SYMTYPE
                item.linkname = '/etc/passwd'
                archive.addfile(item)
            else:
                content = b'SYNTHETIC ONLY'
                item.size = len(content)
                archive.addfile(item, io.BytesIO(content))

    def write_manifest(self):
        import hashlib
        for name in ('.env', 'images.tar', 'database.sql', 'uploads.tar', 'voice-journal.tar', 'raw-console/index.html'):
            data = (self.root / name).read_bytes()
            self.manifest['files'][name] = {'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()}
        (self.root / 'manifest.json').write_text(json.dumps(self.manifest), encoding='utf-8')

    def fake_command(self, args, **kwargs):
        self.calls.append(args)
        if args[:3] == ['docker', 'image', 'inspect']:
            return self.manifest['images'][args[-1]]
        if any('SELECT COUNT(*)' in arg for arg in args):
            return '0'
        return ''

    def main(self, *, mutate=True, command=None):
        args = ['restore-cloud.py'] + (['--restore-empty-server'] if mutate else [])
        with patch.object(sys, 'argv', args), patch.object(restore, 'command', side_effect=command or self.fake_command), patch.object(restore, 'os', SimpleNamespace(name='posix', chmod=lambda *args: None)), contextlib.redirect_stdout(io.StringIO()):
            restore.main()

    def test_verify_only_does_not_call_docker(self):
        self.main(mutate=False)
        self.assertEqual(self.calls, [])
        self.assertFalse((self.root / 'RESTORED').exists())

    def test_modified_export_fails(self):
        (self.root / '.env').write_text('modified', encoding='utf-8')
        with self.assertRaises(RuntimeError):
            restore.validate()

    def test_incomplete_export_fails(self):
        (self.root / 'INCOMPLETE').write_text('incomplete', encoding='utf-8')
        with self.assertRaises(RuntimeError):
            restore.validate()

    def test_traversal_manifest_fails(self):
        self.manifest['files']['../secret'] = self.manifest['files']['.env']
        self.write_manifest()
        with self.assertRaises(RuntimeError):
            restore.validate()

    def test_tar_path_and_symlink_rejected(self):
        for name, linked in (('../escape.txt', False), ('/etc/passwd', False), ('link', True)):
            with self.subTest(name=name):
                self.tar('uploads.tar', name, symlink=linked)
                self.write_manifest()
                with self.assertRaises(RuntimeError):
                    restore.validate()

    def test_existing_target_container_stops_before_load(self):
        def command(args, **kwargs):
            if args[:3] == ['docker', 'ps', '-aq']:
                self.calls.append(args)
                return 'existing-container'
            return self.fake_command(args, **kwargs)
        with self.assertRaisesRegex(RuntimeError, 'containers already exist'):
            self.main(command=command)
        self.assertFalse(any(args[:2] == ['docker', 'load'] for args in self.calls))

    def test_existing_target_volume_stops_before_load(self):
        def command(args, **kwargs):
            if args[:3] == ['docker', 'volume', 'ls']:
                self.calls.append(args)
                return 'mapa-cloud_mysql'
            return self.fake_command(args, **kwargs)
        with self.assertRaisesRegex(RuntimeError, 'volumes already exist'):
            self.main(command=command)
        self.assertFalse(any(args[:2] == ['docker', 'load'] for args in self.calls))

    def test_loaded_image_mismatch_blocks_build(self):
        def command(args, **kwargs):
            if args[:3] == ['docker', 'image', 'inspect']:
                self.calls.append(args)
                return 'sha256:' + 'f' * 64
            return self.fake_command(args, **kwargs)
        with self.assertRaisesRegex(RuntimeError, 'image does not match'):
            self.main(command=command)
        self.assertFalse(any('build' in args for args in self.calls))

    def test_restore_revokes_scoped_sessions_before_start(self):
        self.main()
        revocation = next(i for i, args in enumerate(self.calls) if restore.REVOKE_SESSIONS_SQL in args)
        startup = next(i for i, args in enumerate(self.calls) if 'up' in args and 'application' in args)
        self.assertLess(revocation, startup)
        self.assertIn('CURRENT_TIMESTAMP(3))*1000', restore.REVOKE_SESSIONS_SQL)
        self.assertIn('tokenable_type=CONVERT(0x466c656574626173655c4d6f64656c735c55736572', restore.REVOKE_SESSIONS_SQL)
        self.assertNotIn('api_credentials', restore.REVOKE_SESSIONS_SQL)
        self.assertNotIn('settings', restore.REVOKE_SESSIONS_SQL)
        self.assertTrue((self.root / 'RESTORED').exists())
        self.assertFalse(any('tunnel' in args for args in self.calls))

    def test_database_nonempty_blocks_import(self):
        def command(args, **kwargs):
            if any('SELECT COUNT(*)' in arg for arg in args):
                self.calls.append(args)
                return '4'
            return self.fake_command(args, **kwargs)
        with self.assertRaisesRegex(RuntimeError, 'database is not empty'):
            self.main(command=command)
        self.assertFalse(any(restore.REVOKE_SESSIONS_SQL in args for args in self.calls))
        self.assertFalse((self.root / 'RESTORED').exists())


if __name__ == '__main__':
    unittest.main(verbosity=2)

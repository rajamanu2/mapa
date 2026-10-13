"""Exercise the backup shell in an isolated Linux container with fake Docker/restic."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).with_name('backup.sh')

class BackupSafety(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        for folder in ['bin', 'private/provider-secrets', 'runtime']:
            (self.root / folder).mkdir(parents=True)
        for name in ['compose.yml', 'compose.override.yml', 'staging.yml', '.env', 'private/api.env', 'runtime/manifest.json']:
            (self.root / name).write_text('DEMO isolated fixture\n')
        (self.root / 'private/provider-secrets/example.json').write_text('DEMO provider fixture')
        self.password = self.root / 'private/restic-password'
        self.password.write_text('DEMO-not-a-real-key')
        self.password.chmod(0o600)
        (self.root / 'private/backup.env').write_text('DEMO storage credentials must not be copied')
        self.env = dict(os.environ, PATH=str(self.root / 'bin') + ':' + os.environ['PATH'],
                        MAPA_RELEASE_DIRECTORY=str(self.root), MAPA_EXPECTED_MYSQL_VOLUME='DEMO-final-mysql',
                        MAPA_EXPECTED_UPLOADS_VOLUME='DEMO-final-uploads', RESTIC_REPOSITORY='s3:https://storage.invalid/DEMO',
                        RESTIC_PASSWORD_FILE=str(self.password), TEST_LOG=str(self.root / 'calls.log'))
        self.mock('docker', '''#!/bin/sh
echo "$*" >> "$TEST_LOG"
if [ "$1" = inspect ]; then
 case "$*" in *DEMO-db) echo "${TEST_VOLUME:-DEMO-final-mysql}";; *DEMO-api) echo DEMO-final-uploads;; *) exit 90;; esac
else
 case "$*" in
  'compose --project-name mapa-vps -f compose.yml -f compose.override.yml -f staging.yml '*) ;;
  *) exit 91;;
 esac
 case "$*" in
  *'ps -q database') echo DEMO-db;;
  *'ps -q api') echo DEMO-api;;
  *mysqldump*) [ "${TEST_DUMP_FAIL:-0}" = 0 ] || exit 9; echo 'DEMO SQL';;
  *'exec -T api tar'*) echo 'DEMO tar';;
  *'images --format json') echo '{}';;
  *) exit 92;;
 esac
fi
''')
        self.mock('flock', '#!/bin/sh\nexit 0\n')
        self.mock('restic', '''#!/bin/sh
echo restic >> "$TEST_LOG"
for stage; do :; done
[ "${TEST_RESTIC_FAIL:-0}" = 0 ] || exit 10
test ! -f "$stage/private/restic-password" || exit 11
test ! -f "$stage/private/backup.env" || exit 12
test -f "$stage/compose.override.yml" || exit 13
test -f "$stage/private/provider-secrets/example.json" || exit 14
cd "$stage" && sha256sum -c SHA256SUMS >/dev/null
''')
    def mock(self, name, text):
        p = self.root / 'bin' / name
        p.write_text(text); p.chmod(0o755)
    def tearDown(self): self.temp.cleanup()
    def run_backup(self):
        return subprocess.run(['/bin/sh', str(SCRIPT)], env=self.env, capture_output=True, text=True)
    def log(self):
        p = self.root / 'calls.log'
        return p.read_text() if p.exists() else ''
    def test_success_checks_all_payload_hashes_and_excludes_storage_keys(self):
        result = self.run_backup()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('restic', self.log())
        self.assertFalse(list(Path('/var/backups/mapa').glob('.stage.*')))
    def test_wrong_volume_prevents_any_dump(self):
        self.env['TEST_VOLUME'] = 'DEMO-obsolete-mysql'
        self.assertNotEqual(self.run_backup().returncode, 0)
        self.assertNotIn('mysqldump', self.log())
    def test_missing_override_prevents_docker(self):
        (self.root / 'compose.override.yml').unlink()
        self.assertNotEqual(self.run_backup().returncode, 0)
        self.assertEqual(self.log(), '')
    def test_local_repository_rejected(self):
        self.env['RESTIC_REPOSITORY'] = '/tmp/not-independent'
        self.assertNotEqual(self.run_backup().returncode, 0)
        self.assertEqual(self.log(), '')
    def test_world_readable_password_rejected(self):
        self.password.chmod(0o644)
        self.assertNotEqual(self.run_backup().returncode, 0)
        self.assertEqual(self.log(), '')
    def test_failed_dump_never_uploaded(self):
        self.env['TEST_DUMP_FAIL'] = '1'
        self.assertNotEqual(self.run_backup().returncode, 0)
        self.assertNotIn('restic', self.log())
    def test_failed_upload_does_not_update_success(self):
        success = Path('/var/backups/mapa/last-success')
        before = success.read_bytes() if success.exists() else None
        self.env['TEST_RESTIC_FAIL'] = '1'
        self.assertNotEqual(self.run_backup().returncode, 0)
        self.assertEqual(success.read_bytes() if success.exists() else None, before)

if __name__ == '__main__': unittest.main()

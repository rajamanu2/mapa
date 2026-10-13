import importlib.util
import io
from pathlib import Path
import stat
import unittest
import zipfile

spec = importlib.util.spec_from_file_location('source_offer_policy', Path(__file__).resolve().parents[1] / 'source_offer_policy.py')
policy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(policy)

class SourceOfferSafety(unittest.TestCase):
    def reject(self, name, payload=b'ordinary source'):
        with self.assertRaises(ValueError):
            policy.validate_content(name, payload)

    def test_source_and_licence_allowed(self):
        for name in ['fleetbase/LICENSE.md', 'fleetbase/asr/mobile/TrackingService.php',
                     'fleetbase/asr/sms/gateway.py', 'fleetbase/console/public/track/tracking.js']:
            policy.validate_content(name, b'original source')

    def test_traversal_and_unreviewed_roots(self):
        for name in ['../private/file', '/fleetbase/app.js', 'fleetbase/../private/file',
                     'fleetbase\\app.js', 'fleetbase/C:/file', 'unreviewed/app.js', 'file.json']:
            self.reject(name)

    def test_private_files_and_snapshots(self):
        for leaf in ['.env', '.env.local', '.git/config', 'private/settings.json', 'a.sql',
                     'a.map', 'backup.zip', 'google-services.json', 'provider-settings.json',
                     'fixtures/patients.json', 'logs/api.txt']:
            self.reject('fleetbase/' + leaf)

    def test_private_key_disguised_as_source(self):
        self.reject('fleetbase/app.js', ('-----BEGIN ' + 'PRIVATE KEY-----\n' + 'A' * 80 + '\n-----END PRIVATE KEY-----').encode())

    def test_service_account_disguised_as_json(self):
        self.reject('fleetbase/app.json', b'{"nested":{"private_key":"secret"}}')

    def test_empty_example_credentials_allowed(self):
        policy.validate_content('fleetbase/config.example.json', b'{"private_key":""}')

    def test_frontend_env_public_keys_only(self):
        name = 'fleetbase/console/environments/.env.production'
        policy.validate_content(name, b'API_HOST=https://api.example.test\nAPI_NAMESPACE=int/v1')
        self.reject(name, b'SECRET_TOKEN=anything')
        self.reject(name, b'API_HOST=https://user:password@example.test')
        self.reject(name, b'API_HOST=https://example.test?secret=value')

    def test_log_placeholder_only(self):
        policy.validate_content('fleetbase/api/storage/logs/.gitignore', b'*\n!.gitignore')
        self.reject('fleetbase/api/storage/logs/.gitignore', b'actual log contents')

    def test_entire_archive_including_old_entries_checked(self):
        data = io.BytesIO()
        with zipfile.ZipFile(data, 'w') as z:
            z.writestr('fleetbase/app.js', b'safe')
            z.writestr('fleetbase/private/data.json', b'{}')
        with zipfile.ZipFile(data) as z, self.assertRaises(ValueError):
            policy.validate_archive(z)

    def test_case_collision_rejected(self):
        data = io.BytesIO()
        with zipfile.ZipFile(data, 'w') as z:
            z.writestr('fleetbase/app.js', b'a')
            z.writestr('fleetbase/APP.js', b'b')
        with zipfile.ZipFile(data) as z, self.assertRaises(ValueError):
            policy.validate_archive(z)

    def test_symlink_rejected(self):
        data = io.BytesIO()
        with zipfile.ZipFile(data, 'w') as z:
            entry = zipfile.ZipInfo('fleetbase/app.js')
            entry.external_attr = (stat.S_IFLNK | 0o777) << 16
            z.writestr(entry, b'../secret')
        with zipfile.ZipFile(data) as z, self.assertRaises(ValueError):
            policy.validate_archive(z)

    def test_oversized_entry_rejected(self):
        self.reject('fleetbase/app.js', b'x' * (policy.MAX_FILE_BYTES + 1))

if __name__ == '__main__':
    unittest.main()

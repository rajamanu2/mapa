import base64
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('preflight', HERE / 'preflight.py')
preflight = importlib.util.module_from_spec(spec)
spec.loader.exec_module(preflight)
spec = importlib.util.spec_from_file_location('uptime', HERE / 'uptime-check.py')
uptime = importlib.util.module_from_spec(spec)
spec.loader.exec_module(uptime)
spec = importlib.util.spec_from_file_location('prepare_runtime', HERE / 'prepare-runtime.py')
prepare_runtime = importlib.util.module_from_spec(spec)
spec.loader.exec_module(prepare_runtime)

class RuntimeMapFiles(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.voice = self.root / 'asr/voice'
        self.voice.mkdir(parents=True)
        for name in prepare_runtime.VOICE_PUBLIC_FILES:
            (self.voice / name).write_text('synthetic public map fixture')
        self.base_patch = patch.object(prepare_runtime, 'FLEETBASE', self.root)
        self.base_patch.start()

    def tearDown(self):
        self.base_patch.stop()
        self.temp.cleanup()

    def test_only_named_catalogs_and_notice_selected(self):
        for name in ('private.json', 'provider-settings.json', 'call-export.json', '.env', 'other.md'):
            (self.voice / name).write_text('synthetic excluded fixture')
        (self.voice / 'nested').mkdir()
        (self.voice / 'nested/asr-places.json').write_text('synthetic excluded fixture')
        self.assertEqual({'asr-places.json', 'asr-landmarks.json', 'asr-directory.json', 'PICKUP-MAPS.md'},
                         {source.name for source in prepare_runtime.voice_public_files()})
        self.assertTrue(all(source.parent == self.voice for source in prepare_runtime.voice_public_files()))

    def test_missing_catalog_rejected_before_runtime_created(self):
        (self.voice / 'asr-places.json').unlink()
        with patch.object(prepare_runtime, 'HERE', self.root), self.assertRaises(SystemExit):
            prepare_runtime.prepare()
        self.assertFalse((self.root / 'runtime').exists())

    def test_public_map_files_are_copied_and_manifested(self):
        release = self.root / 'release'
        release.mkdir()
        (self.root / 'TrustProxies.php').write_text('<?php // synthetic source fixture')
        (self.voice / 'private.json').write_text('synthetic excluded fixture')
        with patch.object(prepare_runtime, 'HERE', release), patch('builtins.print'):
            prepare_runtime.prepare()
        manifest = json.loads((release / 'runtime/manifest.json').read_text())
        self.assertNotIn('voice/private.json', manifest)
        for name in prepare_runtime.VOICE_PUBLIC_FILES:
            source = (self.voice / name).read_bytes()
            self.assertEqual(source, (release / 'runtime/voice' / name).read_bytes())
            self.assertEqual(hashlib.sha256(source).hexdigest(), manifest['voice/' + name])

    def test_symlink_is_rejected(self):
        with patch.object(Path, 'is_symlink', return_value=True), self.assertRaises(SystemExit):
            prepare_runtime.voice_public_files()

    def test_resolved_file_outside_module_is_rejected(self):
        resolve = Path.resolve
        def redirect(path, *args, **kwargs):
            return self.root / 'private/asr-places.json' if path.name == 'asr-places.json' else resolve(path, *args, **kwargs)
        with patch.object(Path, 'resolve', redirect), self.assertRaises(SystemExit):
            prepare_runtime.voice_public_files()

class Readiness(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        (self.root / 'private/provider-secrets').mkdir(parents=True)
        (self.root / 'runtime').mkdir()
        (self.root / 'public-console').mkdir()
        (self.root / 'public-console/index.html').write_text('<html>MAPA</html>')
        self.cfg = {'MAPA_PUBLIC_HOST': 'mapa.asrhealth.in', 'MAPA_TLS_EMAIL': 'ops@asrhealth.in',
                    'MAPA_API_BASE_IMAGE': 'mapa-cloud-api:transfer',
                    'MYSQL_PASSWORD': 'a' * 40, 'MYSQL_ROOT_PASSWORD': 'b' * 40,
                    'MAPA_COMPANY_UUID': '12345678-1234-1234-1234-123456789012'}
        self.api = {'MAPA_COMPANY_UUID': self.cfg['MAPA_COMPANY_UUID'], 'APP_KEY': 'base64:' + base64.b64encode(b'x' * 32).decode()}
        self.api['MAPA_MOBILE_SUPERADMIN_UUIDS'] = '12345678-1234-1234-1234-123456789013'
        (self.root / 'runtime/access.mjs').write_text('verified source')
        (self.root / 'runtime/manifest.json').write_text(json.dumps({'access.mjs': hashlib.sha256(b'verified source').hexdigest()}))
        self.write()

    def tearDown(self): self.temp.cleanup()
    def write(self):
        for name, values in (('.env', self.cfg), ('private/api.env', self.api)):
            path = self.root / name
            path.write_text('\n'.join(k + '=' + v for k, v in values.items()))
            path.chmod(0o600)
    def test_configuration_is_not_launch_acceptance(self):
        self.assertEqual([], preflight.checks(self.root))
        self.assertTrue(preflight.checks(self.root, True))
    def test_temporary_domain_rejected(self):
        self.cfg['MAPA_PUBLIC_HOST'] = 'temporary.trycloudflare.com'; self.write()
        self.assertIn('Set a permanent exact DNS hostname', preflight.checks(self.root))
    def test_legacy_database_url_rejected(self):
        self.api['DATABASE_URL'] = 'mysql://old'; self.write()
        self.assertTrue(any('DATABASE_URL' in e for e in preflight.checks(self.root)))
    def test_changed_runtime_rejected(self):
        (self.root / 'runtime/access.mjs').write_text('changed')
        self.assertIn('Runtime snapshot missing or changed', preflight.checks(self.root))
    def test_public_private_files_rejected(self):
        (self.root / 'public-console/backup.sql').write_text('secret')
        self.assertIn('Private file type found in public console', preflight.checks(self.root))
    def test_mismatched_company_rejected(self):
        self.api['MAPA_COMPANY_UUID'] = 'other'; self.write()
        self.assertIn('API and gateway company must match', preflight.checks(self.root))
    def test_failed_http_check_is_false(self):
        with patch('urllib.request.urlopen', side_effect=TimeoutError):
            self.assertFalse(uptime.check('https://mapa.asrhealth.in', '/healthz'))
    def test_gateway_has_authenticated_native_and_socket_routes(self):
        gateway = (HERE / 'runtime/gateway.conf.template').read_text()
        self.assertEqual(2, gateway.count('auth_request /_mapa_access;'))
        self.assertIn('location = /_mapa_access {\n        internal;', gateway)
        self.assertIn('set $socket http://socket:8000;', gateway)
    def test_proxy_identity_and_query_log_boundary(self):
        gateway = (HERE / 'runtime/gateway.conf.template').read_text()
        self.assertIn('access_log off;', gateway)
        self.assertIn('limit_req_zone $binary_remote_addr', gateway)
        self.assertIn('set_real_ip_from 172.30.89.0/24;', gateway)
        self.assertNotIn('$http_cf_connecting_ip', gateway)
        proxy = (HERE / 'runtime/proxy.conf').read_text()
        self.assertIn('proxy_set_header X-Forwarded-For $remote_addr;', proxy)
        self.assertIn('header_up -CF-Connecting-IP', (HERE / 'Caddyfile').read_text())
    def test_private_path_blocks_preserved(self):
        gateway = (HERE / 'runtime/gateway.conf.template').read_text()
        self.assertIn('autoindex off;', gateway)
        self.assertIn('(?:private|raw-console|backups)', gateway)
        self.assertIn('location ~ (^|/)\\. { return 404; }', gateway)

if __name__ == '__main__': unittest.main()

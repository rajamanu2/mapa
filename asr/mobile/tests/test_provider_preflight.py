import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / 'provider-preflight.py'
spec = importlib.util.spec_from_file_location('preflight', SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class Preflight(unittest.TestCase):
    def fixture(self):
        env = dict(MAPA_TRACKING_ORIGIN='https://app.itda.asrhealth.in', MAPA_WHATSAPP_PHONE_ID='123456789',
                   MAPA_WHATSAPP_TOKEN='DEMO-secret-never-output-123', MAPA_WHATSAPP_API_VERSION='v99.0',
                   MAPA_WHATSAPP_TEMPLATE='demo_tracking', MAPA_WHATSAPP_LANGUAGE='en',
                   MAPA_WHATSAPP_APP_SECRET='DEMO-app-secret-12345', MAPA_WHATSAPP_VERIFY_TOKEN='DEMO-verify-secret-12345',
                   MAPA_FCM_PROJECT_ID='demo-project', MAPA_FCM_CREDENTIALS_FILE='/private/demo.json')
        android = {'project_info': {'project_id': 'demo-project', 'project_number': '12345'},
                   'client': [{'client_info': {'android_client_info': {'package_name': 'in.asr.ambulance'},
                                              'mobilesdk_app_id': 'DEMO-app'}, 'api_key': [{'current_key': 'DEMO-key'}]}]}
        server = {'type': 'service_account', 'project_id': 'demo-project',
                  'client_email': 'demo@demo-project.iam.gserviceaccount.com',
                  'private_key': '-----BEGIN PRIVATE KEY-----\nDEMO-NOT-A-VALID-KEY\n-----END PRIVATE KEY-----\n'}
        return env, android, server

    def test_empty_fails_closed(self):
        r = module.inspect({}, {}, {})
        self.assertFalse(r['configuration_complete'])
        self.assertFalse(r['live_ready'])

    def test_complete_structure_never_live_readiness_or_secrets(self):
        r = module.inspect(*self.fixture())
        self.assertTrue(r['configuration_complete'])
        self.assertFalse(r['live_ready'])
        output = json.dumps(r)
        self.assertNotIn('DEMO-secret', output)
        self.assertNotIn('DEMO-NOT-A-VALID-KEY', output)
        self.assertNotIn('demo@', output)

    def test_wrong_package_and_projects(self):
        env, a, s = self.fixture()
        a['client'][0]['client_info']['android_client_info']['package_name'] = 'wrong.app'
        a['project_info']['project_id'] = 'wrong-project'
        s['project_id'] = 'other-project'
        r = module.inspect(env, a, s)
        for name in ('android_package_configured', 'android_project_matches', 'server_project_matches'):
            self.assertFalse(r['checks'][name])

    def test_service_key_in_android_rejected(self):
        env, _, s = self.fixture()
        self.assertFalse(module.inspect(env, s, s)['checks']['android_config_is_not_server_key'])

    def test_nested_service_key_in_android_rejected_without_leak(self):
        env, a, s = self.fixture()
        a['client'][0]['accidental_server_config'] = s
        result = module.inspect(env, a, s)
        self.assertFalse(result['configuration_complete'])
        self.assertNotIn('DEMO-NOT-A-VALID-KEY', json.dumps(result))

    def test_unknown_nested_shapes_fail_closed(self):
        env, a, s = self.fixture()
        for clients in (None, 'bad', [None], [{'client_info': None}], [{'client_info': {'android_client_info': []}}]):
            a['client'] = clients
            self.assertFalse(module.inspect(env, a, s)['configuration_complete'])

    def test_origin_and_flag_dependency(self):
        env, a, s = self.fixture()
        env['MAPA_TRACKING_ORIGIN'] = 'https://app.itda.asrhealth.in.evil.invalid'
        env['MAPA_HOSPITAL_PUSH_ENABLED'] = 'true'
        r = module.inspect(env, a, s)
        self.assertFalse(r['checks']['tracking_origin_canonical'])
        self.assertFalse(r['checks']['hospital_push_flag_consistent'])

    def test_webhook_secrets_match_php_minimum_length(self):
        for key in ('MAPA_WHATSAPP_APP_SECRET', 'MAPA_WHATSAPP_VERIFY_TOKEN'):
            env, a, s = self.fixture()
            env[key] = 'x' * 19
            self.assertFalse(module.inspect(env, a, s)['checks']['whatsapp_webhook_secrets_present'])
            env[key] = 'x' * 20
            self.assertTrue(module.inspect(env, a, s)['checks']['whatsapp_webhook_secrets_present'])

    def test_android_resource_fields_must_be_strings(self):
        for field in ('app', 'key'):
            for value in ([], {'secret': 'DEMO-should-not-output'}, True, 123, ' '):
                env, a, s = self.fixture()
                client = a['client'][0]
                if field == 'app': client['client_info']['mobilesdk_app_id'] = value
                else: client['api_key'][0]['current_key'] = value
                result = module.inspect(env, a, s)
                self.assertFalse(result['configuration_complete'])
                self.assertNotIn('DEMO-should-not-output', json.dumps(result))

    def test_first_matching_android_client_is_the_gradle_selection(self):
        env, a, s = self.fixture()
        a['client'].insert(0, {'client_info': {'android_client_info': {'package_name': 'in.asr.ambulance'}}, 'api_key': []})
        self.assertFalse(module.inspect(env, a, s)['configuration_complete'])

    def test_nonportable_php_boolean_strings_fail_closed(self):
        for value in ('off', 'no', 'yes', 'on', 'false ', '(false)', '"false"', 'unknown'):
            env, a, s = self.fixture()
            env['MAPA_WHATSAPP_ENABLED'] = value
            result = module.inspect(env, a, s)
            self.assertFalse(result['configuration_complete'])
            self.assertIsNone(result['enabled_flags']['MAPA_WHATSAPP_ENABLED'])

    def test_invalid_service_email_rejected(self):
        env, a, s = self.fixture()
        s['client_email'] = 'invalid()@demo-project.iam.gserviceaccount.com'
        self.assertFalse(module.inspect(env, a, s)['checks']['server_email_format'])

    def test_duplicate_json_keys_rejected_without_contents(self):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d) / 'DEMO-private.json'
            p.write_text('{"MAPA_WHATSAPP_ENABLED":"false","MAPA_WHATSAPP_ENABLED":"true"}')
            with self.assertRaises(ValueError): module.read_object(p)

    def test_malformed_private_file_no_contents_or_path_leak(self):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d) / 'SECRET-demo-name.json'
            p.write_text('SECRET-invalid-json')
            r = subprocess.run([sys.executable, '-B', str(SCRIPT), '--config-json', str(p)], capture_output=True, text=True)
            self.assertEqual(r.returncode, 2)
            self.assertNotIn('SECRET', r.stdout + r.stderr)
            self.assertFalse(json.loads(r.stdout)['live_ready'])

    def test_oversized_private_file(self):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d) / 'demo.json'
            p.write_text(' ' * 32769)
            with self.assertRaises(ValueError):
                module.read_object(p)


if __name__ == '__main__':
    unittest.main()

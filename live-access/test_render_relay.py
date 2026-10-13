"""Exercise the renderer only in temporary copies, never the deployed Pages output."""
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

HERE = Path(__file__).resolve().parent


class RelayUpstreamBoundary(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        shutil.copyfile(HERE / 'render-relay.py', self.root / 'render-relay.py')
        shutil.copyfile(HERE / 'relay.mjs', self.root / 'relay.mjs')

    def tearDown(self):
        self.temp.cleanup()

    def render(self, origin):
        return subprocess.run(
            [sys.executable, str(self.root / 'render-relay.py'), '--upstream', origin],
            capture_output=True, text=True, check=False,
        )

    def assert_rendered(self, origin):
        result = self.render(origin)
        self.assertEqual(0, result.returncode, result.stderr)
        expected = (HERE / 'relay.mjs').read_text(encoding='utf-8').replace('__MAPA_UPSTREAM__', origin)
        self.assertEqual(expected, (self.root / 'pages/_worker.js').read_text(encoding='utf-8'))
        self.assertEqual({'version': 1, 'include': ['/*'], 'exclude': []},
                         json.loads((self.root / 'pages/_routes.json').read_text()))

    def test_exact_permanent_origin_preserves_relay_security_and_routes(self):
        self.assert_rendered('https://app.itda.asrhealth.in')

    def test_existing_connector_origin_still_supported(self):
        self.assert_rendered('https://reviewed-connector-123.trycloudflare.com')

    def test_other_hosts_are_rejected_without_output(self):
        for origin in ['https://asrhealth.in', 'https://app.asrhealth.in',
                       'https://mapa.asrhealth.in', 'https://other.itda.asrhealth.in',
                       'https://app.itda.asrhealth.in.attacker.example',
                       'https://app.itda.asrhealth.in@attacker.example',
                       'https://attacker.example@app.itda.asrhealth.in',
                       'https://trycloudflare.com', 'https://nested.host.trycloudflare.com']:
            with self.subTest(origin=origin):
                self.assertNotEqual(0, self.render(origin).returncode)
                self.assertFalse((self.root / 'pages').exists())

    def test_non_origin_inputs_rejected_without_overwriting_existing_output(self):
        pages = self.root / 'pages'
        pages.mkdir()
        (pages / '_worker.js').write_text('previous reviewed deployment')
        for origin in ['http://app.itda.asrhealth.in', 'https://app.itda.asrhealth.in/',
                       'https://app.itda.asrhealth.in:443', 'https://app.itda.asrhealth.in?x=1',
                       'https://app.itda.asrhealth.in#fragment', 'https://app.itda.asrhealth.in\n',
                       'https://app.itda.asrhealth.in/../private', 'https://127.0.0.1',
                       'http://reviewed.trycloudflare.com', 'https://reviewed.trycloudflare.com/path']:
            with self.subTest(origin=origin):
                self.assertNotEqual(0, self.render(origin).returncode)
                self.assertEqual('previous reviewed deployment', (pages / '_worker.js').read_text())
                self.assertFalse((pages / '_routes.json').exists())

    def test_unexpected_template_refused(self):
        (self.root / 'relay.mjs').write_text('__MAPA_UPSTREAM__ __MAPA_UPSTREAM__')
        result = self.render('https://app.itda.asrhealth.in')
        self.assertNotEqual(0, result.returncode)
        self.assertIn('Unexpected relay source', result.stderr)
        self.assertFalse((self.root / 'pages').exists())


if __name__ == '__main__':
    unittest.main()

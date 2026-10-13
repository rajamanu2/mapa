"""Synthetic tests only; no network, real provider data or operational writes."""
import argparse
import copy
import gzip
import json
import os
import tempfile
import unittest
import urllib.error
from datetime import datetime, timezone
from pathlib import Path
from unittest.mock import patch

import way4track as w

IMEI = '000000000000001'
TOKEN = 'a' * 64
NOW = int(datetime(2026, 10, 7, 14, 47, 23, tzinfo=timezone.utc).timestamp() * 1000)


def fixture(offset=0):
    stamp = datetime.fromtimestamp((NOW + offset) / 1000, timezone.utc)
    return dict(imei=IMEI, latlng={'lat': '18.3', 'lng': '82.8'}, gps='On',
                is_gps_device_connected=True, device_timezone_name='UTC',
                actual_time=stamp.strftime('%d-%m-%Y %I:%M:%S %p'), time_inMillis=NOW,
                status='Running', name='DEMO synthetic tracker', meta={'mapa_demo': True})


class AdapterTests(unittest.TestCase):
    def setUp(self):
        self.cfg = {'devices': {IMEI: TOKEN}}
        self.state = {'version': 1, 'devices': {}}
        self.sent, self.saved = [], []

    def execute(self, rows, dry=False, sender=None):
        return w.poll(rows, self.cfg, self.state, sender or (lambda token, fix: self.sent.append((token, fix))),
                      lambda state: self.saved.append(copy.deepcopy(state)), NOW, dry)

    def test_minimal_contract(self):
        fix = w.translate(fixture(), self.cfg['devices'], NOW)
        self.assertEqual(fix, dict(source_protocol='way4track', external_id=IMEI,
            latitude=18.3, longitude=82.8, accuracy_m=None, recorded_at=NOW,
            provider_received_at=NOW, position_quality='provider_reported'))

    def test_old_fix_never_refreshed_by_receipt(self):
        for offset in (-301000, 31000):
            with self.subTest(offset=offset), self.assertRaisesRegex(w.Reject, 'stale_or_future_fix'):
                w.translate(fixture(offset), self.cfg['devices'], NOW)

    def test_boundaries_and_stationary_status(self):
        for offset in (-300000, 30000):
            row = fixture(offset)
            row['status'] = 'Stopped'
            self.assertEqual(w.translate(row, self.cfg['devices'], NOW)['recorded_at'], NOW + offset)

    def test_quality_and_time_rejections(self):
        cases = [('gps', 'Off'), ('gps', 'on'), ('is_gps_device_connected', False),
                 ('is_gps_device_connected', 1), ('is_gps_device_connected', 'true'),
                 ('device_timezone_name', 'Asia/Kolkata'), ('actual_time', 'bad'),
                 ('time_inMillis', True), ('time_inMillis', str(NOW) + '.0'),
                 ('time_inMillis', '+' + str(NOW)), ('time_inMillis', ' ' + str(NOW)),
                 ('time_inMillis', NOW - 31000), ('time_inMillis', NOW + 31000),
                 ('imei', '000000000000002'), ('imei', 1)]
        for key, value in cases:
            with self.subTest(key=key, value=value), self.assertRaises(w.Reject):
                row = fixture()
                row[key] = value
                w.translate(row, self.cfg['devices'], NOW)

    def test_observed_receipt_digits_string_normalized(self):
        row = fixture()
        row['time_inMillis'] = str(NOW)
        fix = w.translate(row, self.cfg['devices'], NOW)
        self.assertEqual(fix['provider_received_at'], NOW)
        self.assertIs(type(fix['provider_received_at']), int)

    def test_bad_coordinate_values(self):
        for value in (True, None, [], 'NaN', float('inf'), 91):
            with self.subTest(value=value), self.assertRaises(w.Reject):
                row = fixture()
                row['latlng']['lat'] = value
                w.translate(row, self.cfg['devices'], NOW)
        row = fixture()
        row['latlng'] = {'lat': 0, 'lng': 0}
        with self.assertRaises(w.Reject):
            w.translate(row, self.cfg['devices'], NOW)

    def test_duplicate_restart_later_receipt_no_history(self):
        self.assertEqual(self.execute([fixture()])['counts']['forwarded'], 1)
        self.state = json.loads(json.dumps(self.saved[0]))
        row = fixture()
        row['time_inMillis'] += 2000
        self.assertEqual(self.execute([row])['counts']['duplicates'], 1)
        self.assertEqual(len(self.sent), 1)

    def test_old_or_conflicting_same_timestamp(self):
        self.execute([fixture()])
        row = fixture()
        row['latlng']['lng'] = 82.9
        for attempt in (row, fixture(-1000)):
            self.assertEqual(self.execute([attempt])['reasons']['old_or_conflicting_fix'], 1)
        self.assertEqual(len(self.sent), 1)

    def test_new_stationary_fix_allowed(self):
        self.execute([fixture(-1000)])
        self.execute([fixture()])
        self.assertEqual(len(self.sent), 2)

    def test_failure_does_not_checkpoint_and_retry_works(self):
        def failed(*args):
            raise w.Reject('mapa_unavailable')
        result = self.execute([fixture()], sender=failed)
        self.assertEqual(result['counts']['forwarded'], 0)
        self.assertEqual(self.state['devices'], {})
        self.assertEqual(self.saved, [])
        self.assertEqual(self.execute([fixture()])['counts']['forwarded'], 1)

    def test_duplicate_imei_in_one_response_sends_neither(self):
        result = self.execute([fixture(), fixture(-1000)])
        self.assertEqual(result['reasons']['duplicate_device_in_response'], 2)
        self.assertEqual(self.sent, [])

    def test_partial_failure_preserves_only_successful_device(self):
        second = '000000000000002'
        self.cfg['devices'][second] = 'b' * 64
        row = fixture()
        row['imei'] = second
        def sender(token, fix):
            if token == TOKEN:
                raise w.Reject('mapa_device_auth_rejected')
            self.sent.append(fix)
        result = self.execute([fixture(), row], sender=sender)
        self.assertEqual(result['counts']['forwarded'], 1)
        self.assertEqual(result['reasons'], {'mapa_device_auth_rejected': 1})
        self.assertEqual(set(self.state['devices']), {second})
        self.assertEqual(set(self.saved[-1]['devices']), {second})

    def test_checkpoint_failure_never_changes_memory(self):
        def failed(state):
            raise OSError('DEMO path')
        with self.assertRaises(OSError):
            w.poll([fixture()], self.cfg, self.state, lambda *_: None, failed, NOW)
        self.assertEqual(self.state['devices'], {})

    def test_dry_run_sends_nothing_and_retains_checkpoint(self):
        result = self.execute([fixture()], dry=True)
        self.assertEqual(result['counts']['eligible'], 1)
        self.assertEqual(self.sent, [])
        self.assertEqual(self.saved, [])
        self.assertEqual(self.state['devices'], {})

    def test_json_and_gzip_envelope(self):
        raw = json.dumps({'root': [[fixture()], [{'count': 1}]]}).encode()
        self.assertEqual(w.decode_records(raw), [fixture()])
        self.assertEqual(w.decode_records(gzip.compress(raw)), [fixture()])

    def test_double_gzip_http_and_application_layers(self):
        raw = json.dumps({'root': [[fixture()], [{'count': 1}]]}).encode()
        self.assertEqual(w.decode_records(gzip.compress(gzip.compress(raw))), [fixture()])

    def test_third_compression_layer_rejected(self):
        raw = json.dumps({'root': [[fixture()]]}).encode()
        with self.assertRaisesRegex(w.Reject, 'excessive_provider_compression'):
            w.decode_records(gzip.compress(gzip.compress(gzip.compress(raw))))

    def test_double_gzip_expansion_bounded_at_each_layer(self):
        # Outer layer expands past the bound while beginning with another gzip header.
        with self.assertRaisesRegex(w.Reject, 'provider_body_too_large'):
            w.decode_records(gzip.compress(b'\x1f\x8b' + b'x' * w.JSON_LIMIT))
        # Small outer and inner compressed bodies must not hide an oversized JSON body.
        with self.assertRaisesRegex(w.Reject, 'provider_body_too_large'):
            w.decode_records(gzip.compress(gzip.compress(b'x' * (w.JSON_LIMIT + 1))))

    def test_provider_bad_payloads_bounded_and_login_fail_closed(self):
        for raw in (b'{}', b'[]', b'[[', b'[{}]', b'{"root":{}}', b'[[{}]]', gzip.compress(b'x' * (w.JSON_LIMIT + 1)), b'x' * (w.WIRE_LIMIT + 1)):
            with self.subTest(size=len(raw)), self.assertRaises(w.Reject):
                w.decode_records(raw)
        with self.assertRaises(w.AuthExpired):
            w.decode_records(b' <!DOCTYPE html><title>Login</title>')

    def test_redirect_handler_does_not_follow(self):
        self.assertIsNone(w.NoRedirect().redirect_request(None, None, 302, 'Found', {}, 'https://other.invalid'))

    def test_transport_fixed_endpoint_cookie_separation(self):
        captured = []
        class Response:
            status = 200
            def __enter__(self): return self
            def __exit__(self, *args): pass
            def read(self, limit):
                return json.dumps({'root': [[fixture()]]} if len(captured) == 1 else {'ok': True}).encode()
        class Opener:
            def open(self, req, timeout):
                captured.append(req)
                return Response()
        transport = w.Transport()
        transport.opener = Opener()
        self.assertEqual(transport.fetch('DEMO_SESSION=private'), [fixture()])
        transport.send(TOKEN, w.translate(fixture(), self.cfg['devices'], NOW))
        self.assertEqual(captured[0].full_url, w.PROVIDER_URL)
        self.assertEqual(captured[1].full_url, w.INGEST_URL)
        self.assertEqual(captured[0].get_header('Cookie'), 'DEMO_SESSION=private')
        self.assertIsNone(captured[0].get_header('Authorization'))
        self.assertIsNone(captured[1].get_header('Cookie'))
        self.assertEqual(captured[1].get_header('Authorization'), 'Bearer ' + TOKEN)
        self.assertEqual(dict(w.urllib.parse.parse_qsl(captured[0].data.decode(), keep_blank_values=True)), w.FORM)

    def test_provider_http_auth_redirects_stop(self):
        transport = w.Transport()
        for code in (301, 302, 303, 307, 308, 401, 403):
            with self.subTest(code=code), patch.object(transport.opener, 'open', side_effect=urllib.error.HTTPError(w.PROVIDER_URL, code, 'DEMO', {}, None)), self.assertRaises(w.AuthExpired):
                transport.fetch('DEMO=private')


class FilesAndLoopTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.cookie = self.root / 'cookie'
        self.cookie.write_text('DEMO_SESSION=private', encoding='ascii')
        self.cookie.chmod(0o600)
        self.config = self.root / 'config.json'
        self.cfg = dict(cookie_file='cookie', devices={IMEI: TOKEN})
        self.write_config()
        self.args = argparse.Namespace(config=str(self.config), state=str(self.root / 'state.json'),
            status=str(self.root / 'status.json'), once=True, dry_run=False)

    def tearDown(self):
        self.temp.cleanup()

    def write_config(self):
        self.config.write_text(json.dumps(self.cfg), encoding='utf-8')
        self.config.chmod(0o600)

    def test_config_fixed_domain_tokens_and_cookie_validation(self):
        self.assertEqual(w.load_config(self.config)['devices'], {IMEI: TOKEN})
        self.cfg['ingest_url'] = 'https://other.invalid/mapa/mobile/v1/tracking/ingest'
        self.write_config()
        with self.assertRaisesRegex(w.Reject, 'invalid_ingest_url'):
            w.load_config(self.config)
        del self.cfg['ingest_url']
        self.cfg['devices']['000000000000002'] = TOKEN
        self.write_config()
        with self.assertRaisesRegex(w.Reject, 'device_tokens_must_differ'):
            w.load_config(self.config)
        self.cookie.write_bytes(b'DEMO=secret\r\nX-Injected: bad')
        with self.assertRaisesRegex(w.Reject, 'invalid_cookie'):
            w.cookie_value(self.cookie)

    def test_state_invalid_does_not_reset(self):
        path = Path(self.args.state)
        path.write_text('{broken', encoding='utf-8')
        path.chmod(0o600)
        with self.assertRaisesRegex(w.Reject, 'invalid_state'):
            w.load_state(path, self.cfg['devices'])

    def test_atomic_write_and_existing_temp_not_deleted(self):
        target = self.root / 'output.json'
        w.atomic_json(target, {'version': 1})
        self.assertEqual(json.loads(target.read_text()), {'version': 1})
        temporary = self.root / 'output.json.tmp'
        temporary.write_text('DEMO preexisting', encoding='ascii')
        with self.assertRaises(FileExistsError):
            w.atomic_json(target, {'version': 2})
        self.assertEqual(temporary.read_text(), 'DEMO preexisting')

    def test_auth_stops_sanitized_status(self):
        class Transport:
            def fetch(self, cookie): raise w.AuthExpired('provider_auth_expired')
        self.assertEqual(w.run(self.args, Transport(), clock=lambda: NOW / 1000), 2)
        status = Path(self.args.status).read_text()
        self.assertEqual(json.loads(status)['state'], 'auth_expired')
        for secret in ('DEMO_SESSION', TOKEN, IMEI, '18.3', '82.8'):
            self.assertNotIn(secret, status)
        self.assertFalse(Path(self.args.state).exists())

    def test_loop_dry_run_then_real_then_duplicate(self):
        sent = []
        class Transport:
            def fetch(self, cookie): return [fixture()]
            def send(self, token, fix): sent.append(fix)
        self.args.dry_run = True
        self.assertEqual(w.run(self.args, Transport(), clock=lambda: NOW / 1000), 0)
        self.assertFalse(Path(self.args.state).exists())
        self.assertEqual(sent, [])
        self.args.dry_run = False
        for _ in range(2):
            self.assertEqual(w.run(self.args, Transport(), clock=lambda: NOW / 1000), 0)
        self.assertEqual(len(sent), 1)
        self.assertEqual(json.loads(Path(self.args.status).read_text())['counts']['duplicates'], 1)

    def test_failure_backoff_bounded_and_auth_stops(self):
        delays = []
        class Transport:
            calls = 0
            def fetch(self, cookie):
                self.calls += 1
                if self.calls == 8: raise w.AuthExpired('provider_auth_expired')
                raise w.Reject('provider_unavailable')
        self.args.once = False
        self.assertEqual(w.run(self.args, Transport(), clock=lambda: NOW / 1000, sleep=delays.append), 2)
        self.assertEqual(delays, [20, 40, 80, 160, 300, 300, 300])

    def test_private_files_not_overwritten_by_status(self):
        self.args.status = str(self.cookie)
        with self.assertRaisesRegex(w.Reject, 'paths_must_differ'):
            w.run(self.args)
        self.assertEqual(self.cookie.read_text(), 'DEMO_SESSION=private')

    def test_singleton_lock_is_released(self):
        path = self.root / 'singleton.lock'
        with w.ProcessLock(path):
            with self.assertRaisesRegex(w.Reject, 'poller_already_running'):
                with w.ProcessLock(path):
                    self.fail('Concurrent writer admitted')
        with w.ProcessLock(path):
            pass

    def test_degraded_ingest_status_and_no_state_checkpoint(self):
        class Transport:
            def fetch(self, cookie): return [fixture()]
            def send(self, token, fix): raise w.Reject('mapa_device_auth_rejected')
        self.assertEqual(w.run(self.args, Transport(), clock=lambda: NOW / 1000), 1)
        status = json.loads(Path(self.args.status).read_text())
        self.assertEqual(status['state'], 'degraded')
        self.assertEqual(status['reasons'], {'mapa_device_auth_rejected': 1})
        self.assertFalse(Path(self.args.state).exists())

    def snapshot_args(self):
        self.cfg['company_id'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'
        self.write_config()
        self.args.snapshot = str(self.root / 'provider-snapshot.json')

    def test_snapshot_dry_run_private_output_no_ingest_or_checkpoint(self):
        self.snapshot_args()
        self.args.dry_run = True
        row = fixture(-600000)
        row['gps'] = 'Off'
        row['is_gps_device_connected'] = False
        class Transport:
            def fetch(self, cookie): return [row]
            def send(self, *args): raise AssertionError('Dry run sent data')
        self.assertEqual(w.run(self.args, Transport(), clock=lambda: NOW / 1000), 0)
        snapshot = json.loads(Path(self.args.snapshot).read_text())
        self.assertEqual(snapshot['devices'][0]['freshness'], 'stale')
        self.assertFalse(snapshot['devices'][0]['connected'])
        self.assertFalse(Path(self.args.state).exists())
        if os.name != 'nt':
            self.assertEqual(Path(self.args.snapshot).stat().st_mode & 0o777, 0o600)

    def test_snapshot_cannot_overwrite_private_config(self):
        self.snapshot_args()
        self.args.snapshot = self.args.config
        with self.assertRaisesRegex(w.Reject, 'paths_must_differ'):
            w.run(self.args)

    def test_snapshot_requires_validated_tenant(self):
        self.args.snapshot = str(self.root / 'snapshot.json')
        with self.assertRaisesRegex(w.Reject, 'snapshot_company_required'):
            w.run(self.args)
        self.cfg['company_id'] = 'not-a-tenant'
        self.write_config()
        with self.assertRaisesRegex(w.Reject, 'invalid_company_id'):
            w.load_config(self.config)

    def test_snapshot_write_failure_does_not_undo_successful_ingestion(self):
        self.snapshot_args()
        # An absent parent fails only the optional snapshot output.
        self.args.snapshot = str(self.root / 'missing' / 'snapshot.json')
        sent = []
        class Transport:
            def fetch(self, cookie): return [fixture()]
            def send(self, token, fix): sent.append(fix)
        self.assertEqual(w.run(self.args, Transport(), clock=lambda: NOW / 1000), 1)
        self.assertEqual(len(sent), 1)
        self.assertTrue(Path(self.args.state).exists())
        status = json.loads(Path(self.args.status).read_text())
        self.assertEqual(status['snapshot_state'], 'write_failed')
        self.assertEqual(status['state'], 'degraded')

    def test_auth_expiry_never_freshens_snapshot(self):
        self.snapshot_args()
        Path(self.args.snapshot).write_text('DEMO unchanged previous snapshot', encoding='ascii')
        class Transport:
            def fetch(self, cookie): raise w.AuthExpired('provider_auth_expired')
        self.assertEqual(w.run(self.args, Transport(), clock=lambda: NOW / 1000), 2)
        self.assertEqual(Path(self.args.snapshot).read_text(), 'DEMO unchanged previous snapshot')


class LastKnownSnapshotTests(unittest.TestCase):
    def setUp(self):
        self.cfg = {'company_id': 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'devices': {IMEI: TOKEN}}

    def view(self, rows):
        return w.last_known_snapshot(rows, self.cfg, NOW)

    def test_offline_stale_unmapped_rows_visible_without_live_eligibility(self):
        row = fixture(-86400000)
        row.update(gps='Off', is_gps_device_connected=False, imei='000000000000002')
        view = self.view([row])
        self.assertEqual(view['contract'], 'mapa-way4track-last-known-v1')
        self.assertEqual(view['generated_at'], NOW)
        self.assertEqual(view['company_id'], self.cfg['company_id'])
        device = view['devices'][0]
        self.assertFalse(device['mapped'])
        self.assertFalse(device['connected'])
        self.assertFalse(device['gps_on'])
        self.assertEqual(device['freshness'], 'stale')
        self.assertEqual(device['latitude'], 18.3)
        self.assertEqual(device['recorded_at'], NOW - 86400000)
        self.assertEqual(device['position_quality'], 'provider_reported')
        with self.assertRaisesRegex(w.Reject, 'unregistered_device'):
            w.translate(row, self.cfg['devices'], NOW)
        row['imei'] = IMEI
        with self.assertRaisesRegex(w.Reject, 'gps_off_or_disconnected'):
            w.translate(row, self.cfg['devices'], NOW)

    def test_invalid_coordinates_keep_device_with_null_position(self):
        for coords in ({'lat': True, 'lng': 82}, {'lat': 0, 'lng': 0}, {'lat': 'NaN', 'lng': 1}, {'lat': 91, 'lng': 181}, None):
            with self.subTest(coords=coords):
                row = fixture()
                row['latlng'] = coords
                device = self.view([row])['devices'][0]
                self.assertIsNone(device['latitude'])
                self.assertIsNone(device['longitude'])
                self.assertEqual(device['external_id'], IMEI)

    def test_unknown_flags_and_invalid_timezone_never_invented(self):
        row = fixture()
        row.update(gps='possibly', is_gps_device_connected='true', device_timezone_name='Asia/Kolkata')
        device = self.view([row])['devices'][0]
        for key in ('gps_on', 'connected', 'recorded_at'):
            self.assertIsNone(device[key])
        self.assertEqual(device['freshness'], 'unknown')

    def test_future_fix_and_invalid_receipt_remain_explicit(self):
        row = fixture(3600000)
        row['time_inMillis'] = NOW + 3600000
        device = self.view([row])['devices'][0]
        self.assertEqual(device['freshness'], 'future')
        self.assertIsNone(device['provider_received_at'])
        with self.assertRaisesRegex(w.Reject, 'stale_or_future_fix'):
            w.translate(row, self.cfg['devices'], NOW)

    def test_snapshot_has_only_allowlisted_non_auth_fields(self):
        row = fixture()
        row.update(cookie='DEMO_SECRET', password='DEMO_SECRET', authorization=TOKEN,
                   driver_phone='DEMO_PRIVATE', session={'token': 'DEMO_SECRET'}, name='DEMO_SECRET')
        row['latlng']['private'] = 'DEMO_SECRET'
        view = self.view([row])
        self.assertEqual(set(view), {'contract', 'company_id', 'generated_at', 'devices'})
        self.assertEqual(set(view['devices'][0]), {'external_id', 'latitude', 'longitude', 'recorded_at',
            'provider_received_at', 'gps_on', 'connected', 'position_quality', 'freshness', 'mapped',
            'speed_kmh', 'heading_degrees', 'address', 'provider_status', 'ignition',
            'external_power_v', 'battery_percent', 'gsm_percent'})
        serialized = json.dumps(view)
        self.assertNotIn('DEMO_SECRET', serialized)
        self.assertNotIn(TOKEN, serialized)
        self.assertNotIn('DEMO_PRIVATE', serialized)

    def test_display_telemetry_is_bounded_and_never_enables_commands(self):
        row = fixture()
        row.update(loc='Haritha Hill Resort', status='Stopped', ign='Off',
                   external_power=12.73, btr='100', gsm='75',
                   immobilize='On', speed='0', angle='90')
        device = self.view([row])['devices'][0]
        self.assertEqual((device['address'], device['provider_status'], device['ignition']),
                         ('Haritha Hill Resort', 'Stopped', 'Off'))
        self.assertEqual((device['external_power_v'], device['battery_percent'], device['gsm_percent']),
                         (12.73, 100.0, 75.0))
        self.assertNotIn('immobilize', device)
        row.update(status='unknown', ign='maybe', external_power=-5, btr=120, gsm='NA')
        device = self.view([row])['devices'][0]
        self.assertIsNone(device['provider_status'])
        self.assertIsNone(device['ignition'])
        self.assertIsNone(device['external_power_v'])
        self.assertIsNone(device['battery_percent'])
        self.assertIsNone(device['gsm_percent'])

    def test_invalid_or_duplicate_identity_never_merges_devices(self):
        bad = fixture()
        bad['imei'] = 'invalid'
        self.assertEqual(self.view([fixture(), fixture(), bad, None])['devices'], [])

    def test_snapshot_is_bounded(self):
        with self.assertRaisesRegex(w.Reject, 'invalid_provider_envelope'):
            self.view([fixture()] * (w.MAX_DEVICES + 1))


if __name__ == '__main__':
    unittest.main(verbosity=2)

"""Private, read-only Way4Track poller. Never creates vehicles or assignments.

Run: python way4track.py --config /private/way4track.json --state /private/state.json
     --status /private/status.json [--once] [--dry-run] [--snapshot /private/last-known.json]
Config: {"cookie_file":"session.cookie", "devices":{"<IMEI>":"<device token>"},
         "company_id":"<tenant UUID, required only for snapshot output>"}
All files belong outside web roots; use a dedicated OS account/private ACL on Windows.
On POSIX config/cookie/state files must have no group/other access. Cookie refresh is
manual; authentication failure stops the process. A supervisor must not repeatedly
restart an auth-expired poller. Dry run contacts the provider, but never MAPA or state.
"""
import argparse
import gzip
import hashlib
import io
import json
import math
import os
import re
import stat
import time
import urllib.error
import urllib.parse
import urllib.request
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

PROVIDER_URL = 'https://way4trackonline.com/GenerateJSON?method=getTrakzeeLiveTrackingAjaxJSON'
INGEST_URL = 'https://app.itda.asrhealth.in/mapa/mobile/v1/tracking/ingest'
FORM = dict(javaclassmethodname='getTrakzeeLiveTrackingAjaxJSON',
            javaclassname='com.uffizio.tools.projectmanager.GenerateJSONAjax',
            sSelectedVehicle='', sSelectedImeiNo='', sourceJsp='LiveTracking',
            lite='true', allObjectFlag='false', refreshCall='true', gzip='true')
WIRE_LIMIT = 2 * 1024 * 1024
JSON_LIMIT = 8 * 1024 * 1024
MAX_DEVICES = 1000


class Reject(ValueError):
    """Only fixed, non-sensitive codes may be raised or logged."""


class AuthExpired(Reject):
    pass


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def private_read(path, limit):
    path = Path(path)
    if path.is_symlink() or not path.is_file():
        raise Reject('private_file_invalid')
    if os.name != 'nt' and stat.S_IMODE(path.stat().st_mode) & 0o077:
        raise Reject('private_file_permissions')
    with path.open('rb') as stream:
        data = stream.read(limit + 1)
    if len(data) > limit:
        raise Reject('private_file_too_large')
    return data


def cookie_value(path):
    try:
        value = private_read(path, 16384).decode('ascii').strip()
    except UnicodeError:
        raise Reject('invalid_cookie') from None
    if not value or any(ord(c) < 32 or ord(c) == 127 for c in value) or '=' not in value:
        raise Reject('invalid_cookie')
    return value


def load_config(path):
    try:
        cfg = json.loads(private_read(path, 256 * 1024))
    except (ValueError, UnicodeError):
        raise Reject('invalid_config') from None
    if not isinstance(cfg, dict) or set(cfg) - {'cookie_file', 'devices', 'ingest_url', 'company_id'}:
        raise Reject('invalid_config')
    company = cfg.get('company_id')
    if company is not None:
        if not isinstance(company, str) or not re.fullmatch(r'[a-fA-F0-9]{8}(?:-[a-fA-F0-9]{4}){3}-[a-fA-F0-9]{12}', company):
            raise Reject('invalid_company_id')
        cfg['company_id'] = company.lower()
    if cfg.get('ingest_url', INGEST_URL) != INGEST_URL:
        raise Reject('invalid_ingest_url')
    devices = cfg.get('devices')
    if not isinstance(devices, dict) or not 1 <= len(devices) <= MAX_DEVICES:
        raise Reject('invalid_devices')
    if any(not re.fullmatch(r'[0-9]{15}', k) or not isinstance(v, str)
           or not re.fullmatch(r'[a-f0-9]{64}', v) for k, v in devices.items()):
        raise Reject('invalid_devices')
    if len(set(devices.values())) != len(devices):
        raise Reject('device_tokens_must_differ')
    cookie = cfg.get('cookie_file')
    if not isinstance(cookie, str) or not cookie:
        raise Reject('invalid_cookie_file')
    cfg['cookie_file'] = str((Path(path).resolve().parent / cookie).resolve())
    cookie_value(cfg['cookie_file'])
    return cfg


def numeric(value):
    if isinstance(value, bool) or not isinstance(value, (str, int, float)):
        raise Reject('invalid_coordinates')
    try:
        number = float(value)
    except (ValueError, OverflowError):
        raise Reject('invalid_coordinates') from None
    if not math.isfinite(number):
        raise Reject('invalid_coordinates')
    return number


def translate(row, devices, now_ms):
    if not isinstance(row, dict):
        raise Reject('invalid_record')
    imei = row.get('imei')
    if not isinstance(imei, str) or imei not in devices:
        raise Reject('unregistered_device')
    if row.get('gps') != 'On' or row.get('is_gps_device_connected') is not True:
        raise Reject('gps_off_or_disconnected')
    if row.get('device_timezone_name') != 'UTC':
        raise Reject('unverified_timezone')
    try:
        stamp = datetime.strptime(row['actual_time'], '%d-%m-%Y %I:%M:%S %p').replace(tzinfo=timezone.utc)
        recorded = int(stamp.timestamp() * 1000)
    except (KeyError, TypeError, ValueError, OverflowError):
        raise Reject('invalid_time') from None
    if not now_ms - 300000 <= recorded <= now_ms + 30000:
        raise Reject('stale_or_future_fix')
    receipt = row.get('time_inMillis')
    if isinstance(receipt, str) and re.fullmatch(r'[1-9][0-9]{12}', receipt):
        receipt = int(receipt)  # Observed provider JSON encodes epoch milliseconds as text.
    if type(receipt) is not int or not recorded - 30000 <= receipt <= now_ms + 30000:
        raise Reject('invalid_provider_receipt')
    coords = row.get('latlng')
    if not isinstance(coords, dict):
        raise Reject('invalid_coordinates')
    lat, lng = numeric(coords.get('lat')), numeric(coords.get('lng'))
    if not -90 <= lat <= 90 or not -180 <= lng <= 180 or (lat == 0 and lng == 0):
        raise Reject('invalid_coordinates')
    return dict(source_protocol='way4track', external_id=imei, latitude=lat,
                longitude=lng, accuracy_m=None, recorded_at=recorded,
                provider_received_at=receipt, position_quality='provider_reported')


def decode_records(raw):
    if len(raw) > WIRE_LIMIT:
        raise Reject('provider_body_too_large')
    try:
        # urllib preserves HTTP gzip; the provider additionally gzips its JSON.
        # Browser tooling may remove the outer layer, so accept zero to two only.
        for _ in range(2):
            if not raw.startswith(b'\x1f\x8b'):
                break
            with gzip.GzipFile(fileobj=io.BytesIO(raw)) as stream:
                raw = stream.read(JSON_LIMIT + 1)
            if len(raw) > JSON_LIMIT:
                raise Reject('provider_body_too_large')
        if raw.startswith(b'\x1f\x8b'):
            raise Reject('excessive_provider_compression')
        if raw.lstrip().startswith(b'<'):
            raise AuthExpired('provider_auth_expired')
        data = json.loads(raw)
    except (OSError, EOFError, UnicodeError, ValueError) as error:
        if isinstance(error, Reject):
            raise
        raise Reject('invalid_provider_json') from None
    root = data.get('root') if isinstance(data, dict) else None
    if not isinstance(root, list) or not root or not isinstance(root[0], list) or len(root[0]) > MAX_DEVICES:
        raise Reject('invalid_provider_envelope')
    return root[0]


def last_known_snapshot(records, cfg, now_ms):
    """Display-only projection; never calls translate or changes live eligibility.

    Keep offline/stale devices and null invalid fields. Ignore all unallowlisted
    provider attributes, including any cookie, password, phone or account fields.
    Backend must recheck tenant mapping, snapshot age and fix freshness at read.
    """
    if not isinstance(cfg.get('company_id'), str) or not re.fullmatch(r'[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}', cfg['company_id']):
        raise Reject('snapshot_company_required')
    if not isinstance(records, list) or len(records) > MAX_DEVICES:
        raise Reject('invalid_provider_envelope')
    occurrences = Counter(row.get('imei') for row in records if isinstance(row, dict) and isinstance(row.get('imei'), str))
    result = []
    for row in records:
        if not isinstance(row, dict):
            continue
        imei = row.get('imei')
        if not isinstance(imei, str) or not re.fullmatch(r'[0-9]{15}', imei) or occurrences[imei] != 1:
            continue
        latitude = longitude = None
        try:
            coords = row.get('latlng')
            if not isinstance(coords, dict):
                raise Reject('invalid_coordinates')
            lat, lng = numeric(coords.get('lat')), numeric(coords.get('lng'))
            if not -90 <= lat <= 90 or not -180 <= lng <= 180 or (lat == 0 and lng == 0):
                raise Reject('invalid_coordinates')
            latitude, longitude = lat, lng
        except Reject:
            pass
        recorded = None
        try:
            if row.get('device_timezone_name') == 'UTC':
                stamp = datetime.strptime(row['actual_time'], '%d-%m-%Y %I:%M:%S %p').replace(tzinfo=timezone.utc)
                milliseconds = int(stamp.timestamp() * 1000)
                if milliseconds > 0:
                    recorded = milliseconds
        except (KeyError, TypeError, ValueError, OverflowError):
            pass
        receipt = row.get('time_inMillis')
        if isinstance(receipt, str) and re.fullmatch(r'[1-9][0-9]{12}', receipt):
            receipt = int(receipt)
        if type(receipt) is not int or not 0 < receipt <= now_ms + 30000 or (recorded is not None and receipt < recorded - 30000):
            receipt = None
        freshness = 'unknown' if recorded is None else ('future' if recorded > now_ms + 30000 else ('stale' if recorded < now_ms - 300000 else 'fresh'))
        # Way4Track reports km/h and clockwise degrees. These are display-only
        # values from the same fix, never dispatch or native vehicle evidence.
        def telemetry(key, maximum):
            try:
                value = numeric(row.get(key))
                return value if 0 <= value <= maximum else None
            except Reject:
                return None
        speed = telemetry('speed', 250) if latitude is not None and recorded is not None else None
        heading = telemetry('angle', 360) if latitude is not None and recorded is not None else None
        # Only observed, display-only fields from the live tracking response.
        # Never copy account, SIM, driver contact or remote-command attributes.
        address = row.get('loc')
        address = re.sub(r'[\x00-\x1f\x7f]', ' ', address).strip()[:240] if isinstance(address, str) and address.strip() else None
        status = row.get('status') if row.get('status') in ('Running', 'Idle', 'Stopped', 'InActive') else None
        ignition = row.get('ign') if row.get('ign') in ('On', 'Off') else None
        external_power = telemetry('external_power', 100)
        battery_percent = telemetry('btr', 100)
        gsm_percent = telemetry('gsm', 100)
        result.append({'external_id': imei, 'latitude': latitude, 'longitude': longitude,
                       'recorded_at': recorded, 'provider_received_at': receipt,
                       'speed_kmh': speed, 'heading_degrees': heading,
                       'address': address, 'provider_status': status, 'ignition': ignition,
                       'external_power_v': external_power, 'battery_percent': battery_percent,
                       'gsm_percent': gsm_percent,
                       'gps_on': True if row.get('gps') == 'On' else (False if row.get('gps') == 'Off' else None),
                       'connected': row.get('is_gps_device_connected') if type(row.get('is_gps_device_connected')) is bool else None,
                       'position_quality': 'provider_reported', 'freshness': freshness,
                       'mapped': imei in cfg['devices']})
    return {'contract': 'mapa-way4track-last-known-v1', 'company_id': cfg['company_id'],
            'generated_at': now_ms, 'devices': result}


class Transport:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())

    def fetch(self, cookie):
        request = urllib.request.Request(PROVIDER_URL,
            data=urllib.parse.urlencode(FORM).encode('ascii'), method='POST',
            headers={'Cookie': cookie, 'Content-Type': 'application/x-www-form-urlencoded',
                     'Accept': 'application/json', 'Accept-Encoding': 'gzip'})
        try:
            with self.opener.open(request, timeout=8) as response:
                if response.status != 200:
                    raise Reject('provider_unavailable')
                return decode_records(response.read(WIRE_LIMIT + 1))
        except urllib.error.HTTPError as error:
            if error.code in (301, 302, 303, 307, 308, 401, 403):
                raise AuthExpired('provider_auth_expired') from None
            raise Reject('provider_unavailable') from None
        except (OSError, TimeoutError):
            raise Reject('provider_unavailable') from None

    def send(self, token, fix):
        request = urllib.request.Request(INGEST_URL, method='POST',
            data=json.dumps(fix, allow_nan=False).encode('utf-8'),
            headers={'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json', 'Accept': 'application/json'})
        try:
            with self.opener.open(request, timeout=8) as response:
                raw = response.read(16385)
                if response.status != 200 or len(raw) > 16384 or json.loads(raw).get('ok') is not True:
                    raise Reject('mapa_rejected')
        except urllib.error.HTTPError as error:
            if error.code in (401, 403):
                raise Reject('mapa_device_auth_rejected') from None
            raise Reject('mapa_rejected') from None
        except Reject:
            raise
        except (OSError, ValueError, AttributeError):
            raise Reject('mapa_unavailable') from None


def atomic_json(path, value):
    path = Path(path)
    if path.is_symlink():
        raise Reject('unsafe_output_path')
    temporary = path.with_name(path.name + '.tmp')
    flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL
    owned = False
    try:
        fd = os.open(temporary, flags, 0o600)
        owned = True
        with os.fdopen(fd, 'w', encoding='utf-8') as stream:
            json.dump(value, stream, allow_nan=False, separators=(',', ':'))
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        if owned and temporary.exists() and not temporary.is_symlink():
            temporary.unlink()


def load_state(path, devices):
    if not Path(path).exists():
        return {'version': 1, 'devices': {}}
    try:
        value = json.loads(private_read(path, 256 * 1024))
    except (ValueError, UnicodeError):
        raise Reject('invalid_state') from None
    if not isinstance(value, dict) or value.get('version') != 1 or not isinstance(value.get('devices'), dict) or len(value['devices']) > MAX_DEVICES:
        raise Reject('invalid_state')
    for imei, entry in value['devices'].items():
        if not re.fullmatch(r'[0-9]{15}', imei) or not isinstance(entry, dict) or type(entry.get('recorded_at')) is not int or not isinstance(entry.get('fingerprint'), str) or not re.fullmatch('[a-f0-9]{64}', entry['fingerprint']):
            raise Reject('invalid_state')
    value['devices'] = {k: v for k, v in value['devices'].items() if k in devices}
    return value


def fingerprint(fix):
    stable = {k: v for k, v in fix.items() if k != 'provider_received_at'}
    return hashlib.sha256(json.dumps(stable, sort_keys=True, separators=(',', ':')).encode()).hexdigest()


def poll(records, cfg, state, sender, checkpoint, now_ms, dry_run=False):
    counts = Counter(dict(received=0, eligible=0, forwarded=0, rejected=0, duplicates=0))
    reasons = Counter()
    occurrences = Counter(row.get('imei') for row in records if isinstance(row, dict) and isinstance(row.get('imei'), str))
    for row in records:
        counts['received'] += 1
        try:
            fix = translate(row, cfg['devices'], now_ms)
            imei = fix['external_id']
            if occurrences[imei] > 1:
                raise Reject('duplicate_device_in_response')
            prior = state['devices'].get(imei)
            digest = fingerprint(fix)
            if prior and fix['recorded_at'] <= prior['recorded_at']:
                if fix['recorded_at'] == prior['recorded_at'] and digest == prior['fingerprint']:
                    counts['duplicates'] += 1
                    continue
                raise Reject('old_or_conflicting_fix')
            counts['eligible'] += 1
            if dry_run:
                continue
            sender(cfg['devices'][imei], fix)
            next_state = {'version': 1, 'devices': dict(state['devices'])}
            next_state['devices'][imei] = {'recorded_at': fix['recorded_at'], 'fingerprint': digest}
            checkpoint(next_state)  # Persist only successful receipts, before changing memory.
            state.update(next_state)
            counts['forwarded'] += 1
        except Reject as error:
            counts['rejected'] += 1
            reasons[str(error)] += 1
    return {'counts': dict(counts), 'reasons': dict(reasons), 'dry_run': dry_run}


class ProcessLock:
    def __init__(self, path):
        self.path = Path(path)

    def __enter__(self):
        if self.path.is_symlink():
            raise Reject('unsafe_lock_path')
        self.stream = open(self.path, 'a+b')
        try:
            if os.name == 'nt':
                import msvcrt
                if self.stream.tell() == 0:
                    self.stream.write(b'0')
                    self.stream.flush()
                self.stream.seek(0)
                msvcrt.locking(self.stream.fileno(), msvcrt.LK_NBLCK, 1)
            else:
                import fcntl
                os.chmod(self.path, 0o600)
                fcntl.flock(self.stream, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            self.stream.close()
            raise Reject('poller_already_running') from None
        return self

    def __exit__(self, *args):
        self.stream.close()


def run(args, transport=None, clock=time.time, sleep=time.sleep):
    cfg = load_config(args.config)
    snapshot = getattr(args, 'snapshot', None)
    if snapshot and not cfg.get('company_id'):
        raise Reject('snapshot_company_required')
    outputs = [Path(p).resolve() for p in (args.state, args.status, str(args.state) + '.lock')]
    if snapshot:
        outputs.append(Path(snapshot).resolve())
    if len(set(outputs + [Path(args.config).resolve(), Path(cfg['cookie_file'])])) != len(outputs) + 2:
        raise Reject('paths_must_differ')
    transport = transport or Transport()
    failures = 0
    with ProcessLock(str(args.state) + '.lock'):
        state = load_state(args.state, cfg['devices'])
        while True:
            result = {'checked_at': int(clock() * 1000), 'service': 'MAPA Way4Track', 'dry_run': args.dry_run}
            try:
                records = transport.fetch(cookie_value(cfg['cookie_file']))
                result.update(poll(records, cfg, state, transport.send,
                    lambda value: atomic_json(args.state, value), int(clock() * 1000), args.dry_run))
                degraded = result['reasons'].get('mapa_unavailable', 0) or result['reasons'].get('mapa_rejected', 0) or result['reasons'].get('mapa_device_auth_rejected', 0)
                if snapshot:
                    view = last_known_snapshot(records, cfg, int(clock() * 1000))
                    try:
                        atomic_json(snapshot, view)
                        result.update(snapshot_state='ok', snapshot_rows=len(view['devices']),
                                      snapshot_without_coordinates=sum(row['latitude'] is None for row in view['devices']))
                    except (OSError, Reject):
                        result['snapshot_state'] = 'write_failed'
                        degraded = True
                result['state'] = 'degraded' if degraded else ('no_fresh_positions' if not result['counts']['eligible'] and not result['counts']['duplicates'] else 'ok')
                failures = failures + 1 if degraded else 0
            except AuthExpired:
                result.update(state='auth_expired', reason='provider_auth_expired', needs_action=True)
                atomic_json(args.status, result)
                return 2
            except Reject as error:
                failures += 1
                result.update(state='unavailable', reason=str(error))
            delay = min(300, 10 * 2 ** min(failures, 5)) if failures else 10
            result.update(consecutive_failures=failures, next_poll_seconds=0 if args.once else delay)
            atomic_json(args.status, result)
            if args.once:
                return 1 if failures else 0
            sleep(delay)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('config', 'state', 'status'):
        parser.add_argument('--' + name, required=True)
    parser.add_argument('--once', action='store_true')
    parser.add_argument('--dry-run', action='store_true')
    parser.add_argument('--snapshot', help='Optional private last-known provider snapshot, including stale/offline devices')
    args = parser.parse_args()
    try:
        return run(args)
    except (Reject, OSError, ValueError):
        # Do not print exception text: OS/JSON exceptions may contain private paths/data.
        print(json.dumps({'service': 'MAPA Way4Track', 'state': 'stopped', 'reason': 'private_configuration_or_io_error'}), flush=True)
        return 3
    except KeyboardInterrupt:
        return 0


if __name__ == '__main__':
    raise SystemExit(main())

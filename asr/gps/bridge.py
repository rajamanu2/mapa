"""Private Traccar position forwarding -> MAPA per-device authenticated ingestion.

Never register vehicles, trust payload URLs, or convert missing accuracy to metres.
"""
import hmac
import json
import math
import re
import socket
import threading
import time
import urllib.error
import urllib.request
from datetime import datetime
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlsplit


class Reject(ValueError):
    pass


def number(value):
    if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(value):
        raise Reject('invalid_number')
    return value


def translate(data, devices, now_ms):
    if not isinstance(data, dict) or not isinstance(data.get('device'), dict) or not isinstance(data.get('position'), dict):
        raise Reject('invalid_envelope')
    device, p = data['device'], data['position']
    imei = device.get('uniqueId')
    if not isinstance(imei, str) or imei not in devices:
        raise Reject('unregistered_device')
    if device.get('disabled') is True or type(device.get('id')) is not int or device['id'] <= 0 or type(p.get('deviceId')) is not int or p['deviceId'] != device['id']:
        raise Reject('device_mismatch')
    if p.get('protocol') != 'gt06' or p.get('valid') is not True:
        raise Reject('invalid_gps')
    attributes = p.get('attributes')
    if not isinstance(attributes, dict) or attributes.get('outdated') is True or attributes.get('approximate') is True:
        raise Reject('invalid_gps')
    satellites = number(attributes.get('sat'))
    if satellites != int(satellites) or not 4 <= satellites <= 15:
        raise Reject('insufficient_satellites')
    lat, lon = number(p.get('latitude')), number(p.get('longitude'))
    if not -90 <= lat <= 90 or not -180 <= lon <= 180 or (lat == 0 and lon == 0):
        raise Reject('invalid_coordinates')
    try:
        stamp = datetime.fromisoformat(p['fixTime'].replace('Z', '+00:00'))
        if stamp.tzinfo is None:
            raise ValueError()
        recorded = int(stamp.timestamp() * 1000)
    except (KeyError, TypeError, AttributeError, ValueError, OverflowError):
        raise Reject('invalid_time') from None
    if not now_ms - 300000 <= recorded <= now_ms + 30000:
        raise Reject('stale_or_future')
    accuracy = p.get('accuracy')
    if accuracy is not None:
        accuracy = number(accuracy)
        if accuracy == 0:
            accuracy = None  # Traccar defaults an absent radius to zero; not perfect accuracy.
        elif not 0 < accuracy <= 100:
            raise Reject('inaccurate')
    return devices[imei], dict(latitude=lat, longitude=lon, accuracy_m=accuracy,
        recorded_at=recorded, source_protocol='gt06', external_id=imei,
        gps_valid=True, satellites=int(satellites))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None  # Never forward a device credential to another origin.


def load_config(path):
    cfg = json.loads(Path(path).read_text(encoding='utf-8'))
    if not re.fullmatch(r'[a-f0-9]{64}', cfg.get('forward_secret', '')):
        raise ValueError('A generated private forwarding secret is required')
    devices = cfg.get('devices')
    if not isinstance(devices, dict) or any(not re.fullmatch(r'[0-9]{15}', k) or not re.fullmatch(r'[a-f0-9]{64}', v) for k, v in devices.items()):
        raise ValueError('Devices must map 15-digit IMEIs to private MAPA device credentials')
    if len(set(devices.values())) != len(devices):
        raise ValueError('Each device must use a separate MAPA credential')
    u = urlsplit(cfg.get('ingest_url', ''))
    local_http = u.scheme == 'http' and u.hostname in ('127.0.0.1','localhost','mapa-qa-api','host.docker.internal') and cfg.get('local_test_only') is True
    if (u.scheme != 'https' and not local_http) or not u.hostname or u.username or u.password or u.query or u.fragment or u.path != '/mapa/mobile/v1/tracking/ingest':
        raise ValueError('Use the exact HTTPS MAPA ingestion endpoint (HTTP only for explicit isolated local tests)')
    return cfg


def make_handler(cfg, sender=None):
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())

    def send(token, fix):
        req = urllib.request.Request(cfg['ingest_url'], data=json.dumps(fix, allow_nan=False).encode(),
            headers={'Authorization': 'Bearer '+token, 'Content-Type': 'application/json', 'Accept': 'application/json'}, method='POST')
        try:
            with opener.open(req, timeout=8) as response:
                body = json.loads(response.read(16384))
                return response.status if body.get('ok') is True else 503
        except urllib.error.HTTPError as error:
            return error.code
        except (OSError, ValueError):
            return 503

    class Handler(BaseHTTPRequestHandler):
        server_version = 'MAPA-GPS'

        def setup(self):
            super().setup()
            self.connection.settimeout(10)

        def log_message(self, *args):
            pass  # Never log request paths, bodies, IMEIs or credentials.

        def reply(self, status, result):
            body = json.dumps(result).encode()
            self.send_response(status)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(body)))
            self.send_header('Cache-Control', 'no-store')
            self.end_headers()
            self.wfile.write(body)

        def do_GET(self):
            self.reply(200 if self.path == '/healthz' else 404, {'service': 'MAPA GT06 bridge'})

        def do_POST(self):
            if self.path != '/positions':
                return self.reply(404, {'error': 'not_found'})
            auth = self.headers.get('Authorization', '')
            if not hmac.compare_digest(auth.encode('utf-8'), ('Bearer '+cfg['forward_secret']).encode('ascii')):
                return self.reply(401, {'error': 'unauthorized'})
            if self.headers.get_content_type() != 'application/json' or self.headers.get('Transfer-Encoding'):
                return self.reply(415, {'error': 'json_required'})
            try:
                size = int(self.headers.get('Content-Length', '0'))
                if not 0 < size <= 16384:
                    return self.reply(413, {'error': 'bounded_body_required'})
                data = json.loads(self.rfile.read(size))
                token, fix = translate(data, cfg['devices'], int(time.time()*1000))
            except Reject as error:
                # Acknowledge a permanent rejection so bad samples cannot fill Traccar's retry queue.
                print(json.dumps({'event': 'gps_discarded', 'reason': str(error)}), flush=True)
                return self.reply(200, {'discarded': str(error)})
            except (ValueError, OSError):
                return self.reply(400, {'error': 'invalid_json'})
            status = (sender or send)(token, fix)
            if status == 200:
                return self.reply(200, {'ok': True})
            if status in (401, 403, 409, 422):
                print(json.dumps({'event': 'gps_rejected_by_mapa', 'status': status}), flush=True)
                return self.reply(200, {'discarded': 'mapa_rejected', 'status': status})
            return self.reply(503, {'error': 'mapa_unavailable'})

    return Handler


class BoundedServer(ThreadingHTTPServer):
    daemon_threads = True
    slots = threading.BoundedSemaphore(32)

    def process_request(self, request, address):
        if not self.slots.acquire(blocking=False):
            self.shutdown_request(request)
            return
        try:
            super().process_request(request, address)
        except BaseException:
            self.slots.release()
            raise

    def process_request_thread(self, request, address):
        try:
            super().process_request_thread(request, address)
        finally:
            self.slots.release()


if __name__ == '__main__':
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument('--config', required=True)
    parser.add_argument('--bind', default='127.0.0.1')
    parser.add_argument('--port', type=int, default=8090)
    args = parser.parse_args()
    BoundedServer((args.bind, args.port), make_handler(load_config(args.config))).serve_forever()

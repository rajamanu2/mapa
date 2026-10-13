#!/usr/bin/env python3
"""Private, disabled-by-default mTLS Gammu SMSD injection adapter. No access logs."""
import argparse
from contextlib import contextmanager
import configparser
import hashlib
import hmac
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import re
import sqlite3
import ssl
import subprocess
import threading
import time
from urllib.parse import urlsplit

# Reviewed upstream release: multipart stdin and FILES MaxRetries fixes.
# A changed version requires separate source/CLI and modem acceptance review.
GAMMU_VERSION = '1.45.0'

class Reject(Exception):
    def __init__(self, status): self.status = status

def payload(data, origin):
    if not isinstance(data, dict) or set(data) != {'id', 'phone', 'url'}:
        raise Reject(422)
    if not all(isinstance(v, str) for v in data.values()): raise Reject(422)
    if not re.fullmatch(r'[a-f0-9]{64}', data['id']): raise Reject(422)
    if not re.fullmatch(r'\+[1-9][0-9]{7,14}', data['phone']): raise Reject(422)
    if not data['url'].startswith(origin + '/track/#') or not re.fullmatch(r'[a-f0-9]{64}', data['url'][len(origin + '/track/#'):]): raise Reject(422)
    return data

class Gateway:
    def __init__(self, config, runner=subprocess.run, version_runner=subprocess.run):
        self.config, self.runner, self.version_runner = config, runner, version_runner
        self.lock = threading.Lock()
        if config.get('enabled') is not True: raise ValueError('SMS gateway disabled')
        origin = config.get('tracking_origin', '')
        if origin != 'https://app.itda.asrhealth.in': raise ValueError('Canonical tracking origin required')
        if not re.fullmatch(r'[a-f0-9]{64}', config.get('token', '')): raise ValueError('Private authentication required')
        database = Path(config['ledger'])
        if not database.is_absolute() or database.is_symlink() or not database.parent.is_dir(): raise ValueError('Private ledger required')
        if os.name != 'nt' and database.parent.stat().st_mode & 0o077: raise ValueError('Ledger directory must be mode 0700')
        with self.db() as conn:
            conn.execute('CREATE TABLE IF NOT EXISTS attempts (id TEXT PRIMARY KEY, fingerprint TEXT NOT NULL, status TEXT NOT NULL, created INTEGER NOT NULL, updated INTEGER NOT NULL)')
        if os.name != 'nt': os.chmod(database, 0o600)

    @contextmanager
    def db(self):
        connection = sqlite3.connect(self.config['ledger'], timeout=5)
        try:
            with connection: yield connection
        finally: connection.close()

    def authenticate(self, authorization):
        return hmac.compare_digest('Bearer ' + self.config['token'], authorization or '')

    def cli_ready(self):
        # -v exits without reading SMSD config or contacting a modem. Do not
        # silently assume an installed distro's older CLI preserves long links.
        try:
            for key, name in (('inject_binary', 'Gammu-smsd-inject'), ('smsd_binary', 'Gammu-smsd')):
                binary = self.config.get(key, '')
                if not binary or not Path(binary).is_absolute(): return False
                result = self.version_runner([binary, '-v'], stdin=subprocess.DEVNULL,
                    stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=3, check=False, shell=False)
                expected = (name + ' version ' + GAMMU_VERSION).encode('ascii')
                if result.returncode != 0 or not isinstance(result.stdout, bytes) or result.stdout.splitlines()[:1] != [expected]: return False
            return True
        except (OSError, subprocess.SubprocessError): return False

    def smsd_ready(self):
        """Recheck local daemon safety before every injection; never execute config."""
        try:
            path = Path(self.config['smsd_config'])
            if path.is_symlink() or not path.is_file() or path.stat().st_size > 32768: return False
            parser = configparser.ConfigParser(interpolation=None)
            parser.read(path)
            return (parser.get('smsd', 'MaxRetries', fallback='') == '0'
                    and parser.get('smsd', 'DebugLevel', fallback='0') == '0'
                    and parser.get('smsd', 'LogFile', fallback='').strip() in ('', '/dev/null')
                    and parser.get('smsd', 'Service', fallback='').lower() in ('sql', 'files'))
        except (OSError, configparser.Error): return False

    def submit(self, data):
        data = payload(data, self.config['tracking_origin'])
        message = 'ASR Health: view your ambulance journey and optionally share pickup location: ' + data['url']
        message_bytes = message.encode('ascii')
        # No truncation. Current fixed GSM-basic-alphabet message fits two parts.
        # Gammu also enforces the encoded part limit before injecting anything.
        if len(message_bytes) > 306: raise Reject(422)
        encoded = json.dumps(data, sort_keys=True, separators=(',', ':')).encode()
        fingerprint = hmac.new(self.config['token'].encode(), encoded, hashlib.sha256).hexdigest()
        # Serialize reservation only. Commit before touching the modem queue:
        # crash/timeout leaves unknown and is NEVER automatically re-injected.
        with self.lock, self.db() as conn:
            conn.execute('BEGIN IMMEDIATE')
            old = conn.execute('SELECT fingerprint,status FROM attempts WHERE id=?', (data['id'],)).fetchone()
            if old:
                if not hmac.compare_digest(old[0], fingerprint): raise Reject(409)
                return {'id': data['id'], 'status': old[1], 'delivered': False, 'replayed': True}
            if not self.smsd_ready() or not self.cli_ready(): raise Reject(503)
            stamp = int(time.time())
            conn.execute('INSERT INTO attempts VALUES (?,?,?,?,?)', (data['id'], fingerprint, 'unknown', stamp, stamp))
        status = 'unknown'
        try:
            result = self.runner([self.config['inject_binary'], '-c', self.config['smsd_config'], '-L', 'TEXT', data['phone'],
                                  '-len', str(len(message_bytes)), '-maxsms', '2'],
                                 input=message_bytes, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                 timeout=10, check=False, shell=False)
            if result.returncode == 0: status = 'queued'
        except (OSError, subprocess.SubprocessError): pass
        with self.db() as conn:
            conn.execute('UPDATE attempts SET status=?,updated=? WHERE id=?', (status, int(time.time()), data['id']))
        return {'id': data['id'], 'status': status, 'delivered': False, 'replayed': False}

class Handler(BaseHTTPRequestHandler):
    server_version = 'MAPA-SMS'
    sys_version = ''
    def log_message(self, *args): pass
    def reply(self, status, value):
        body = json.dumps(value, separators=(',', ':')).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Cache-Control', 'no-store')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)
    def do_POST(self):
        try:
            if not self.server.gateway.authenticate(self.headers.get('Authorization')): raise Reject(401)
            if self.path != '/v1/sms': raise Reject(404)
            if self.headers.get('Transfer-Encoding') or self.headers.get_content_type() != 'application/json': raise Reject(415)
            try: size = int(self.headers.get('Content-Length', '0'))
            except ValueError: raise Reject(413)
            if not 1 <= size <= 2048: raise Reject(413)
            self.connection.settimeout(5)
            raw = self.rfile.read(size)
            if len(raw) != size: raise Reject(400)
            try: data = json.loads(raw)
            except (ValueError, UnicodeError): raise Reject(400)
            result = self.server.gateway.submit(data)
            self.reply(200 if result['replayed'] else 202, result)
        except Reject as error: self.reply(error.status, {'error': 'SMS request unavailable or rejected.'})
        except Exception: self.reply(503, {'error': 'SMS outcome unknown. Do not resend automatically.'})
    def do_GET(self): self.reply(405, {'error': 'Method not allowed.'})

class Server(ThreadingHTTPServer):
    daemon_threads = True
    def __init__(self, address, gateway, tls):
        self.gateway = gateway
        self.tls = tls
        self.slots = threading.BoundedSemaphore(8)
        super().__init__(address, Handler)
    def get_request(self):
        request, address = self.socket.accept()
        request.settimeout(5)
        try: return self.tls.wrap_socket(request, server_side=True), address
        except Exception: request.close(); raise
    def process_request(self, request, address):
        if not self.slots.acquire(blocking=False):
            self.shutdown_request(request)
            return
        try: super().process_request(request, address)
        except Exception: self.slots.release(); raise
    def process_request_thread(self, request, address):
        try: super().process_request_thread(request, address)
        finally: self.slots.release()
    def handle_error(self, request, address): pass

def tls_context(config):
    tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    tls.minimum_version = ssl.TLSVersion.TLSv1_2
    tls.verify_mode = ssl.CERT_REQUIRED
    tls.load_cert_chain(config['tls_cert'], config['tls_key'])
    tls.load_verify_locations(config['client_ca'])
    return tls

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', required=True)
    args = parser.parse_args()
    try:
        os.umask(0o077)
        path = Path(args.config)
        if path.is_symlink() or not path.is_file() or path.stat().st_size > 16384: raise ValueError()
        if os.name != 'nt' and path.stat().st_mode & 0o077: raise ValueError()
        config = json.loads(path.read_text())
        gateway = Gateway(config)
        for key in ('inject_binary', 'smsd_binary', 'smsd_config', 'tls_cert', 'tls_key', 'client_ca'):
            target = Path(config[key])
            if not target.is_absolute() or not target.is_file() or target.is_symlink(): raise ValueError()
        if not gateway.smsd_ready() or not gateway.cli_ready(): raise ValueError()
        tls = tls_context(config)
        server = Server((config.get('bind', '127.0.0.1'), int(config.get('port', 9443))), gateway, tls)
        server.serve_forever()
    except Exception:
        print('MAPA SMS gateway unavailable. Review private setup; no configuration details logged.')
        return 1

if __name__ == '__main__': raise SystemExit(main())

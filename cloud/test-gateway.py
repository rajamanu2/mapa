#!/usr/bin/env python3
"""Read-only routing checks against a private test gateway (no sign-in required)."""
import argparse
import json
from urllib.request import Request, urlopen
from urllib.error import HTTPError

parser = argparse.ArgumentParser()
parser.add_argument('--base', default='http://127.0.0.1:8894')
parser.add_argument('--host', default='mapa.rajamanu2.workers.dev')
args = parser.parse_args()
cases = [
    ('/fleet-ops/connectivity/telematics', 200),
    ('/fleetbase.config.json', 200),
    ('/api/int/v1/auth/session', 401),
    ('/mapa/mobile/v1/snapshot', 401),
    ('/mapa/voice/v1/calls', 401),
    ('/api/int/v1/auth/sign-up', 404),
    ('/api/int/v1/onboard/create-account', 404),
    ('/mapa/voice/v1/bridge/events', 404),
    ('/api/mapa/voice/v1/bridge/events', 404),
    ('/api/%6dapa/voice/v1/bridge/events', 404),
    ('/.env', 404),
    ('/database.sql', 404),
    ('/private/manifest.json', 404),
    ('/api/_ignition/execute-solution', 404),
    ('/socketcluster/', 503),
    ('/media', 404),
]
for path, expected in cases:
    req = Request(args.base.rstrip('/') + path, headers={'Host': args.host, 'Accept': 'application/json'})
    try:
        response = urlopen(req, timeout=35)
    except HTTPError as exc:
        response = exc
    with response:
        status, headers, body = response.status, response.headers, response.read(1024 * 1024)
    assert status == expected, f'{path}: expected {expected}, received {status}'
    assert 'no-store' in headers.get('Cache-Control', ''), f'{path}: cache control absent'
    if path == '/fleetbase.config.json':
        cfg = json.loads(body)
        assert cfg['API_HOST'] == 'https://' + args.host + '/api'
        assert cfg['SOCKETCLUSTER_SECURE'] is True and cfg['SOCKETCLUSTER_PORT'] == 443
    if path.startswith('/fleet-ops/'):
        assert b'<html' in body.lower(), 'Native console HTML was not served'
    print(f'PASS {status} {path}')
print(f'{len(cases)} gateway integration checks passed; public deployment and authenticated browser acceptance remain pending.')

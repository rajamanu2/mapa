#!/usr/bin/env python3
"""Run from an independent host every minute; exit nonzero for its alerting system."""
import argparse
import concurrent.futures
import json
import urllib.error
import urllib.request
from urllib.parse import urlsplit

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

def check_redirect(origin, canonical):
    """Verify the relay's browser response itself, without following a redirect."""
    try:
        request = urllib.request.Request(origin + '/fleet-ops', headers={
            'Accept': 'text/html', 'User-Agent': 'MAPA-External-Health/1.0'})
        try:
            response = urllib.request.build_opener(NoRedirect).open(request, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status in (301, 302, 307, 308) and response.headers.get('Location') == canonical + '/fleet-ops'
    except Exception:
        return False

def check(origin, path):
    try:
        request = urllib.request.Request(origin + path, headers={'Accept': 'application/json', 'User-Agent': 'MAPA-External-Health/1.0'})
        try:
            response = urllib.request.urlopen(request, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            body = response.read(512000)
            code, kind = response.status, response.headers.get('Content-Type', '')
            if urlsplit(response.url).netloc != urlsplit(origin).netloc:
                return False
        if path == '/api/int/v1/users/me':
            return code == 401 and 'json' in kind and isinstance(json.loads(body).get('errors'), list)
        if path == '/api/int/v1/settings/branding':
            return code == 200 and 'json' in kind and 'brand' in json.loads(body)
        return code == 200 and (b'MAPA' in body if path == '/healthz' else 'text/html' in kind)
    except Exception:
        return False

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('origin')
    parser.add_argument('--compatibility-origin', help='Also check old API routes and exact browser redirect')
    args = parser.parse_args()
    for origin in filter(None, [args.origin, args.compatibility_origin]):
        url = urlsplit(origin)
        if url.scheme != 'https' or not url.hostname or url.username or url.password or url.query or url.fragment or url.path not in ('', '/'):
            parser.error('Use HTTPS origins without credentials or path')
    paths = ['/healthz', '/auth', '/api/int/v1/settings/branding', '/api/int/v1/users/me']
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        results = dict(zip(paths, pool.map(lambda path: check(args.origin.rstrip('/'), path), paths)))
        if args.compatibility_origin:
            relay = args.compatibility_origin.rstrip('/')
            results.update(zip(('compatibility:' + path for path in paths), pool.map(lambda path: check(relay, path), paths)))
            results['compatibility:browser-redirect'] = check_redirect(relay, args.origin.rstrip('/'))
    print(json.dumps({'healthy': all(results.values()), 'checks': results}))
    return 0 if all(results.values()) else 1

if __name__ == '__main__':
    raise SystemExit(main())

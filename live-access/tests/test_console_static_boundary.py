"""Local Nginx HTTP regression using synthetic files; never attaches application volumes."""
import json
from pathlib import Path
import subprocess
import tempfile
import time
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parents[2]
DOCKER = Path.home() / 'AppData/Local/Programs/DockerDesktop/resources/bin/docker.exe'

def docker(*args):
    return subprocess.check_output([str(DOCKER), *args], text=True).strip()

def main():
    count = 0
    assert (ROOT / 'console/nginx.conf').is_file()
    with tempfile.TemporaryDirectory(prefix='mapa-security-test-') as temporary:
        public = Path(temporary)
        fixtures = ['index.html', 'assets/app.js', 'assets/app.js.map', '.env', '.git/config',
                    'private/keys.json', 'backups/a.zip', 'storage/patients.json', 'source.php',
                    'config/database.json', 'dump.sql', 'mapa-source.zip', 'unreviewed.zip',
                    'downloads/MAPA-Console-Android-Source.zip', 'mapa-license.txt']
        for name in fixtures:
            dest = public / name
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_text('SYNTHETIC DEMO FILE', encoding='utf-8')
        container = docker('run', '--rm', '-d', '--read-only', '--tmpfs', '/var/cache/nginx',
                           '--tmpfs', '/var/run', '--publish', '127.0.0.1::4200',
                           '--mount', f'type=bind,source={public},target=/usr/share/nginx/html,readonly',
                           '--mount', f'type=bind,source={ROOT / "console/nginx.conf"},target=/etc/nginx/conf.d/default.conf,readonly',
                           '--entrypoint', 'nginx', 'nginx:1.31.6-alpine', '-g', 'daemon off;')
        try:
            port = docker('port', container, '4200/tcp').split(':')[-1]
            origin = 'http://127.0.0.1:' + port
            for attempt in range(30):
                try:
                    with urlopen(origin, timeout=2):
                        break
                except URLError:
                    time.sleep(0.2)
            cases = [(name, 404) for name in fixtures if name not in {
                'index.html', 'assets/app.js', 'mapa-license.txt',
                'downloads/MAPA-Console-Android-Source.zip'}]
            cases += [('assets/app.js', 200), ('mapa-source.zip?download=1', 404), ('mapa-license.txt', 200),
                      ('downloads/MAPA-Console-Android-Source.zip', 200),
                      ('%2eenv', 404), ('assets/app.js.map?cache=1', 404)]
            for name, expected in cases:
                try:
                    response = urlopen(origin + '/' + name, timeout=3)
                except HTTPError as exc:
                    response = exc
                with response:
                    assert response.status == expected, (name, response.status, expected)
                    assert response.headers.get('X-Content-Type-Options') == 'nosniff'
                    assert '1.31' not in response.headers.get('Server', '')
                count += 1
            for name in ['mapa-source.zip', 'downloads/MAPA-Console-Android-Source.zip']:
                try:
                    urlopen(Request(origin + '/' + name, data=b'', method='POST'), timeout=3)
                    raise AssertionError('Archive accepted POST')
                except HTTPError as exc:
                    assert exc.code == (404 if name == 'mapa-source.zip' else 403)
                count += 1
            print(json.dumps({'passed': count, 'production_modified': False, 'synthetic_only': True}))
        finally:
            docker('rm', '-f', container)

if __name__ == '__main__':
    main()

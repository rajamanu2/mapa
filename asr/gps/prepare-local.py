"""Generate local-only private configuration. Does not start containers or alter MAPA."""
import argparse
import json
import secrets
from pathlib import Path
from xml.sax.saxutils import escape


def receiver_xml(secret):
    entries = {
        'database.driver': 'org.h2.Driver',
        'database.url': 'jdbc:h2:./data/database',
        'database.user': 'sa', 'database.password': '',
        'protocols.enable': 'gt06', 'gt06.port': '5023', 'gt06.timeout': '300',
        'database.registerUnknown': 'false', 'database.throttleUnknown': 'true',
        'geocoder.enable': 'false', 'geolocation.enable': 'false',
        'web.port': '8082', 'web.console': 'false',
        'logger.console': 'true', 'logger.level': 'warning',
        'forward.type': 'json', 'forward.url': 'http://bridge:8090/positions',
        'forward.header': 'Authorization: Bearer '+secret,
        'forward.retry.enable': 'true', 'forward.retry.delay': '1000',
        'forward.retry.count': '8', 'forward.retry.limit': '1000',
    }
    return '<?xml version="1.0" encoding="UTF-8"?>\n<!DOCTYPE properties SYSTEM "http://java.sun.com/dtd/properties.dtd">\n<properties>\n'+''.join('  <entry key="'+k+'">'+escape(v)+'</entry>\n' for k,v in entries.items())+'</properties>\n'


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--directory', type=Path, default=Path(__file__).parent/'private')
    args = parser.parse_args()
    target = args.directory.resolve()
    target.mkdir(parents=True, exist_ok=True)
    if (target/'traccar.xml').exists() or (target/'bridge.json').exists():
        parser.error('Existing configuration retained; edit it deliberately instead of resetting credentials.')
    secret = secrets.token_hex(32)
    (target/'traccar.xml').write_text(receiver_xml(secret), encoding='utf-8')
    (target/'bridge.json').write_text(json.dumps({'local_test_only': True,
        'ingest_url': 'http://host.docker.internal:8897/mapa/mobile/v1/tracking/ingest',
        'forward_secret': secret, 'devices': {}}, indent=2), encoding='utf-8')
    print('Local private configuration created with an empty device allowlist. No services started.')

"""Use this MAPA deployment's release notes in the native changelog modal."""
import argparse
import hashlib
import json
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[2]
VENDOR = 'assets/vendor.js'
MODULE = '@fleetbase/ember-ui/components/modals/changelog'
UPSTREAM = 'https://api.github.com/repos/fleetbase/fleetbase/releases'
LOCAL = '/mapa-changelog.json'


def patch_vendor(text):
    marker = 'define(' + json.dumps(MODULE) + ','
    if text.count(marker) != 1:
        raise ValueError('Expected exactly one native changelog component.')
    start = text.index(marker)
    end = text.find(';define(', start + len(marker))
    if end < 0:
        end = len(text)
    section = text[start:end]
    if section.count(UPSTREAM) + section.count(LOCAL) != 1:
        raise ValueError('Expected one changelog data source.')
    return text[:start] + section.replace(UPSTREAM, LOCAL) + text[end:]


def apply(console):
    notes = (ROOT / 'fleetbase/console/public/mapa-changelog.json').read_text(encoding='utf-8')
    releases = json.loads(notes)
    if not releases or any(not all(isinstance(item.get(key), str) and item[key].strip()
                                  for key in ('name', 'created_at', 'body')) for item in releases):
        raise ValueError('Incomplete MAPA release notes.')
    if re.search(r'fleetbase|fleet[- ]?ops', notes, re.I):
        raise ValueError('Upstream product branding found in MAPA release notes.')
    vendor = patch_vendor((console / VENDOR).read_text(encoding='utf-8'))
    version = hashlib.sha256(vendor.encode()).hexdigest()[:12]
    index = (console / 'index.html').read_text(encoding='utf-8')
    index, count = re.subn(r'(src="/assets/vendor.js)(?:\?[^"\s]*)?("\s*>)',
                          lambda m: m.group(1) + '?mapa-changelog=' + version + m.group(2), index)
    if count != 1:
        raise ValueError('Expected one native vendor script.')
    # Publish the data before the component that requests it; preserve all other assets.
    (console / 'mapa-changelog.json').write_text(notes, encoding='utf-8', newline='\n')
    (console / VENDOR).write_text(vendor, encoding='utf-8', newline='\n')
    (console / 'index.html').write_text(index, encoding='utf-8', newline='\n')
    print('MAPA changelog installed; vendor version ' + version + '.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--console-root', type=Path, default=ROOT / 'private/live-access/console')
    args = parser.parse_args()
    apply(args.console_root.resolve(strict=True))

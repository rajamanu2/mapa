"""Publish the compact MAPA legal dialog and linked software notices."""
import hashlib
from pathlib import Path
import re

root = Path(__file__).resolve().parents[2]
public = root / 'fleetbase/console/public'
console = root / 'private/live-access/console'
index_path = console / 'index.html'
index = index_path.read_text(encoding='utf-8')
assets = {name: (public / name).read_bytes() for name in
          ('mapa-legal.js', 'mapa-legal.css', 'mapa-notices.html')}
for name in ('mapa-legal.js', 'mapa-legal.css'):
    version = hashlib.sha256(assets[name]).hexdigest()[:12]
    pattern = r'((?:src|href)="/' + re.escape(name) + r')(?:\?[^"\s]*)?("\s*)'
    index, count = re.subn(pattern, lambda m: m[1] + '?v=' + version + m[2], index)
    if count != 1:
        raise SystemExit('Expected one legal asset reference: ' + name)
for name, content in assets.items():
    (console / name).write_bytes(content)
index_path.write_text(index, encoding='utf-8', newline='\n')
print('Compact MAPA legal dialog and linked notices published; asset cache versions refreshed.')

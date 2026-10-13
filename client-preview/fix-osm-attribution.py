"""Preserve map attribution in the adapted client preview only."""
from pathlib import Path
import argparse
import re

parser=argparse.ArgumentParser(); parser.add_argument('--console-root', type=Path, default=Path(__file__).resolve().parents[2] / 'private/client-preview/console-pages'); args=parser.parse_args()
bundle = args.console_root / 'engines-dist/@fleetbase/fleetops-engine/assets/engine.js'
data = bundle.read_bytes()
marker = b'// MAPA preview: keep the default OpenStreetMap credit visible.'
old = b'        this._tileLayer = L.tileLayer(options.tileUrl, options.tileOptions ?? {}).addTo(this._map);'
if marker in data:
    if data.count(marker) != 1:
        raise SystemExit('Repeated patch marker; no file changed.')
    start = data.index(marker)
    end = data.index(b'this._tileLayer = L.tileLayer(options.tileUrl, tileOptions).addTo(this._map);', start)
    patched = data[start:end].replace(b"setPosition('topright')", b"setPosition('bottomleft')").replace(b"position: 'topright'", b"position: 'bottomleft'")
    data = data[:start] + patched + data[end:]
    bundle.write_bytes(data)
    print('OSM attribution retained at bottom left within its map.')
else:
    if data.count(old) != 1:
        raise SystemExit('Expected one tile initialization anchor; no file changed.')
    start = data.index(old)
    newline = b'\r\n' if data[start + len(old):start + len(old) + 2] == b'\r\n' else b'\n'
    replacement = '''        // MAPA preview: keep the default OpenStreetMap credit visible.
        let tileOptions = options.tileOptions ?? {};
        let usesDefaultOpenStreetMap = false;
        try {
          usesDefaultOpenStreetMap = new URL(options.tileUrl, window.location.href).origin === 'https://tile.openstreetmap.org';
        } catch (_) {
          // Leave non-URL/custom provider handling to Leaflet.
        }
        if (usesDefaultOpenStreetMap) {
          tileOptions = {
            ...tileOptions,
            attribution: tileOptions.attribution || '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors'
          };
          if (this._map.attributionControl) {
            this._map.attributionControl.setPosition('bottomleft');
          } else {
            this._map.attributionControl = L.control.attribution({ position: 'bottomleft' }).addTo(this._map);
          }
        }
        this._tileLayer = L.tileLayer(options.tileUrl, tileOptions).addTo(this._map);'''.encode('utf-8').replace(b'\n', newline)
    bundle.write_bytes(data.replace(old, replacement, 1))
    print('Updated only adapted preview OSM attribution; other provider options retained.')

# The console also contains a legacy Leaflet view; give it visible provider credit.
preview = args.console_root
credit_source = Path(__file__).with_name('map-credit.js')
index_path = preview / 'index.html'
index = index_path.read_text(encoding='utf-8')
tag = '<script src="/mapa-preview-map-credit.js"></script>'
if not re.search(r'<script\s+src="/mapa-preview-map-credit\.js(?:\?[^"\s]*)?"\s*></script>', index):
    if index.count('</body>') != 1:
        raise SystemExit('Expected one index body end; credit script not installed.')
    index_path.write_text(index.replace('</body>', tag + '</body>'), encoding='utf-8')
(preview / 'mapa-preview-map-credit.js').write_bytes(credit_source.read_bytes())

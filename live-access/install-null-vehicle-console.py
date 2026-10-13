"""Prepare/apply reviewed vehicle-only null-GPS guards in the retained console.

Default is a read-only preflight. --output-root writes an isolated test candidate;
--apply is reserved for the release owner after review. No containers/network.
"""
from pathlib import Path
import argparse
import hashlib
import json
import re
import os
import tempfile
from urllib.parse import quote, unquote

ROOT = Path(__file__).resolve().parents[2]
VENDOR = 'assets/vendor.js'
ENGINE = 'engines-dist/@fleetbase/fleetops-engine/assets/engine.js'
APP = 'assets/@fleetbase/console.js'
MANIFEST = Path(__file__).with_name('overrides') / 'vehicle-null-console-manifest.json'

TRANSFORM = ''';define("@fleetbase/console/transforms/mapa-vehicle-point", ["exports", "@ember-data/serializer/transform", "@fleetbase/fleetops-data/utils/geojson/point"], function (_exports, _transform, _point) {
  "use strict";
  Object.defineProperty(_exports, "__esModule", { value: true });
  class MapaVehiclePointTransform extends _transform.default {
    deserialize(value) { return value === null || value === undefined ? null : new _point.default(value); }
    serialize(value) { return value === null || value === undefined ? null : new _point.default(value); }
  }
  _exports.default = MapaVehiclePointTransform;
});
'''

def digest(text):
    return hashlib.sha256(text.encode('utf-8')).hexdigest()

def module_span(text, name):
    needle = 'define("' + name + '"'
    if text.count(needle) != 1:
        raise ValueError('Expected one native module: ' + name)
    start = text.index(needle)
    following = re.search(r'\n[ ;]*define\("', text[start + 1:])
    end = start + 1 + following.start() if following else len(text)
    return start, end

def once(text, old, new):
    if text.count(old) != 1:
        raise ValueError('Native source differs from reviewed anchor')
    return text.replace(old, new, 1)

def patch_vehicle(text):
    text = once(text, "_dec23 = (0, _model.attr)('point')", "_dec23 = (0, _model.attr)('mapa-vehicle-point')")
    text = once(text, "return (0, _object.get)(this.location, 'coordinates.0');", 'return this.location?.coordinates?.[0];')
    return once(text, "return (0, _object.get)(this.location, 'coordinates.1');", 'return this.location?.coordinates?.[1];')

def patch_replay(text):
    text = once(text, '{{#if this.resource}}', '{{#if this.hasResourcePosition}}')
    text = once(text, '[41,[30,0,[\\"resource\\"]]', '[41,[30,0,[\\"hasResourcePosition\\"]]')
    return once(text, '    get resourceName() {', '''    get hasResourcePosition() {
      const coordinates = this.resource?.location?.coordinates;
      return Array.isArray(coordinates) && coordinates.length >= 2 && Number.isFinite(coordinates[0]) && Number.isFinite(coordinates[1]);
    }
    get resourceName() {''')

def patch_leaflet(text):
    text = once(text, '        const data = yield this.fetch.get(url, params, {', '        let data = yield this.fetch.get(url, params, {')
    return once(text, '        this.trigger(callback, data);\n        this[name] = data;', '''        if (path === 'vehicles') {
          data = data.filter(vehicle => {
            const coordinates = vehicle?.location?.coordinates;
            return Array.isArray(coordinates) && coordinates.length >= 2 && Number.isFinite(coordinates[0]) && Number.isFinite(coordinates[1]);
          });
        }
        this.trigger(callback, data);
        this[name] = data;''')

def patch_locate(text):
    return once(text, '    locate(vehicle, options = {}) {', '''    locate(vehicle, options = {}) {
      const coordinates = vehicle?.location?.coordinates;
      if (!Array.isArray(coordinates) || coordinates.length < 2 || !Number.isFinite(coordinates[0]) || !Number.isFinite(coordinates[1])) {
        this.notifications.info('No GPS position has been received for this vehicle.');
        return;
      }''')

PATCHES = [
    (VENDOR, '@fleetbase/fleetops-data/models/vehicle', patch_vehicle),
    (ENGINE, '@fleetbase/fleetops-engine/components/positions-replay', patch_replay),
    (ENGINE, '@fleetbase/fleetops-engine/components/map/leaflet-live-map', patch_leaflet),
    (ENGINE, '@fleetbase/fleetops-engine/services/vehicle-actions', patch_locate),
]

def prepare(console, manifest):
    files = {file: (console / file).read_text(encoding='utf-8') for file in [VENDOR, ENGINE, APP]}
    changes = []
    for file, name, patch in PATCHES:
        start, end = module_span(files[file], name)
        module = files[file][start:end]
        allowed = manifest[name]
        if digest(module) == allowed['patched_sha256']:
            continue
        if digest(module) != allowed['original_sha256']:
            raise ValueError('Unreviewed native module: ' + name)
        patched = patch(module)
        if digest(patched) != allowed['patched_sha256']:
            raise ValueError('Patch differs from reviewed module: ' + name)
        files[file] = files[file][:start] + patched + files[file][end:]
        changes.append(name)
    marker = 'define("@fleetbase/console/transforms/mapa-vehicle-point"'
    if marker in files[APP]:
        if files[APP].count(marker) != 1 or files[APP].count(TRANSFORM) != 1:
            raise ValueError('Unreviewed vehicle transform')
    else:
        # Define before the console boot require, never after eager resolution.
        boot = '\n          if (!runningTests) {'
        if files[APP].count(boot) != 1:
            raise ValueError('Expected reviewed console boot boundary')
        files[APP] = files[APP].replace(boot, '\n' + TRANSFORM + boot, 1)
        changes.append('@fleetbase/console/transforms/mapa-vehicle-point')
    engine_uri = '/engines-dist/@fleetbase/fleetops-engine/assets/engine.js'
    def refresh_assets(value):
        assets = value['bundles']['@fleetbase/fleetops-engine']['assets']
        matches = [asset for asset in assets if asset.get('uri', '').split('?')[0] == engine_uri]
        if len(matches) != 1:
            raise ValueError('Expected one native operations engine asset')
        matches[0]['uri'] = engine_uri + '?mapa-null-gps=' + digest(files[ENGINE])[:12]
        return value
    index = (console / 'index.html').read_text(encoding='utf-8')
    for file, uri in [(VENDOR, '/assets/vendor.js'), (APP, '/assets/@fleetbase/console.js')]:
        pattern = r'(src="' + re.escape(uri) + r')(?:\?[^"\s]*)?(\")'
        index, count = re.subn(pattern, lambda match: match[1] + '?mapa-null-gps=' + digest(files[file])[:12] + match[2], index)
        if count != 1:
            raise ValueError('Expected one native script tag: ' + uri)
    def refresh_meta(match):
        value = refresh_assets(json.loads(unquote(match[2])))
        return match[1] + quote(json.dumps(value, separators=(',', ':')), safe='/') + match[3]
    index, count = re.subn(r'(<meta name="@fleetbase/console/config/asset-manifest" content=")([^"\s]+)("\s*/>)', refresh_meta, index)
    if count != 1:
        raise ValueError('Expected one console asset manifest meta tag')
    files['index.html'] = index
    assets = refresh_assets(json.loads((console / 'asset-manifest.json').read_text(encoding='utf-8')))
    files['asset-manifest.json'] = json.dumps(assets, separators=(',', ':')) + '\n'
    return files, changes

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--console-root', type=Path, default=ROOT / 'private/live-access/console')
    parser.add_argument('--output-root', type=Path)
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    if args.apply and args.output_root:
        parser.error('Choose an isolated candidate or apply, not both')
    files, changes = prepare(args.console_root, json.loads(MANIFEST.read_text(encoding='utf-8')))
    target = args.console_root if args.apply else args.output_root
    if target:
        if not args.apply and target.resolve() == args.console_root.resolve():
            parser.error('Candidate must not replace the served console')
        for file in files:
            if not (target / file).resolve().is_relative_to(target.resolve()):
                raise ValueError('Bundle path escaped the reviewed console root')
        for file, contents in files.items():
            path = target / file
            path.parent.mkdir(parents=True, exist_ok=True)
            # All source/hash preflights complete before replacing any bundle;
            # each served file is replaced atomically in its own directory.
            with tempfile.NamedTemporaryFile(mode='w', encoding='utf-8', newline='\n', dir=path.parent, prefix='.mapa-null-gps-', suffix='.tmp', delete=False) as temporary:
                temporary.write(contents)
                temporary.flush()
                os.fsync(temporary.fileno())
                temporary_path = Path(temporary.name)
            try:
                os.replace(temporary_path, path)
            finally:
                temporary_path.unlink(missing_ok=True)
    print(json.dumps({'verified': True, 'changed_modules': changes, 'written': bool(target), 'bundle_sha256': {file: digest(contents) for file, contents in files.items()}}))

if __name__ == '__main__':
    main()

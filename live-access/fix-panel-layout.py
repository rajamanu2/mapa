"""Install the scoped native create-call header and map-credit layout corrections."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys
from urllib.parse import quote, unquote

ROOT = Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--console-root', type=Path, default=ROOT / 'private/live-access/console')
args = parser.parse_args()
console = args.console_root.resolve()

def edit_template(text, name, edit, edit_comment):
    marker = 'define(' + json.dumps(name) + ','
    if text.count(marker) != 1:
        raise ValueError('Expected one native template module: ' + name)
    start = text.index(marker)
    next_module = re.search(r'(?:;|\n)define\(', text[start + len(marker):])
    end = start + len(marker) + next_module.start() if next_module else len(text)
    section = text[start:end]
    match = re.search(r'"block":\s*("(?:\\.|[^"\\])*")', section)
    if not match:
        raise ValueError('Missing native template block: ' + name)
    block = json.loads(json.loads(match.group(1)))
    edit(block)
    encoded = json.dumps(json.dumps(block, separators=(',', ':')), ensure_ascii=False)
    section = section[:match.start(1)] + encoded + section[match.end(1):]
    return text[:start] + edit_comment(section) + text[end:]

HELP = 'Select pickup and drop-off to enable Create call.'

def patch_new_call(block):
    panel = block[0][0]
    if panel[:2] != [8, [39, block[3].index('layout/resource/panel')]]:
        raise ValueError('Unexpected new-call panel structure.')
    names, values = panel[3]
    if '@modelName' in names:
        values[names.index('@modelName')] = 'call'
    else:
        names.append('@modelName')
        values.append('call')
    statements = panel[4][1][0][0]
    if not any(statement == [1, HELP] for statement in statements):
        statements[0:0] = [[10, 'p'], [14, 0, 'mapa-create-call-help'], [12], [1, HELP], [13]]

def patch_new_comment(section):
    section = re.sub(r'@modelName="[^"]*"', '@modelName="call"', section)
    if '@modelName="call"' not in section:
        section = section.replace('@resource={{this.order}}', '@resource={{this.order}}\n      @modelName="call"', 1)
    hint = '<p class="mapa-create-call-help">' + HELP + '</p>'
    if hint not in section:
        section = section.replace('<Order::Form ', hint + '\n      <Order::Form ', 1)
    return section

def patch_header(block):
    # modelName is a display label; save/authorization still use @resource.
    if '@modelName' not in block[1]:
        block[1].append('@modelName')
    value = [30, block[1].index('@modelName') + 1]
    target = [39, block[3].index('layout/resource/panel/header-actions')]
    found = []
    def visit(node):
        if not isinstance(node, list):
            return
        if len(node) > 3 and node[0] == 8 and node[1] == target:
            names, values = node[3]
            if '@modelName' in names:
                values[names.index('@modelName')] = value
            else:
                names.append('@modelName')
                values.append(value)
            found.append(True)
        for child in node:
            visit(child)
    visit(block[0])
    if len(found) != 1:
        raise ValueError('Expected one header-actions component.')

def patch_header_comment(section):
    if '@modelName={{@modelName}}' not in section:
        section = section.replace('@resource={{@resource}}', '@resource={{@resource}}\n              @modelName={{@modelName}}', 1)
    return section

engine_file = console / 'engines-dist/@fleetbase/fleetops-engine/assets/engine.js'
engine_text = edit_template(engine_file.read_text(encoding='utf-8'), '@fleetbase/fleetops-engine/templates/operations/orders/index/new', patch_new_call, patch_new_comment)
vendor_file = console / 'assets/vendor.js'
vendor_text = edit_template(vendor_file.read_text(encoding='utf-8'), '@fleetbase/ember-ui/components/layout/resource/panel/header', patch_header, patch_header_comment)
engine_file.write_text(engine_text, encoding='utf-8')
vendor_file.write_text(vendor_text, encoding='utf-8')
subprocess.run([sys.executable, str(ROOT / 'fleetbase/client-preview/fix-osm-attribution.py'), '--console-root', str(console)], check=True)
css = (ROOT / 'fleetbase/console/public/mapa-layout-fixes.css').read_bytes()
css_version = hashlib.sha256(css).hexdigest()[:12]
(console / 'mapa-layout-fixes.css').write_bytes(css)
index_path = console / 'index.html'
index = index_path.read_text(encoding='utf-8')
tag = f'<link rel="stylesheet" href="/mapa-layout-fixes.css?v={css_version}">'
pattern = r'<link\s+rel="stylesheet"\s+href="/mapa-layout-fixes\.css(?:\?[^"\s]*)?"\s*/?>'
if re.search(pattern, index):
    index, count = re.subn(pattern, tag, index)
    if count != 1:
        raise SystemExit('Expected one layout stylesheet; no index changed.')
else:
    if index.count('</head>') != 1:
        raise SystemExit('Missing unique HTML head; no index changed.')
    index = index.replace('</head>', tag + '</head>')
credit_version = hashlib.sha256((console / 'mapa-preview-map-credit.js').read_bytes()).hexdigest()[:12]
index = re.sub(r'src="/mapa-preview-map-credit\.js(?:\?[^"\s]*)?"', f'src="/mapa-preview-map-credit.js?v={credit_version}"', index)
vendor_version = hashlib.sha256(vendor_file.read_bytes()).hexdigest()[:12]
index, vendor_count = re.subn(r'src="/?assets/vendor\.js(?:\?[^"\s]*)?"', f'src="/assets/vendor.js?mapa-layout={vendor_version}"', index)
if vendor_count != 1:
    raise SystemExit('Expected one native vendor script.')
engine_path = '/engines-dist/@fleetbase/fleetops-engine/assets/engine.js'
engine_version = hashlib.sha256((console / engine_path.lstrip('/')).read_bytes()).hexdigest()[:12]

def refresh_manifest(value):
    assets = value['bundles']['@fleetbase/fleetops-engine']['assets']
    selected = [asset for asset in assets if asset.get('uri', '').split('?')[0] == engine_path]
    if len(selected) != 1:
        raise ValueError('Expected one native fleet-operations engine asset.')
    selected[0]['uri'] = engine_path + '?mapa-layout=' + engine_version
    return value

def refresh_meta(match):
    value = refresh_manifest(json.loads(unquote(match.group(2))))
    return match.group(1) + quote(json.dumps(value, separators=(',', ':')), safe='/') + match.group(3)

index, count = re.subn(r'(<meta name="@fleetbase/console/config/asset-manifest" content=")([^"\s]+)("\s*/>)', refresh_meta, index)
if count != 1:
    raise SystemExit('Missing native asset manifest; no index changed.')
manifest_path = console / 'asset-manifest.json'
manifest = refresh_manifest(json.loads(manifest_path.read_text(encoding='utf-8')))
manifest_path.write_text(json.dumps(manifest, separators=(',', ':')) + '\n', encoding='utf-8')
index_path.write_text(index, encoding='utf-8')
print('Native create-call header wraps; OSM credit stays bottom-left inside the map; affected asset versions refreshed.')

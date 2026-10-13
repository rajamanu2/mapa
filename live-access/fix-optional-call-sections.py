"""Collapse optional native new-call sections without changing save requirements."""
import argparse
import hashlib
import json
from pathlib import Path
import re
from urllib.parse import quote, unquote

ROOT = Path(__file__).resolve().parents[2]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--console-root', type=Path, default=ROOT / 'private/live-access/console')
console = parser.parse_args().console_root.resolve()
uri = '/engines-dist/@fleetbase/fleetops-engine/assets/engine.js'
file = console / uri.lstrip('/')
text = file.read_text(encoding='utf-8')
for name, note in [
    ('documents', 'Documents are optional. You can create this call and assign crew without uploading files.'),
    ('orchestrator-constraints', 'These scheduling settings are optional. You can create this call and assign crew without adding constraints.'),
]:
    module = '@fleetbase/fleetops-engine/components/order/form/' + name
    marker = 'define(' + json.dumps(module) + ','
    if text.count(marker) != 1:
        raise ValueError('Expected one native component: ' + module)
    start = text.index(marker)
    end = text.find('\ndefine(', start + len(marker))
    if end < 0:
        end = len(text)
    section = text[start:end]
    match = re.search(r'"block":\s*("(?:\\.|[^"\\])*")', section)
    if not match:
        raise ValueError('Missing native component template.')
    block = json.loads(json.loads(match.group(1)))
    panel = block[0][0]
    if panel[:2] != [8, [39, block[3].index('content-panel')]]:
        raise ValueError('Unexpected native content panel.')
    names, values = panel[3]
    condition = [30, block[1].index('@resource') + 1, ['isNew']]
    title = values[names.index('@title')]
    if isinstance(title, list) and title[:2] == [52, condition]:
        title = title[3]
    if 'concat' not in block[3]:
        block[3].append('concat')
    optional = [28, [37, block[3].index('concat')], [title, ' (optional)'], None]
    values[names.index('@title')] = [52, condition, optional, title]
    values[names.index('@open')] = [52, condition, False, True]
    if note not in json.dumps(panel[4]):
        hint = [41, condition, [[[10, 'p'], [14, 0, 'text-sm mb-3 opacity-80'], [12], [1, note], [13]], []], None]
        panel[4][1][panel[4][0].index('default')][0].insert(0, hint)
    encoded = json.dumps(json.dumps(block, separators=(',', ':')), ensure_ascii=False)
    section = section[:match.start(1)] + encoded + section[match.end(1):]
    # Keep the embedded template source accurate for the source offer/debugging.
    hbs = (ROOT / 'fleetops-source/addon/components/order/form' / (name + '.hbs')).read_text(encoding='utf-8')
    source_match = re.search(r'/\*\s*<ContentPanel[\s\S]*?\*/', section)
    if not source_match:
        raise ValueError('Missing native template source comment.')
    section = section[:source_match.start()] + '/*\n' + hbs + '\n  */' + section[source_match.end():]
    text = text[:start] + section + text[end:]

version = hashlib.sha256(text.encode('utf-8')).hexdigest()[:12]
def refresh(value):
    assets = value['bundles']['@fleetbase/fleetops-engine']['assets']
    found = [asset for asset in assets if asset.get('uri', '').split('?')[0] == uri]
    if len(found) != 1:
        raise ValueError('Expected one native operations engine asset.')
    found[0]['uri'] = uri + '?mapa-optional=' + version
    return value

index_path = console / 'index.html'
index = index_path.read_text(encoding='utf-8')
def meta(match):
    return match.group(1) + quote(json.dumps(refresh(json.loads(unquote(match.group(2)))), separators=(',', ':')), safe='/') + match.group(3)
index, count = re.subn(r'(<meta name="@fleetbase/console/config/asset-manifest" content=")([^"\s]+)("\s*/>)', meta, index)
if count != 1:
    raise ValueError('Expected one console asset manifest.')
manifest_path = console / 'asset-manifest.json'
manifest = refresh(json.loads(manifest_path.read_text(encoding='utf-8')))
file.write_text(text, encoding='utf-8')
manifest_path.write_text(json.dumps(manifest, separators=(',', ':')) + '\n', encoding='utf-8')
index_path.write_text(index, encoding='utf-8')
print('Optional new-call sections installed; native save and assignment rules unchanged:', version)

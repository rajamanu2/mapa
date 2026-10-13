"""Install the native MAPA caller contact field in a copied console build.

Patches native create/edit/display components, safe edit hydration/cancellation,
the host component re-export and affected asset versions. Native permissions,
save tasks and unrelated metadata stay intact.
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
from urllib.parse import quote, unquote
from caller_phone_edit import patch_saved_call

ROOT = Path(__file__).resolve().parents[2]
SCRIPTS = ROOT / 'fleetbase/console/scripts'
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--console-root', type=Path, default=ROOT / 'private/live-access/console')
args = parser.parse_args()
console = args.console_root.resolve()
engine_uri = '/engines-dist/@fleetbase/fleetops-engine/assets/engine.js'
engine_path = console / engine_uri.lstrip('/')
text = engine_path.read_text(encoding='utf-8')
marker = 'define("@fleetbase/fleetops-engine/components/order/form/details",'
if text.count(marker) != 1:
    raise SystemExit('Expected one native order Details component.')
start = text.index(marker)
end = text.find('\ndefine(', start + len(marker))
if end < 0:
    raise SystemExit('Missing native component boundary.')
section = text[start:end]
match = re.search(r'"block":\s*("(?:\\.|[^"\\])*")', section)
if not match:
    raise SystemExit('Missing Details template block.')
block = json.loads(json.loads(match.group(1)))

def shared(name):
    if name not in block[3]:
        block[3].append(name)
    return block[3].index(name)

if 'mapaCallerPhoneId' not in block[1]:
    block[1].append('mapaCallerPhoneId')
    local = len(block[1])
    resource = block[1].index('@resource') + 1
    input_group, cannot_write, on = shared('input-group'), shared('cannot-write'), shared('on')
    # Same Glimmer operations as the adjacent native inputs, preserving scope.
    body = [
        [11, 'input'], [16, 'id', [30, local]], [24, 0, 'w-full form-input'],
        [24, 4, 'tel'], [16, 2, [30, 0, ['mapaCallerPhone']]],
        [24, 'placeholder', '+91 98765 43210'], [24, 'inputmode', 'tel'],
        [24, 'autocomplete', 'tel'], [24, 'maxlength', '40'],
        [16, 'disabled', [28, [37, cannot_write], [[30, resource]], None]],
        [16, 'aria-invalid', [52, [30, 0, ['mapaCallerPhoneError']], 'true', 'false']],
        [4, [38, on], ['input', [30, 0, ['setMapaCallerPhone']]], None], [12], [13],
        [10, 'p'], [14, 0, 'mt-1 text-sm opacity-80'], [12],
        [1, 'Optional if unknown. Shared with the assigned crew for coordination. For India, enter 10 digits or +91 and the number.'], [13],
        [41, [30, 0, ['mapaCallerPhoneError']], [[
            [10, 'p'], [14, 0, 'mt-1 text-sm text-red-600 dark:text-red-300'], [14, 'role', 'alert'], [12],
            [1, [30, 0, ['mapaCallerPhoneError']]], [13]
        ], []], None],
    ]
    field = [8, [39, input_group], None,
             [['@name', '@wrapperClass'], ['Caller phone number', 'lg:col-span-2']],
             [['default'], [[body, [local]]]]]
    statements = block[0][0][4][1][0][0]
    candidates = [i for i, node in enumerate(statements) if isinstance(node, list) and len(node) > 3
                  and node[0:2] == [8, [39, input_group]] and node[3]
                  and [30, resource, ['internal_id']] in node[3][1]]
    if len(candidates) != 1:
        raise SystemExit('Expected one adjacent native internal-id input.')
    statements.insert(candidates[0], field)

# Disable editing until native Details has replaced a lightweight index resource.
for node in block[0][0][4][1][0][0]:
    if isinstance(node, list) and len(node) > 3 and node[0] == 8 and node[3] and 'Caller phone number' in node[3][1]:
        resource = block[1].index('@resource') + 1
        for operation in node[4][1][0][0]:
            if operation[:2] == [16, 'disabled']:
                operation[2] = [28, [37, shared('or')], [[30, resource, ['meta', '_index_resource']], [28, [37, shared('cannot-write')], [[30, resource]], None]], None]

encoded = json.dumps(json.dumps(block, separators=(',', ':')), ensure_ascii=False)
section = section[:match.start(1)] + encoded + section[match.end(1):]
field_source = (SCRIPTS / 'mapa-caller-phone-field.hbs').read_text(encoding='utf-8')
if '<InputGroup @name="Caller phone number"' not in section:
    anchor = '<InputGroup @name={{t "order.fields.internal-id"}}'
    if section.count(anchor) != 1:
        raise SystemExit('Missing Details source-comment input anchor.')
    section = section.replace(anchor, field_source + '\n          ' + anchor, 1)
else:
    a = section.index('<InputGroup @name="Caller phone number"')
    b = section.index('</InputGroup>', a) + len('</InputGroup>')
    section = section[:a] + field_source.strip() + section[b:]

methods = (SCRIPTS / 'mapa-caller-phone-methods.js.txt').read_text(encoding='utf-8').replace('@action ', '')
method_marker = '    // MAPA caller contact is operator-supplied coordination information.'
method_end = '    get integratedVendorServiceType() {'
if section.count(method_end) != 1:
    raise SystemExit('Missing Details method anchor.')
if method_marker in section:
    a = section.index(method_marker)
    b = section.index(method_end, a)
    section = section[:a] + methods + section[b:]
else:
    section = section.replace(method_end, methods + method_end, 1)
decorator = '_applyDecoratedDescriptor(_class.prototype, "setMapaCallerPhone", [_object.action], Object.getOwnPropertyDescriptor(_class.prototype, "setMapaCallerPhone"), _class.prototype), '
if decorator not in section:
    anchor = '_applyDecoratedDescriptor(_class.prototype, "selectFacilitator",'
    if section.count(anchor) != 1:
        raise SystemExit('Missing action decorator anchor.')
    section = section.replace(anchor, decorator + anchor, 1)
text = text[:start] + section + text[end:]
text = patch_saved_call(text, SCRIPTS, block)
version = hashlib.sha256(text.encode('utf-8')).hexdigest()[:12]
# Native shared modals render under the host application's owner. Mirror the
# existing addon app re-exports so its resolver can find the reusable field.
console_path = console / 'assets/@fleetbase/console.js'
console_text = console_path.read_text(encoding='utf-8')
app_marker = 'define("@fleetbase/console/components/order/caller-phone",'
reexport = ''';define("@fleetbase/console/components/order/caller-phone", ["exports", "@fleetbase/fleetops-engine/components/order/caller-phone"], function (_exports, _callerPhone) {
  "use strict";
  Object.defineProperty(_exports, "__esModule", {value: true});
  Object.defineProperty(_exports, "default", {enumerable: true, get: function () {return _callerPhone.default;}});
});
'''
if app_marker not in console_text:
    console_text = console_text.rstrip() + '\n' + reexport
elif console_text.count(app_marker) != 1:
    raise SystemExit('Expected a unique host caller-phone re-export.')
console_version = hashlib.sha256(console_text.encode('utf-8')).hexdigest()[:12]

def refresh_manifest(value):
    assets = value['bundles']['@fleetbase/fleetops-engine']['assets']
    selected = [asset for asset in assets if asset.get('uri', '').split('?')[0] == engine_uri]
    if len(selected) != 1:
        raise ValueError('Expected one fleet-operations engine asset.')
    selected[0]['uri'] = engine_uri + '?mapa-contact=' + version
    return value

index_path = console / 'index.html'
index = index_path.read_text(encoding='utf-8')
index, console_count = re.subn(r'src="/?assets/@fleetbase/console\.js(?:\?[^"\s]*)?"', f'src="/assets/@fleetbase/console.js?mapa-contact={console_version}"', index)
if console_count != 1:
    raise SystemExit('Expected one native host console script; no files changed.')
def refresh_meta(match):
    value = refresh_manifest(json.loads(unquote(match.group(2))))
    return match.group(1) + quote(json.dumps(value, separators=(',', ':')), safe='/') + match.group(3)
index, count = re.subn(r'(<meta name="@fleetbase/console/config/asset-manifest" content=")([^"\s]+)("\s*/>)', refresh_meta, index)
if count != 1:
    raise SystemExit('Missing console asset manifest; no files changed.')
manifest_path = console / 'asset-manifest.json'
manifest = refresh_manifest(json.loads(manifest_path.read_text(encoding='utf-8')))
engine_path.write_text(text, encoding='utf-8')
console_path.write_text(console_text, encoding='utf-8')
manifest_path.write_text(json.dumps(manifest, separators=(',', ':')) + '\n', encoding='utf-8')
index_path.write_text(index, encoding='utf-8')
print('Native caller phone field and engine cache version installed:', version)

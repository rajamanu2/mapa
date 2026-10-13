"""Generate a file-only ASR Health presentation overlay from a console snapshot.

This prepares separate output files. It never writes to the input console, calls
Docker, changes a database, reloads a service or deploys anything. The returned
manifest binds every replacement to the exact input bytes that were inspected.
"""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import re

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
PUBLIC = ROOT / 'fleetbase/console/public'
APP_MODULES = [
    '@fleetbase/console/components/logo',
    '@fleetbase/console/components/logo-icon',
    '@fleetbase/console/components/onboarding/form',
    '@fleetbase/console/templates/invite/for-user',
    '@fleetbase/console/templates/console/admin/branding',
    '@fleetbase/console/templates/install',
    '@fleetbase/console/controllers/console/admin/branding',
]
VENDOR_MODULES = ['@fleetbase/ember-ui/components/logo-icon']
OUTPUT_ALLOWLIST = frozenset([
    'index.html', 'assets/@fleetbase/console.js', 'assets/vendor.js',
    'mapa-loading.css', 'mapa-loading.js', 'mapa-icon.svg',
    'images/asr-health-icon.svg', 'images/asr-health-logo.svg',
    'images/mapa-logo.svg', 'images/icon.svg', 'images/fleetbase-logo-svg.svg',
    'favicon/site.webmanifest',
])


def load(name):
    spec = importlib.util.spec_from_file_location(name.replace('-', '_'), HERE / (name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def sha(value):
    return hashlib.sha256(value).hexdigest()


def safe_path(root, relative):
    path = root / relative
    if not path.resolve().is_relative_to(root):
        raise ValueError('Input or output path escapes its selected directory: ' + relative)
    return path


def prepare(console, output):
    console = console.resolve(strict=True)
    output = output.resolve()
    if not console.is_dir() or output == console or output.is_relative_to(console) or console.is_relative_to(output):
        raise ValueError('Choose a separate output directory outside the console snapshot.')
    if output.exists() and (not output.is_dir() or any(output.iterdir())):
        raise ValueError('Output directory must be absent or empty; no existing files are overwritten.')
    brand, loading = load('fix-entry-branding'), load('install-loading-assets')
    originals = {}

    def read(relative, required=True):
        path = safe_path(console, relative)
        if not path.is_file():
            if required:
                raise ValueError('Missing expected input file: ' + relative)
            originals[relative] = None
            return None
        originals[relative] = path.read_bytes()
        return originals[relative]

    def module(text, name):
        start, end = brand.module_bounds(text, name)
        return text[start:end]

    def masked(text, names):
        spans = [(*brand.module_bounds(text, name), name) for name in names]
        for start, end, name in sorted(spans, reverse=True):
            text = text[:start] + '<ALLOWED-MODULE:' + name + '>' + text[end:]
        return text

    original_app = read('assets/@fleetbase/console.js').decode('utf-8')
    original_vendor = read('assets/vendor.js').decode('utf-8')
    original_index = read('index.html').decode('utf-8')
    original_manifest = json.loads(read('favicon/site.webmanifest').decode('utf-8'))
    attribution_name = '@fleetbase/console/components/mapa-attribution'
    attribution_before = module(original_app, attribution_name)
    if 'MAPA.DEV' not in attribution_before:
        raise ValueError('Expected MAPA.DEV attribution was not found; no overlay generated.')
    app, vendor = original_app, original_vendor
    for name in APP_MODULES[2:6]:
        app = brand.patch_template(app, name, brand.LOGO_PATHS)
    app = brand.patch_controller(app, APP_MODULES[-1])
    app = brand.patch_operator_logo(brand.patch_operator_logo(app), icon=True)
    vendor = brand.patch_template(vendor, VENDOR_MODULES[0],
                                  {**brand.LOGO_PATHS, 'Fleetbase': 'ASR Health', 'MAPA': 'ASR Health'}, True)
    if masked(app, APP_MODULES) != masked(original_app, APP_MODULES):
        raise ValueError('A change escaped the allowed first-party branding modules.')
    if masked(vendor, VENDOR_MODULES) != masked(original_vendor, VENDOR_MODULES):
        raise ValueError('A change escaped the allowed shared logo module.')
    if module(app, attribution_name) != attribution_before:
        raise ValueError('MAPA.DEV attribution changed; no overlay generated.')
    index = original_index.replace('href="/mapa-icon.svg"', 'href="' + brand.ICON_PATH + '"')
    for name, content in [('vendor.js', vendor), ('@fleetbase/console.js', app)]:
        pattern = r'(?P<base>src="/assets/' + re.escape(name) + r')(?:\?[^"\s]*)?(?P<close>")'
        index, count = re.subn(pattern, lambda m: m['base'] + '?mapa-brand=' + sha(content.encode())[:12] + m['close'], index)
        if count != 1:
            raise ValueError('Expected exactly one native bundle reference: ' + name)
    index, loading_assets, version = loading.prepare(index)
    script_paths = lambda html: re.findall(r'<script\b[^>]*\bsrc="([^"?]+)', html)
    if script_paths(index) != script_paths(original_index):
        raise ValueError('Native script or initializer order changed; no overlay generated.')
    begin, end = loading.boot_bounds(index)
    splash = index[begin:end]
    if 'ASR HEALTH' not in splash or re.search(r'>[^<]*MAPA[^<]*<', splash):
        raise ValueError('Startup splash does not have the intended ASR Health presentation.')
    manifest_value = dict(original_manifest)
    manifest_value.update(name='ASR Health', short_name='ASR Health', theme_color='#0d9488', background_color='#111827',
                          icons=[{'src': brand.ICON_PATH, 'sizes': 'any', 'type': 'image/svg+xml'}])
    candidates = {
        'index.html': index.encode(), 'assets/@fleetbase/console.js': app.encode(),
        'assets/vendor.js': vendor.encode(), **loading_assets,
        'mapa-icon.svg': brand.ICON.encode(), 'images/icon.svg': brand.ICON.encode(),
        'images/asr-health-icon.svg': brand.ICON.encode(), 'images/asr-health-logo.svg': brand.LOGO.encode(),
        'images/mapa-logo.svg': brand.LOGO.encode(), 'images/fleetbase-logo-svg.svg': brand.LOGO.encode(),
        'favicon/site.webmanifest': (json.dumps(manifest_value, indent=2) + '\n').encode(),
    }
    if set(candidates) != OUTPUT_ALLOWLIST:
        raise ValueError('Unexpected output file set.')
    for relative in candidates:
        if relative not in originals:
            read(relative, required=False)
    # Immutable input hashes are rechecked after all preparation, before output.
    for relative, before in originals.items():
        path = safe_path(console, relative)
        now = path.read_bytes() if path.is_file() else None
        if now != before:
            raise ValueError('Console snapshot changed during preparation: ' + relative)
    changes = {name: content for name, content in candidates.items() if originals[name] != content}
    record = {
        'schema': 'asr-branding-file-overlay-v1', 'input_console': str(console),
        'loading_version': version,
        'files': [{'path': name, 'existed_before': originals[name] is not None,
                   'before_sha256': sha(originals[name]) if originals[name] is not None else None,
                   'sha256': sha(content), 'bytes': len(content)} for name, content in changes.items()],
        'allowed_files': sorted(OUTPUT_ALLOWLIST),
        'checks': ['non-branding AMD bytes unchanged', 'MAPA.DEV attribution module unchanged',
                   'native script and initializer order unchanged', 'input snapshot unchanged'],
        'unchanged_attribution_sha256': sha(attribution_before.encode()),
        'modules': [
            {'bundle': bundle, 'name': name, 'before_sha256': sha(module(before, name).encode()),
             'sha256': sha(module(after, name).encode())}
            for bundle, before, after, names in [
                ('assets/@fleetbase/console.js', original_app, app, APP_MODULES),
                ('assets/vendor.js', original_vendor, vendor, VENDOR_MODULES)]
            for name in names
        ],
    }
    output.mkdir(parents=True, exist_ok=True)
    for relative, content in changes.items():
        destination = safe_path(output, relative)
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_bytes(content)
    (output / 'manifest.json').write_text(json.dumps(record, indent=2) + '\n', encoding='utf-8')
    return record


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--console-root', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    result = prepare(args.console_root, args.output)
    print(json.dumps({'prepared_files': len(result['files']), 'loading_version': result['loading_version'],
                      'manifest': str(args.output.resolve() / 'manifest.json')}, indent=2))

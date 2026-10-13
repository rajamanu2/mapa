"""Rename AI product labels in the selected exported console, preserving behavior.

Only five known AI UI modules and the AI engine's cache URI are changed. Provider
configuration, technical module names, licences and other asset versions stay intact.
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
from urllib.parse import quote, unquote

ROOT = Path(__file__).resolve().parents[2]
ENGINE = 'engines-dist/@fleetbase/ai-engine/assets/engine.js'
MODULES = [
    '@fleetbase/ai-engine/components/admin/ai-settings',
    '@fleetbase/ai-engine/components/ai-action-preview-renderer',
    '@fleetbase/ai-engine/components/ai-header-tray-button',
    '@fleetbase/ai-engine/components/ai-prompt',
    '@fleetbase/ai-engine/templates/home',
]


def patch_engine(original):
    text = original
    for name in MODULES:
        start_match = list(re.finditer(r'(?:^|\n)define\(' + re.escape(json.dumps(name)) + ',', text))
        if len(start_match) != 1:
            raise ValueError('Expected exactly one reviewed AI UI module: ' + name)
        start = start_match[0].start()
        next_module = re.search(r'\ndefine\(', text[start_match[0].end():])
        end = start_match[0].end() + next_module.start() if next_module else len(text)
        section = text[start:end]
        if 'Fleetbase AI' not in section and 'MAPA AI' not in section:
            raise ValueError('Expected AI product label missing: ' + name)
        updated = section.replace('Fleetbase AI', 'MAPA AI')
        text = text[:start] + updated + text[end:]
    if 'Fleetbase AI' in text or 'FLEETBASE AI' in text:
        raise ValueError('Unreviewed AI product label remains; refusing a partial rename.')
    for technical in ['@fleetbase/ai-engine', 'fleetbase-ai-', 'config.enabled', 'providers', 'api_key']:
        if text.count(technical) != original.count(technical):
            raise ValueError('Unexpected technical identifier change: ' + technical)
    return text


def update_manifest(manifest, version):
    assets = manifest['bundles']['@fleetbase/ai-engine']['assets']
    selected = [asset for asset in assets if asset.get('uri', '').split('?')[0] == '/' + ENGINE]
    if len(selected) != 1:
        raise ValueError('Expected exactly one AI engine JS asset in manifest.')
    selected[0]['uri'] = '/' + ENGINE + '?mapa-ai=' + version
    return manifest


def prepare(console):
    engine = patch_engine((console / ENGINE).read_text(encoding='utf-8'))
    version = hashlib.sha256(engine.encode()).hexdigest()[:12]
    manifest = update_manifest(json.loads((console / 'asset-manifest.json').read_text(encoding='utf-8')), version)
    index = (console / 'index.html').read_text(encoding='utf-8')
    pattern = r'(<meta name="@fleetbase/console/config/asset-manifest" content=")([^"\s]+)("\s*/?>)'

    def replace_meta(match):
        data = update_manifest(json.loads(unquote(match.group(2))), version)
        return match.group(1) + quote(json.dumps(data, separators=(',', ':')), safe='/') + match.group(3)

    index, count = re.subn(pattern, replace_meta, index)
    if count != 1:
        raise ValueError('Expected one native asset-manifest meta tag.')
    return engine, json.dumps(manifest, separators=(',', ':')) + '\n', index, version


def apply(console):
    engine, manifest, index, version = prepare(console)
    # Every anchor and technical identifier is checked before any write.
    for relative, content in [(ENGINE, engine), ('asset-manifest.json', manifest), ('index.html', index)]:
        (console / relative).write_text(content, encoding='utf-8', newline='\n')
    print('MAPA AI settings, enable label, prompt and accessibility labels applied; AI cache version ' + version + '.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--console-root', type=Path, default=ROOT / 'private/live-access/console')
    args = parser.parse_args()
    apply(args.console_root.resolve(strict=True))

"""Patch reviewed admin/navigation display labels, preserving routes and config."""
import argparse
import hashlib
import json
from pathlib import Path
import re
from urllib.parse import quote, unquote

ROOT = Path(__file__).resolve().parents[2]
APP = 'assets/@fleetbase/console.js'
VENDOR = 'assets/vendor.js'
ENGINE = 'engines-dist/@fleetbase/fleetops-engine/assets/engine.js'
RULES = {
    APP: {'@fleetbase/console/extensions/fleet-ops': [('Fleet-Ops Config', 'MAPA Operations Config')]},
    VENDOR: {
        '@fleetbase/ember-ui/components/layout/header': [('Fleetbase Documentation', 'Documentation')],
        '@fleetbase/ember-ui/components/docs-panel': [('Open MAPA documentation', 'Open documentation'), ('Open Fleetbase documentation', 'Open documentation')],
    },
    ENGINE: {'@fleetbase/fleetops-engine/extension': [('Fleet-Ops Config', 'MAPA Operations Config')]},
}


def patch(text, modules):
    original = text
    for name, replacements in modules.items():
        matches = list(re.finditer(r'(?:^|[;\n])define\(' + re.escape(json.dumps(name)) + ',', text))
        if len(matches) != 1:
            raise ValueError('Expected exactly one reviewed admin module: ' + name)
        start = matches[0].start()
        following = re.search(r'[;\n]define\(', text[matches[0].end():])
        end = matches[0].end() + following.start() if following else len(text)
        section = text[start:end]
        for old, new in replacements:
            if old not in section and new not in section:
                raise ValueError('Missing reviewed display label in ' + name)
            section = section.replace(old, new)
        text = text[:start] + section + text[end:]
    for technical in ('@fleetbase/', 'fleet-ops', 'https://www.fleetbase.io/docs', 'https://github.com/fleetbase/fleetbase/issues'):
        if text.count(technical) != original.count(technical):
            raise ValueError('Technical identifier or upstream destination changed: ' + technical)
    return text


def update_manifest(manifest, version):
    assets = manifest['bundles']['@fleetbase/fleetops-engine']['assets']
    matches = [asset for asset in assets if asset.get('uri', '').split('?')[0] == '/' + ENGINE]
    if len(matches) != 1:
        raise ValueError('Expected one native FleetOps JavaScript URI.')
    matches[0]['uri'] = '/' + ENGINE + '?mapa-admin=' + version
    return manifest


def prepare(console):
    outputs = {relative: patch((console / relative).read_text(encoding='utf-8'), modules) for relative, modules in RULES.items()}
    versions = {relative: hashlib.sha256(content.encode()).hexdigest()[:12] for relative, content in outputs.items()}
    manifest = update_manifest(json.loads((console / 'asset-manifest.json').read_text(encoding='utf-8')), versions[ENGINE])
    index = (console / 'index.html').read_text(encoding='utf-8')
    for asset in (APP, VENDOR):
        pattern = r'(src="/' + re.escape(asset) + r')(?:\?[^"\s]*)?("\s*>)'
        index, count = re.subn(pattern, lambda m: m.group(1) + '?mapa-admin=' + versions[asset] + m.group(2), index)
        if count != 1:
            raise ValueError('Expected exactly one script URI for ' + asset)
    pattern = r'(<meta name="@fleetbase/console/config/asset-manifest" content=")([^"\s]+)("\s*/?>)'

    def replace_meta(match):
        value = update_manifest(json.loads(unquote(match.group(2))), versions[ENGINE])
        return match.group(1) + quote(json.dumps(value, separators=(',', ':')), safe='/') + match.group(3)

    index, count = re.subn(pattern, replace_meta, index)
    if count != 1:
        raise ValueError('Expected one native manifest meta tag.')
    outputs['index.html'] = index
    outputs['asset-manifest.json'] = json.dumps(manifest, separators=(',', ':')) + '\n'
    return outputs, versions


def apply(console):
    outputs, versions = prepare(console)
    for relative, content in outputs.items():
        (console / relative).write_text(content, encoding='utf-8', newline='\n')
    print('MAPA Operations Config and neutral Documentation labels applied; routes and upstream destinations unchanged.')
    print('Asset versions:', json.dumps(versions, separators=(',', ':')))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--console-root', type=Path, default=ROOT / 'private/live-access/console')
    args = parser.parse_args()
    apply(args.console_root.resolve(strict=True))

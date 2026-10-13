"""Finish reviewed MAPA UI copy in invitations, webhooks, IAM, maps and invoices.

Only display values change. Module names, translation keys, policy data keys,
provider credentials, external documentation, third-party notices and routes remain.
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
import runpy
from urllib.parse import quote, unquote

ROOT = Path(__file__).resolve().parents[2]
COMMON = runpy.run_path(str(Path(__file__).with_name('fix-admin-branding.py')))
VENDOR = 'assets/vendor.js'
RULES = {
    '@fleetbase/fleetops-engine': {
        '@fleetbase/fleetops-engine/controllers/connectivity/telematics/details/attachments': [('Fleetbase could not load the full provider device list.', 'MAPA could not load the full provider device list.')],
        '@fleetbase/fleetops-engine/utils/fleet-ops-options': [('Vendor with native API integration into Fleetbase', 'Vendor with native API integration into MAPA')],
    },
    '@fleetbase/iam-engine': {
        '@fleetbase/iam-engine/components/widget/policy-surface': [('Fleetbase', 'MAPA')],
    },
    '@fleetbase/ledger-engine': {
        '@fleetbase/ledger-engine/components/customer-invoice': [('Fleetbase Ledger', 'MAPA Ledger')],
        '@fleetbase/ledger-engine/components/modals/refund-result': [('Send this Fleetbase link to the customer.', 'Send this MAPA link to the customer.')],
    },
}
# These groups are product UI copy, not source docs or licensing records.
GROUPS = ('developers', 'iam', 'settings', 'storefront', 'trailer', 'widget',
          'fleet-ops', 'integrated-vendor', 'modals', 'order', 'order-config-manager',
          'app', 'auth', 'onboard', 'install', 'invite')


def rename(value):
    if isinstance(value, dict):
        return {key: rename(child) for key, child in value.items()}
    if isinstance(value, list):
        return [rename(child) for child in value]
    if isinstance(value, str):
        return value.replace('Fleetbase', 'MAPA').replace('Fleet-Ops', 'MAPA Operations').replace('MAPA CLI', 'application CLI')
    return value


def patch_translations(text):
    marker = ';define("ember-intl/translations",'
    if text.count(marker) != 1:
        raise ValueError('Expected one native translation catalog.')
    start = text.index(marker)
    end = text.find(';define(', start + len(marker))
    section = text[start:end if end >= 0 else len(text)]
    assignment = '_exports.default='
    at = section.index(assignment, section.index(assignment) + len(assignment)) + len(assignment)
    catalog, consumed = json.JSONDecoder().raw_decode(section[at:])
    for language, labels in catalog:
        for group in GROUPS:
            if group in labels:
                labels[group] = rename(labels[group])
        # Self-hosted instance copy; preserve CLI/token names and real registry names.
        registry = labels.get('registry-bridge', {})
        for path in [('component', 'extension-details-modal', 'self-managed-help-text'),
                     ('developers', 'extensions', 'extension-form', 'self-managed-help-text')]:
            node = registry
            for key in path[:-1]:
                node = node.get(key, {})
            if path[-1] in node:
                node[path[-1]] = rename(node[path[-1]])
    section = section[:at] + json.dumps(catalog, ensure_ascii=False, separators=(',', ':')) + section[at + consumed:]
    return text[:start] + section + text[end if end >= 0 else len(text):]


def prepare(console):
    outputs = {VENDOR: patch_translations((console / VENDOR).read_text(encoding='utf-8'))}
    engines = {}
    for package, rules in RULES.items():
        relative = f'engines-dist/{package}/assets/engine.js'
        outputs[relative] = COMMON['patch']((console / relative).read_text(encoding='utf-8'), rules)
        engines[package] = relative
    versions = {relative: hashlib.sha256(text.encode()).hexdigest()[:12] for relative, text in outputs.items()}

    def update(manifest):
        for package, relative in engines.items():
            assets = manifest['bundles'][package]['assets']
            found = [asset for asset in assets if asset.get('uri', '').split('?')[0] == '/' + relative]
            if len(found) != 1:
                raise ValueError('Expected one reviewed engine asset: ' + package)
            found[0]['uri'] = '/' + relative + '?mapa-product=' + versions[relative]
        return manifest

    manifest = update(json.loads((console / 'asset-manifest.json').read_text(encoding='utf-8')))
    index = (console / 'index.html').read_text(encoding='utf-8')
    index, count = re.subn(r'(src="/assets/vendor.js)(?:\?[^"\s]*)?("\s*>)',
                          lambda m: m.group(1) + '?mapa-product=' + versions[VENDOR] + m.group(2), index)
    if count != 1:
        raise ValueError('Expected one native vendor script.')
    pattern = r'(<meta name="@fleetbase/console/config/asset-manifest" content=")([^"\s]+)("\s*/?>)'

    def replace_meta(match):
        value = update(json.loads(unquote(match.group(2))))
        return match.group(1) + quote(json.dumps(value, separators=(',', ':')), safe='/') + match.group(3)

    index, count = re.subn(pattern, replace_meta, index)
    if count != 1:
        raise ValueError('Expected one native manifest meta tag.')
    outputs['asset-manifest.json'] = json.dumps(manifest, separators=(',', ':')) + '\n'
    outputs['index.html'] = index
    return outputs, versions


def apply(console):
    outputs, versions = prepare(console)
    for relative, text in outputs.items():
        (console / relative).write_text(text, encoding='utf-8', newline='\n')
    print('MAPA product labels applied across product translations, IAM stats, operational errors and invoice/refund UI.')
    print('Asset versions:', json.dumps(versions, separators=(',', ':')))


def apply_translations(console):
    text = patch_translations((console / VENDOR).read_text(encoding='utf-8'))
    version = hashlib.sha256(text.encode()).hexdigest()[:12]
    index = (console / 'index.html').read_text(encoding='utf-8')
    index, count = re.subn(r'(src="/assets/vendor.js)(?:\?[^"\s]*)?("\s*>)',
                          lambda m: m.group(1) + '?mapa-product=' + version + m.group(2), index)
    if count != 1:
        raise ValueError('Expected one native vendor script.')
    (console / VENDOR).write_text(text, encoding='utf-8', newline='\n')
    (console / 'index.html').write_text(index, encoding='utf-8', newline='\n')
    print('MAPA translation values applied without engine/manifest changes; vendor version ' + version + '.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--console-root', type=Path, default=ROOT / 'private/live-access/console')
    parser.add_argument('--translations-only', action='store_true')
    args = parser.parse_args()
    (apply_translations if args.translations_only else apply)(args.console_root.resolve(strict=True))

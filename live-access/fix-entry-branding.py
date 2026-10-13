"""Apply MAPA product branding to the exported native console, without DOM rewriting.

This changes explicitly named product templates/assets only. Dependency names,
source copyright comments, license notices, organization records and auth stay intact.
Run after runtime-config.mjs and the account-recovery preparation step.
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
from urllib.parse import quote, unquote

ROOT = Path(__file__).resolve().parents[2]
PUBLIC = ROOT / 'fleetbase/console/public'
ICON_PATH = '/images/asr-health-icon.svg'
LOGO_PATH = '/images/asr-health-logo.svg'
ICON = (PUBLIC / ICON_PATH.lstrip('/')).read_text(encoding='utf-8')
LOGO = (PUBLIC / LOGO_PATH.lstrip('/')).read_text(encoding='utf-8')
LOGO_PATHS = {
    '/images/fleetbase-logo-svg.svg': LOGO_PATH,
    '/images/mapa-logo.svg': LOGO_PATH,
    '/images/icon.png': ICON_PATH,
    '/mapa-icon.svg': ICON_PATH,
}


def module_bounds(text, name):
    marker = ';define(' + json.dumps(name) + ','
    if text.count(marker) != 1:
        raise ValueError(f'Expected one native module: {name}')
    start = text.index(marker)
    end = text.find(';define(', start + len(marker))
    return start, len(text) if end < 0 else end


def replace_strings(value, mapping):
    if isinstance(value, str):
        return mapping.get(value, value)
    if isinstance(value, list):
        return [replace_strings(child, mapping) for child in value]
    return value


def patch_template(text, name, mapping, force_product_icon=False):
    start, end = module_bounds(text, name)
    section = text[start:end]
    match = re.search(r'"block":\s*("(?:\\.|[^"\\])*")', section)
    if not match:
        raise ValueError(f'Missing native template block: {name}')
    block = json.loads(json.loads(match.group(1)))
    block = replace_strings(block, mapping)
    if force_product_icon:
        old = [16, 'src', [30, 0, ['brand', 'icon_url']]]
        new = [24, 'src', ICON_PATH]
        found = []

        def replace_icon(value):
            if value == old or value == new or value == [24, 'src', '/mapa-icon.svg']:
                found.append(True)
                return new
            if isinstance(value, list):
                return [replace_icon(child) for child in value]
            return value

        block = replace_icon(block)
        if len(found) != 1:
            raise ValueError('Unexpected shared logo-icon source; refusing unsafe patch')
    encoded = json.dumps(json.dumps(block, separators=(',', ':')), ensure_ascii=False)
    section = section[:match.start(1)] + encoded + section[match.end(1):]
    # Match the embedded source comment to the executable template for review.
    for old, new in mapping.items():
        section = section.replace(old, new)
    if force_product_icon:
        section = section.replace('src={{this.brand.icon_url}}', 'src="' + ICON_PATH + '"')
    return text[:start] + section + text[end:]


def patch_controller(text, name):
    start, end = module_bounds(text, name)
    section = text[start:end]
    for old, new in LOGO_PATHS.items():
        section = section.replace(old, new)
    return text[:start] + section + text[end:]


def patch_operator_logo(text, icon=False):
    """Replace only the two first-party logo templates, preserving native namespaces."""
    name = '@fleetbase/console/components/' + ('logo-icon' if icon else 'logo')
    start, end = module_bounds(text, name)
    section = text[start:end]
    match = re.search(r'"block":\s*("(?:\\.|[^"\\])*")', section)
    if not match:
        raise ValueError('Missing operator logo template block: ' + name)
    old_icon = [[[11,1],[24,0,'mapa-mark'],[24,'aria-label','MAPA'],[17,1],[12],[1,'MAPA'],[13],[1,'\n\n']],['&attrs'],False,[]]
    old_logo = [[[11,1],[24,0,'mapa-wordmark'],[17,1],[12],[10,1],[14,0,'mapa-mark'],[12],[1,'MAPA'],[13],[1,' '],[10,'strong'],[12],[1,'MAPA'],[13],[13],[1,'\n\n']],['&attrs'],False,[]]
    new_icon = [[[11,'img'],[24,0,'mapa-operator-icon'],[24,'src',ICON_PATH],[24,'alt','ASR Health'],[24,'width','48'],[24,'height','48'],[17,1],[12],[13],[1,'\n']],['&attrs'],False,[]]
    new_logo = [[[11,1],[24,0,'mapa-wordmark'],[17,1],[12],[10,'img'],[14,0,'mapa-operator-icon'],[14,'src',ICON_PATH],[14,'alt',''],[14,'width','40'],[14,'height','40'],[12],[13],[1,' '],[10,'strong'],[12],[1,'ASR HEALTH'],[13],[13],[1,'\n']],['&attrs'],False,[]]
    current = json.loads(json.loads(match.group(1)))
    desired = new_icon if icon else new_logo
    original = old_icon if icon else old_logo
    # Whitespace is the only tolerated build variation in these static templates.
    def without_whitespace(block):
        return [[node for node in block[0] if not (node[0] == 1 and len(node) == 2 and isinstance(node[1], str) and not node[1].strip())], *block[1:]]
    if without_whitespace(current) not in [without_whitespace(original), without_whitespace(desired)]:
        raise ValueError('Unrecognized operator logo template; refusing a broad edit: ' + name)
    encoded = json.dumps(json.dumps(desired, separators=(',', ':')), ensure_ascii=False)
    section = section[:match.start(1)] + encoded + section[match.end(1):]
    old_markup = '<span class="mapa-mark" aria-label="MAPA" ...attributes>MAPA</span>' if icon else '<span class="mapa-wordmark" ...attributes><span class="mapa-mark">MAPA</span> <strong>MAPA</strong></span>'
    source = (ROOT / ('fleetbase/console/app/components/' + ('logo-icon.hbs' if icon else 'logo.hbs'))).read_text(encoding='utf-8').strip()
    section = section.replace(old_markup, source)
    return text[:start] + section + text[end:]


def patch_portal_notifications(console, index):
    relative = 'engines-dist/@fleetbase/customer-portal-engine/assets/engine.js'
    engine = console / relative
    if not engine.exists():
        return index, None, None
    text = engine.read_text(encoding='utf-8')
    name = '@fleetbase/customer-portal-engine/templates/portal/notifications'
    marker = '\ndefine(' + json.dumps(name) + ','
    if text.count(marker) != 1:
        raise ValueError('Expected one portal notifications template module')
    start = text.index(marker)
    end = text.find('\ndefine(', start + len(marker))
    if end < 0:
        end = len(text)
    section = text[start:end]
    old = 'operator notification rules configured in Fleetbase.'
    new = 'operator notification rules configured in MAPA.'
    if section.count(old) + section.count(new) != 2:
        raise ValueError('Expected portal notification sentence in source and compiled template')
    section = section.replace(old, new)
    text = text[:start] + section + text[end:]
    version = hashlib.sha256(text.encode()).hexdigest()[:12]
    public_path = '/' + relative

    def update_manifest(manifest):
        assets = manifest['bundles']['@fleetbase/customer-portal-engine']['assets']
        selected = [asset for asset in assets if asset.get('uri', '').split('?')[0] == public_path]
        if len(selected) != 1:
            raise ValueError('Expected one customer portal engine asset in manifest')
        selected[0]['uri'] = public_path + '?mapa-brand=' + version
        return manifest

    manifest_file = console / 'asset-manifest.json'
    manifest = update_manifest(json.loads(manifest_file.read_text(encoding='utf-8')))
    pattern = r'(<meta name="@fleetbase/console/config/asset-manifest" content=")([^"\s]+)("\s*/>)'

    def replace_meta(match):
        value = update_manifest(json.loads(unquote(match.group(2))))
        return match.group(1) + quote(json.dumps(value, separators=(',', ':')), safe='/') + match.group(3)

    index, count = re.subn(pattern, replace_meta, index)
    if count != 1:
        raise ValueError('Expected one native asset manifest meta tag')
    return index, (engine, text), (manifest_file, json.dumps(manifest, separators=(',', ':')) + '\n')


def apply(console):
    bundle = console / 'assets/@fleetbase/console.js'
    vendor = console / 'assets/vendor.js'
    app_text = bundle.read_text(encoding='utf-8')
    vendor_text = vendor.read_text(encoding='utf-8')
    for name in ['components/onboarding/form', 'templates/invite/for-user',
                 'templates/console/admin/branding', 'templates/install']:
        app_text = patch_template(app_text, '@fleetbase/console/' + name, LOGO_PATHS)
    app_text = patch_controller(app_text, '@fleetbase/console/controllers/console/admin/branding')
    app_text = patch_operator_logo(patch_operator_logo(app_text), icon=True)
    vendor_text = patch_template(vendor_text, '@fleetbase/ember-ui/components/logo-icon',
                                 {**LOGO_PATHS, 'Fleetbase': 'ASR Health', 'MAPA': 'ASR Health'}, True)

    # The i18n catalog is a JSON value in one explicitly named AMD module.
    start, end = module_bounds(vendor_text, 'ember-intl/translations')
    section = vendor_text[start:end]
    marker = '_exports.default='
    value_start = section.index(marker, section.index(marker) + len(marker)) + len(marker)
    catalog, consumed = json.JSONDecoder().raw_decode(section[value_start:])
    for language, labels in catalog:
        labels.setdefault('app', {})['name'] = 'MAPA'
        # Product names on account/installation routes; preserve unrelated docs/legal text.
        def rename_labels(value):
            if isinstance(value, dict):
                return {key: rename_labels(child) for key, child in value.items()}
            if isinstance(value, list):
                return [rename_labels(child) for child in value]
            return value.replace('Fleetbase', 'MAPA') if isinstance(value, str) else value
        for group in ('auth', 'onboard', 'install', 'invite'):
            if group in labels:
                labels[group] = rename_labels(labels[group])
        if language == 'en-us':
            labels['auth']['login']['title'] = 'Sign in to MAPA'
    section = section[:value_start] + json.dumps(catalog, ensure_ascii=False, separators=(',', ':')) + section[value_start + consumed:]
    vendor_text = vendor_text[:start] + section + vendor_text[end:]

    index_file = console / 'index.html'
    index = index_file.read_text(encoding='utf-8')
    index = index.replace('href="/mapa-icon.svg"', 'href="' + ICON_PATH + '"')
    for asset, content in [('vendor.js', vendor_text), ('@fleetbase/console.js', app_text)]:
        version = hashlib.sha256(content.encode()).hexdigest()[:12]
        pattern = r'(?P<base>src="/assets/' + re.escape(asset) + r')(?:\?[^"\s]*)?(?P<close>")'
        index, count = re.subn(pattern, lambda m: m['base'] + '?mapa-brand=' + version + m['close'], index)
        if count != 1:
            raise ValueError(f'Expected one script tag for {asset}')
    index, portal_update, manifest_update = patch_portal_notifications(console, index)

    # Validate all transformations before writing. Re-running produces identical bytes.
    bundle.write_text(app_text, encoding='utf-8', newline='\n')
    vendor.write_text(vendor_text, encoding='utf-8', newline='\n')
    index_file.write_text(index, encoding='utf-8', newline='\n')
    for update in (portal_update, manifest_update):
        if update:
            path, content = update
            path.write_text(content, encoding='utf-8', newline='\n')
    for relative, content in [(ICON_PATH.lstrip('/'), ICON), (LOGO_PATH.lstrip('/'), LOGO),
                              ('mapa-icon.svg', ICON), ('images/mapa-logo.svg', LOGO),
                              ('images/icon.svg', ICON), ('images/fleetbase-logo-svg.svg', LOGO)]:
        (console / relative).write_text(content, encoding='utf-8', newline='\n')
    manifest = console / 'favicon/site.webmanifest'
    value = json.loads(manifest.read_text(encoding='utf-8'))
    value.update(name='ASR Health', short_name='ASR Health', theme_color='#0d9488', background_color='#111827',
                 icons=[{'src': ICON_PATH, 'sizes': 'any', 'type': 'image/svg+xml'}])
    manifest.write_text(json.dumps(value, indent=2) + '\n', encoding='utf-8', newline='\n')
    print('ASR Health entry logos applied; MAPA product labels, namespaces and attribution preserved.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--console-root', type=Path, default=ROOT / 'private/live-access/console')
    args = parser.parse_args()
    apply(args.console_root.resolve())

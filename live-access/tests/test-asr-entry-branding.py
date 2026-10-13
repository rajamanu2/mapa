"""Scoped branding guards; no console, account, database or browser is started."""
import importlib.util
import json
from pathlib import Path
import re
import unittest
import xml.etree.ElementTree as ET

HERE = Path(__file__).resolve().parents[1]
ROOT = HERE.parents[1]


def load(name):
    spec = importlib.util.spec_from_file_location(name.replace('-', '_'), HERE / (name + '.py'))
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


brand, loading, overlay = load('fix-entry-branding'), load('install-loading-assets'), load('prepare-asr-branding')


def amd(name, block):
    return ';define(' + json.dumps(name) + ',[],function(){return {"block":' + json.dumps(json.dumps(block)) + '};});\n'


def block_in(text, name):
    start, end = brand.module_bounds(text, name)
    match = re.search(r'"block":\s*("(?:\\.|[^"\\])*")', text[start:end])
    return json.loads(json.loads(match.group(1)))


class BrandingGuards(unittest.TestCase):
    def setUp(self):
        self.logo = '@fleetbase/console/components/logo'
        self.icon = '@fleetbase/console/components/logo-icon'
        self.old_icon = [[[11,1],[24,0,'mapa-mark'],[24,'aria-label','MAPA'],[17,1],[12],[1,'MAPA'],[13],[1,'\n\n']],['&attrs'],False,[]]
        self.old_logo = [[[11,1],[24,0,'mapa-wordmark'],[17,1],[12],[10,1],[14,0,'mapa-mark'],[12],[1,'MAPA'],[13],[1,' '],[10,'strong'],[12],[1,'MAPA'],[13],[13],[1,'\n\n']],['&attrs'],False,[]]

    def test_only_native_logo_modules_change(self):
        protected = amd('@fleetbase/console/components/mapa-attribution', [[[1,'MAPA.DEV']],[],False,[]])
        protected += amd('@fleetbase/console/controllers/auth/login', [[[1,'MAPA software login']],[],False,[]])
        original = '/* Preserve upstream licence */\n' + amd(self.logo,self.old_logo) + amd(self.icon,self.old_icon) + protected
        changed = brand.patch_operator_logo(brand.patch_operator_logo(original),icon=True)
        self.assertTrue(changed.startswith('/* Preserve upstream licence */'))
        self.assertTrue(changed.endswith(protected))
        self.assertIn([1,'ASR HEALTH'],block_in(changed,self.logo)[0])
        self.assertIn([24,'alt','ASR Health'],block_in(changed,self.icon)[0])
        self.assertEqual(changed,brand.patch_operator_logo(brand.patch_operator_logo(changed),icon=True))

    def test_unexpected_or_duplicate_module_rejected(self):
        original = amd(self.icon,self.old_icon)
        with self.assertRaises(ValueError):
            brand.patch_operator_logo(original + original,icon=True)
        with self.assertRaises(ValueError):
            brand.patch_operator_logo(amd(self.icon,[[[1,'unexpected implementation']],[],False,[]]),icon=True)

    def test_shared_logo_keeps_namespaces_and_private_bindings(self):
        name='@fleetbase/ember-ui/components/logo-icon'
        original=amd(name,[[[24,'src','/mapa-icon.svg'],[24,'alt','MAPA'],[16,'data-preserved',[30,0,['privateState']]]],['&attrs'],False,['image']])
        changed=brand.patch_template(original,name,{**brand.LOGO_PATHS,'MAPA':'ASR Health'},True)
        block=block_in(changed,name)
        self.assertIn([24,'src','/images/asr-health-icon.svg'],block[0])
        self.assertIn([16,'data-preserved',[30,0,['privateState']]],block[0])
        self.assertEqual(block[3],['image'])
        self.assertIn(name,changed)

    def test_loading_installer_preserves_native_boot_and_footer(self):
        html='<html><head><meta name="native-config" content="DEMO unchanged"></head><body><div id="boot-loader"><div>Old</div></div><script src="/assets/vendor.js"></script><script src="/mapa-intake-native.js"></script><script src="/assets/@fleetbase/console.js"></script><footer>MAPA.DEV</footer></body></html>'
        changed,assets,version=loading.prepare(html)
        self.assertIn('<meta name="native-config" content="DEMO unchanged">',changed)
        self.assertIn('<footer>MAPA.DEV</footer>',changed)
        self.assertLess(changed.index('/assets/vendor.js'),changed.index('/mapa-intake-native.js'))
        self.assertLess(changed.index('/mapa-intake-native.js'),changed.index('/assets/@fleetbase/console.js'))
        begin,end=loading.boot_bounds(changed)
        self.assertIn('ASR HEALTH',changed[begin:end])
        self.assertNotRegex(changed[begin:end],r'>[^<]*MAPA[^<]*<')
        self.assertEqual(loading.prepare(changed)[0],changed)
        self.assertEqual(set(assets),{'mapa-loading.css','mapa-loading.js','images/asr-health-icon.svg'})
        self.assertRegex(version,r'^[a-f0-9]{12}$')

    def test_generated_assets_are_local_svg_without_active_content(self):
        for text in [brand.ICON,brand.LOGO]:
            xml=ET.fromstring(text)
            self.assertEqual(xml.attrib['aria-label'],'ASR Health')
            self.assertNotRegex(text,r'<script|<foreignObject|\bon[a-z]+\s*=|href=')
        self.assertIn('ASR HEALTH',(ROOT/'fleetbase/console/app/components/logo.hbs').read_text(encoding='utf-8'))

    def test_overlay_cannot_include_operational_or_legal_files(self):
        self.assertEqual(len(overlay.OUTPUT_ALLOWLIST),12)
        self.assertNotIn('mapa-voice.js',overlay.OUTPUT_ALLOWLIST)
        self.assertNotIn('mapa-legal.js',overlay.OUTPUT_ALLOWLIST)
        self.assertFalse(any('track/' in name or '.env' in name or '..' in name for name in overlay.OUTPUT_ALLOWLIST))
        self.assertNotIn('@fleetbase/console/components/mapa-attribution',overlay.APP_MODULES)


if __name__=='__main__':
    unittest.main()

"""Offline installer preflight, scope, cache-version and refusal regression."""
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import sys
import tempfile
from urllib.parse import unquote

SOURCE = Path(__file__).with_name('install-null-vehicle-console.py')
spec = importlib.util.spec_from_file_location('null_vehicle_installer', SOURCE)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
console = module.ROOT / 'private/live-access/console'
manifest = json.loads(module.MANIFEST.read_text(encoding='utf-8'))
checks = 0
def check(value):
    global checks
    assert value
    checks += 1

before = {name: (console / name).read_bytes() for name in [module.VENDOR,module.ENGINE,module.APP,'index.html','asset-manifest.json']}
files, changes = module.prepare(console,manifest)
check(len(changes) in [0,5])
with tempfile.TemporaryDirectory(prefix='mapa-null-console-') as directory:
    candidate=Path(directory)
    for name,contents in files.items():
        path=candidate/name
        path.parent.mkdir(parents=True,exist_ok=True)
        path.write_text(contents,encoding='utf-8',newline='\n')
    second, repeated = module.prepare(candidate,manifest)
    check(repeated == [])
    check(second == files)
    result=subprocess.run([sys.executable,'-B',str(SOURCE),'--console-root',str(candidate)],capture_output=True,text=True)
    check(result.returncode == 0 and json.loads(result.stdout)['written'] is False)
    check({name:(candidate/name).read_text(encoding='utf-8') for name in files} == files)
    asset=json.loads(files['asset-manifest.json'])
    old_asset=json.loads(before['asset-manifest.json'])
    uri='/engines-dist/@fleetbase/fleetops-engine/assets/engine.js'
    for name, bundle in asset['bundles'].items():
        if name != '@fleetbase/fleetops-engine': check(bundle == old_asset['bundles'][name])
    engine_assets=asset['bundles']['@fleetbase/fleetops-engine']['assets']
    matches=[entry for entry in engine_assets if entry['uri'].split('?')[0]==uri]
    check(len(matches)==1 and matches[0]['uri']==uri+'?mapa-null-gps='+module.digest(files[module.ENGINE])[:12])
    meta=re.search(r'<meta name="@fleetbase/console/config/asset-manifest" content="([^\"]+)"',files['index.html'])
    check(json.loads(unquote(meta[1])) == asset)
    for name in [module.VENDOR,module.APP]:
        check('/'+name+'?mapa-null-gps='+module.digest(files[name])[:12] in files['index.html'])
    # A module changed by someone else must fail before touching any output.
    altered=candidate/module.VENDOR
    altered.write_text(files[module.VENDOR].replace("('mapa-vehicle-point')","('unreviewed-vehicle-point')",1),encoding='utf-8')
    refused=subprocess.run([sys.executable,'-B',str(SOURCE),'--console-root',str(candidate),'--output-root',str(candidate/'out')],capture_output=True,text=True)
    check(refused.returncode != 0)
    check(not (candidate/'out').exists())
check(all((console/name).read_bytes()==value for name,value in before.items()))
check("add_header Cache-Control 'no-store' always;" in (module.ROOT/'fleetbase/live-access/gateway.conf.template').read_text())
print(f'PASS {checks} offline native-console installer checks; served files unchanged.')

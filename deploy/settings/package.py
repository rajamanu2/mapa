"""Build and exercise the settings-only release. No network or production writes."""
import hashlib
import importlib.util
import re
import shutil
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DEST = ROOT / 'settings-release'
if DEST.exists():
    raise SystemExit('Release directory already exists; use a clean checkout')
DEST.mkdir()
payload = (ROOT / 'console/public/mapa-dispatch-settings.js').read_bytes()
digest = hashlib.sha256(payload).hexdigest()
template = (Path(__file__).parent / 'publish-settings.py').read_text()
template, count = re.subn(r"SOURCE_SHA = '[a-f0-9]{64}'", f"SOURCE_SHA = '{digest}'", template)
assert count == 1
(DEST / 'publish-settings.py').write_text(template, newline='\n')
(DEST / 'mapa-dispatch-settings.js').write_bytes(payload)
shutil.copyfile(Path(__file__).parent / 'apply.sh', DEST / 'apply.sh')
spec = importlib.util.spec_from_file_location('publisher', DEST / 'publish-settings.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
with tempfile.TemporaryDirectory(dir=DEST) as directory:
    root = Path(directory) / 'public'
    root.mkdir()
    module.BACKUP = Path(directory) / 'rollback'
    module.RECEIPT = Path(directory) / 'receipt.json'
    old_name = 'mapa-dispatch-settings-0123456789abcdef.js'
    old = b'// DEMO old release\n'
    html = f'<script src="/{old_name}"></script>'.encode()
    (root / 'index.html').write_bytes(html)
    (root / old_name).write_bytes(old)
    (root / 'mapa-dispatch-settings.js').write_bytes(old)
    state = module.inspect(root)
    module.publish(root, state['state_sha256'])
    assert (root / module.NEW_ASSET).read_bytes() == payload
    module.rollback(root)
    assert (root / 'index.html').read_bytes() == html
    assert (root / 'mapa-dispatch-settings.js').read_bytes() == old
print('Isolated publication and rollback passed:', digest)

#!/usr/bin/env python3
"""Package only reviewed deployment source. Never include migration data or credentials."""
from pathlib import Path
import hashlib
import json
import zipfile

base = Path(__file__).resolve().parent
root_files = [
    '.dockerignore', '.env.example', '.gitignore', 'README.md', 'VALIDATION.md',
    'FRONTEND.md', 'MIGRATION.md', 'compose.yml', 'Dockerfile.api', 'TrustProxies.php',
    'gateway.conf.template', 'proxy.conf', 'runtime-config.mjs', 'export-cloud.py',
    'restore-cloud.py', 'backup.sh', 'test-export-cloud.py', 'test-restore-cloud.py',
    'test-gateway.py', 'package-cloud.py',
]
edge_files = [
    '.gitignore', 'README.md', 'VALIDATION.md', 'package.json', 'pnpm-lock.yaml',
    'pnpm-workspace.yaml', 'tsconfig.json', 'worker-configuration.d.ts',
    'wrangler.example.jsonc', 'src/worker.mjs', 'test/proxy.test.mjs',
]
selected = [base / name for name in root_files] + [base / 'edge' / name for name in edge_files]
for path in selected:
    if not path.is_file() or path.is_symlink():
        raise SystemExit('Expected reviewed deployment file missing: ' + path.name)
output = base.parents[1] / 'MAPA-Cloud-Setup-2026-09-18.zip'
manifest = {}
with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for path in selected:
        name = path.relative_to(base).as_posix()
        data = path.read_bytes()
        manifest[name] = {'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()}
        archive.writestr('mapa-cloud/' + name, data)
    archive.writestr('mapa-cloud/package-manifest.json', json.dumps(manifest, indent=2) + '\n')
print(json.dumps({'file': str(output), 'entries': len(selected) + 1,
                  'bytes': output.stat().st_size, 'sha256': hashlib.sha256(output.read_bytes()).hexdigest()}))

"""Create a source-only build context. No credentials, downloaded archives or live data."""
import argparse, hashlib, json, shutil
from pathlib import Path

SOURCE = Path(__file__).resolve().parents[1]
FILES = ['package.json', 'pnpm-lock.yaml', 'pnpm-workspace.yaml', 'ember-cli-build.js',
         'tailwind.config.js', 'router.map.js', '.ember-cli', 'Dockerfile.production-review']
DIRS = ['app', 'config', 'lib', 'translations', 'scripts', 'public']

def prepare(destination):
    destination = destination.resolve()
    if destination.exists():
        raise ValueError('Use a new build context; existing directories are never overwritten')
    destination.mkdir(parents=True)
    for name in FILES:
        shutil.copy2(SOURCE / name, destination / name)
    for name in DIRS:
        for source in (SOURCE / name).rglob('*'):
            relative = source.relative_to(SOURCE)
            if source.is_symlink() or not source.is_file():
                continue
            if any(p.startswith('.') or p in ('downloads', '__pycache__', 'node_modules', 'audit', 'private') for p in relative.parts):
                continue
            if source.suffix.lower() in ('.zip', '.apk', '.map', '.env', '.key', '.pem', '.sql', '.gpg', '.tar'):
                continue
            target = destination / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, target)
    (destination / 'fleetbase.config.json').write_text('{}\n', encoding='utf-8')
    (destination / 'environments').mkdir()
    (destination / 'environments/.env.production').write_text('API_HOST=/api\nDISABLE_RUNTIME_CONFIG=false\n', encoding='utf-8')
    manifest = {p.relative_to(destination).as_posix(): hashlib.sha256(p.read_bytes()).hexdigest()
                for p in sorted(destination.rglob('*')) if p.is_file()}
    (destination / 'source-manifest.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')
    print(f'Prepared {len(manifest)} source files in {destination}. No deployment performed.')

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('destination', type=Path)
    prepare(parser.parse_args().destination)

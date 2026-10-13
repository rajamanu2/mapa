"""Restore stable public endpoint assets after Ember fingerprinting; local artifacts only."""
import argparse, hashlib, json, re, shutil
from pathlib import Path

def finalize(built, public):
    built, public = built.resolve(), public.resolve()
    if built == public or not (built / 'index.html').is_file():
        raise ValueError('Use a separate compiled candidate')
    # Public source from prepare-production-review, never a workspace/private root.
    if not (public / 'mapa-icon.svg').is_file() or not (public / 'call-intake/index.html').is_file():
        raise ValueError('Expected prepared MAPA public source')
    assets = []
    for source in sorted(public.rglob('*')):
        if source.is_symlink(): raise ValueError('Public symlinks are not permitted')
        if not source.is_file(): continue
        rel = source.relative_to(public)
        if any(part.startswith('.') or part in ('private', 'downloads') for part in rel.parts):
            raise ValueError('Unexpected private/download content')
        if source.suffix.lower() not in ('.html','.css','.js','.json','.txt','.svg','.png','.jpg','.jpeg','.webp','.ico','.xml','.woff','.woff2','.webmanifest'):
            raise ValueError('Unreviewed public asset type: ' + rel.as_posix())
        if rel.as_posix() == 'index.html': raise ValueError('Do not replace the compiled app entry')
        assets.append((source, rel))
    index = (built / 'index.html').read_text(encoding='utf-8')
    for source, rel in assets:
        target = built / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source, target)
        # Keep compiled framework/engine assets untouched, including their SRI.
        name = rel.as_posix()
        stem, suffix = name.rsplit('.', 1)
        hashed = re.escape(stem) + r'(?:-[a-f0-9]{32})?\.' + re.escape(suffix)
        digest = hashlib.sha256(source.read_bytes()).hexdigest()[:12]
        index = re.sub(r'(["\'])/' + hashed + r'(?:\?[^"\']*)?(["\'])',
                       lambda m: m[1] + '/' + name + '?v=' + digest + m[2], index)
    (built / 'index.html').write_text(index, encoding='utf-8')
    forbidden = [p for p in built.rglob('*') if p.is_file() and p.suffix.lower() in ('.map','.env','.key','.pem','.sql','.zip','.apk')]
    if forbidden: raise ValueError('Private/source/archive file found in candidate')
    manifest = {p.relative_to(built).as_posix(): hashlib.sha256(p.read_bytes()).hexdigest()
                for p in sorted(built.rglob('*')) if p.is_file()}
    (built.parent / 'artifact-sha256.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')
    print(f'Finalized {len(assets)} public assets and hashed {len(manifest)} artifact files. Nothing deployed.')

if __name__ == '__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--built',type=Path,required=True)
    parser.add_argument('--public',type=Path,required=True)
    args=parser.parse_args();finalize(args.built,args.public)

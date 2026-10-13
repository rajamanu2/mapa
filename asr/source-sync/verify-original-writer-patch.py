"""Read-only exact-content verification before or after applying the reviewed original source patch."""
import argparse,hashlib,json,pathlib,sys
p=argparse.ArgumentParser();p.add_argument('--manifest',required=True);p.add_argument('--source-dir',required=True);p.add_argument('--phase',choices=['before','after'],required=True);a=p.parse_args()
root=pathlib.Path(a.source_dir).resolve(strict=True);manifest=json.loads(pathlib.Path(a.manifest).read_text(encoding='utf-8'));fail=[];verified=0
for name,hashes in manifest['files'].items():
    relative=pathlib.PurePosixPath(name)
    if relative.is_absolute() or '..' in relative.parts:raise SystemExit('Invalid manifest path')
    target=(root/pathlib.Path(*relative.parts)).resolve()
    if not target.is_relative_to(root):raise SystemExit('Manifest target leaves source directory')
    expected=hashes.get(a.phase+'_sha256')
    if expected is None:
        if a.phase=='before' and target.exists() and hashlib.sha256(target.read_bytes()).hexdigest()!=hashes['after_sha256']:fail.append(name+': conflicting existing helper')
        continue
    if not target.is_file():fail.append(name+': missing');continue
    if hashlib.sha256(target.read_bytes()).hexdigest()!=expected:fail.append(name+': content mismatch');continue
    verified+=1
print(json.dumps({'phase':a.phase,'verified_files':verified,'errors':fail,'ready_to_enable':False},indent=2))
sys.exit(1 if fail else 0)

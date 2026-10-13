"""Install reviewed null-GPS API guards without restarting application containers."""
from pathlib import Path
from datetime import datetime,timezone
import argparse,json,hashlib,subprocess
p=argparse.ArgumentParser();p.add_argument('--docker',required=True);p.add_argument('--apply',action='store_true');args=p.parse_args()
root=Path(__file__).resolve().parents[2]
manifest=json.loads((root/'fleetbase/live-access/overrides/vehicle-null-location-manifest.json').read_text(encoding='utf-8'))
containers=['mapa-live-access-api-1','asr-fleetbase-pilot-application-1']
def run(*command):
 r=subprocess.run([args.docker,*command],capture_output=True,timeout=60)
 if r.returncode:raise RuntimeError('Reviewed API source installation failed: '+command[0])
 return r.stdout
sha=lambda content:hashlib.sha256(content).hexdigest()
preflight=[]
for container in containers:
 for row in manifest:
  candidate=root/row['override'];new=candidate.read_bytes();assert sha(new)==row['new_sha256']
  original=run('exec',container,'cat',row['runtime_path']);digest=sha(original)
  assert digest in [row['original_sha256'],row['new_sha256']], 'Unexpected installed API source; no files changed.'
  if digest==row['original_sha256']:
   reverted=new;guards=0
   for variable in [b'$this',b'$vehicle']:
    expression=variable+b'->location === null ? null : Utils::castPoint('+variable+b'->location)'
    guards+=new.count(expression);reverted=reverted.replace(expression,b'Utils::castPoint('+variable+b'->location)')
   assert guards==row['null_guard_expressions'] and reverted==original,'Expected only reviewed null guards.'
  preflight.append((container,row,candidate,original,digest==row['new_sha256']))
print('Validated exact source hashes in both API containers.',flush=True)
if not args.apply:raise SystemExit(0)
backup=root/'private/crew-release-backups'/('before-null-gps-'+datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ'))
backup.mkdir(parents=True,exist_ok=False)
for container,row,candidate,original,already in preflight:
 (backup/(container+'-'+candidate.name)).write_bytes(original)
 if already:continue
 temporary='/tmp/mapa-null-gps-'+Path(row['runtime_path']).name
 run('cp',str(candidate),container+':'+temporary)
 run('exec',container,'php','-l',temporary)
 run('exec',container,'mv',temporary,row['runtime_path'])
 assert sha(run('exec',container,'cat',row['runtime_path']))==row['new_sha256']
 print('Installed null-GPS guard:',container,Path(row['runtime_path']).name,flush=True)
for container in containers:
 run('exec',container,'php','artisan','octane:reload')
 print('Gracefully reloaded API workers:',container,flush=True)
print('No database, cache, queue, socket or connector restart.',flush=True)

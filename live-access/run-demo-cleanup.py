"""Run reviewed exact DEMO cleanup PHP; default SELECT-only validation."""
from pathlib import Path
import argparse,hashlib,json,os,subprocess
root=Path(__file__).resolve().parents[2]
ap=argparse.ArgumentParser();ap.add_argument('--apply',action='store_true');ap.add_argument('--confirm');ap.add_argument('--source-sha256',required=True);ap.add_argument('--receipt',required=True);args=ap.parse_args()
source=root/'fleetbase/live-access/retire-demo-data.php';code=source.read_bytes()
if hashlib.sha256(code).hexdigest()!=args.source_sha256:raise SystemExit('Reviewed cleanup source hash changed.')
plan_path=root/'private/asr-import/demo-cleanup-plan-2026-10-01.json';plan=plan_path.read_bytes();p=json.loads(plan)
if args.apply and args.confirm!=p['plan_fingerprint']:raise SystemExit('Exact reviewed plan confirmation required.')
receipt=(root/args.receipt).resolve();private=(root/'private/asr-import').resolve()
if receipt.parent!=private or receipt.suffix!='.json' or receipt.exists():raise SystemExit('New private receipt path required; no overwrite.')
docker=os.path.join(os.environ['LOCALAPPDATA'],'Programs','DockerDesktop','resources','bin','docker.exe')
mode=['--apply','--confirm='+args.confirm] if args.apply else ['--dry-run']
r=subprocess.run([docker,'exec','-i','mapa-live-access-api-1','php','-r',code.decode().removeprefix('<?php'),'--',*mode],input=plan,capture_output=True,timeout=60)
if r.returncode:raise SystemExit(r.stderr.decode(errors='replace')[:1800])
result=json.loads(r.stdout)
if result.get('plan_fingerprint')!=p['plan_fingerprint'] or result.get('mode')!=('applied' if args.apply else 'dry_run'):raise SystemExit('Unexpected receipt; verify database manually before any retry.')
result['source_sha256']=args.source_sha256;receipt.write_text(json.dumps(result,indent=2),encoding='utf-8')
print(json.dumps(result,indent=2))

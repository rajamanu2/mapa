"""Adversarial SELECT-only checks for the exact reviewed DEMO archive plan."""
from pathlib import Path
import copy,hashlib,json,os,subprocess
root=Path(__file__).resolve().parents[2]
source=root/'fleetbase/live-access/retire-demo-data.php'
code=source.read_text(encoding='utf-8').removeprefix('<?php')
plan=json.loads((root/'private/asr-import/demo-cleanup-plan-2026-10-01.json').read_text())
audit=json.loads((root/'private/asr-import/demo-cleanup-audit-2026-10-01.json').read_text())
docker=os.path.join(os.environ['LOCALAPPDATA'],'Programs','DockerDesktop','resources','bin','docker.exe')
def fingerprint(p):
 p['scope']=dict(sorted((t,sorted(ids)) for t,ids in p['scope'].items()))
 p['plan_fingerprint']=hashlib.sha256(json.dumps(p['scope'],separators=(',',':'),ensure_ascii=False).encode()).hexdigest()
 return p
def run(p):
 return subprocess.run([docker,'exec','-i','mapa-live-access-api-1','php','-r',code,'--','--dry-run'],input=json.dumps(p).encode(),capture_output=True,timeout=40)
checks=[]
r=run(plan);assert r.returncode==0,r.stderr.decode(errors='replace')[:500]
result=json.loads(r.stdout);assert result['mode']=='dry_run' and result['hard_deleted_rows']==0 and result['notifications_sent']==0
checks.append('exact scoped plan passes read-only validation')
for table in ['users','drivers','vehicles','places']:
 candidate=next(x for x in audit['records'][table] if not x['provenance'] and x['active'])
 forged=copy.deepcopy(plan);forged['scope'][table].append(candidate['id']);forged['expected'][table][candidate['id']]=candidate
 r=run(fingerprint(forged));assert r.returncode!=0,'unmarked actual '+table+' accepted'
 checks.append('unmarked actual '+table+' rejected even with recomputed fingerprint')
forged=copy.deepcopy(plan);forged['company_uuid']='00000000-0000-4000-a000-000000000001';assert run(forged).returncode!=0
checks.append('foreign company denied')
forged=copy.deepcopy(plan);forged['plan_fingerprint']='0'*64;assert run(forged).returncode!=0
checks.append('tampered plan fingerprint denied')
forged=copy.deepcopy(plan);row=next(iter(forged['expected']['mapa_voice_calls'].values()));row['revision']+=1;assert run(forged).returncode!=0
checks.append('stale call revision denied')
forged=copy.deepcopy(plan);linked=next(x for x in audit['records']['mapa_voice_calls'] if x['provenance'] and (x['references'] or {}).get('order_uuid'));forged['scope']['orders'].remove(linked['references']['order_uuid']);assert run(fingerprint(forged)).returncode!=0
checks.append('demo call linked to preserved order denied')
actual_position=next(x for x in audit['records']['positions'] if x['id'] not in plan['scope']['positions'])
forged=copy.deepcopy(plan);forged['scope']['positions'].append(actual_position['id']);forged['expected']['positions'][actual_position['id']]=actual_position;assert run(fingerprint(forged)).returncode!=0
checks.append('real GPS history cannot enter DEMO-subject archive')
forged=copy.deepcopy(plan);forged['scope']['mapa_mobile_enrollments']=[audit['records']['mapa_mobile_enrollments'][0]['id']];assert run(fingerprint(forged)).returncode!=0
checks.append('registration table cannot enter archive whitelist')
report={'ok':True,'read_only':True,'checks':checks,'passed':len(checks),'source_sha256':hashlib.sha256(source.read_bytes()).hexdigest(),'plan_fingerprint':plan['plan_fingerprint'],'positive_counts':result['counts'],'production_writes':0}
out=root/'private/asr-import/demo-cleanup-read-only-tests-2026-10-01.json';out.write_text(json.dumps(report,indent=2),encoding='utf-8')
print(json.dumps(report,indent=2))

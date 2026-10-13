"""Offline lint/pure tests in new cached-image containers; never boots a live app/DB."""
import argparse,hashlib,json,subprocess,sys
from datetime import datetime,timezone
from pathlib import Path
module=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(module))
from apply import compose_body
from verify import compose_body as compose_verification_body

parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--docker',required=True);args=parser.parse_args()
files=['ImportPlan.php','ImportApply.php','apply-native.php','snapshot-native.php','inspect-native.php','tests/policy.php','apply.py','stage.py','VerifyInventory.php','verify-native.php','verify.py','tests/verification.php']
digest=lambda file:hashlib.sha256(file.read_bytes()).hexdigest()
before={name:digest(module/name) for name in files};checks=[]
base=[args.docker,'run','-i','--rm','--pull','never','--network','none','--read-only','--cap-drop','ALL','--security-opt','no-new-privileges','--mount','type=bind,source='+str(module)+',target=/qa/import,readonly','--entrypoint','php','mapa-cloud-api:pilot']
for name in files:
    if not name.endswith('.php'):continue
    process=subprocess.run([*base,'-l','/qa/import/'+name],capture_output=True,text=True,timeout=45)
    checks.append({'id':'lint-'+name,'pass':process.returncode==0,'detail':(process.stdout+process.stderr).strip()})
process=subprocess.run([*base,'/qa/import/tests/policy.php'],capture_output=True,text=True,timeout=45)
checks.append({'id':'pure-resource-policy','pass':process.returncode==0,'detail':(process.stdout+process.stderr).strip()})
process=subprocess.run([*base,'/qa/import/tests/verification.php'],capture_output=True,text=True,timeout=45)
checks.append({'id':'pure-inventory-verification','pass':process.returncode==0,'detail':(process.stdout+process.stderr).strip()})
process=subprocess.run([*base,'-l'],input=compose_body({'plan_base64':'DEMO lint-only fixture'}),capture_output=True,text=True,timeout=45)
checks.append({'id':'exact-launcher-body-lint','pass':process.returncode==0,'detail':(process.stdout+process.stderr).strip()})
process=subprocess.run([*base,'-l'],input=compose_verification_body({'plan_base64':'DEMO lint-only fixture','snapshot_base64':'DEMO lint-only snapshot'}),capture_output=True,text=True,timeout=45)
checks.append({'id':'exact-readonly-verifier-body-lint','pass':process.returncode==0,'detail':(process.stdout+process.stderr).strip()})
stable=all(digest(module/name)==value for name,value in before.items());checks.append({'id':'source-stability','pass':stable})
report={'checked_at':datetime.now(timezone.utc).isoformat(),'state':'PASS' if all(c['pass'] for c in checks) else 'FAIL','proof':'offline syntax and pure policy only','production_writes':0,'source_hashes':before,'checks':checks}
output=module/'results'/'offline.json';output.parent.mkdir(exist_ok=True);output.write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8')
for check in checks:print(check['id']+': '+('PASS' if check['pass'] else 'FAIL')+' '+check.get('detail',''))
print('Report: '+str(output));sys.exit(0 if report['state']=='PASS' else 1)

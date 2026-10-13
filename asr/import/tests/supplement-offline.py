"""Supplement syntax/policy checks only, with offline cached containers and no DB."""
from pathlib import Path
from datetime import datetime,timezone
import argparse,base64,hashlib,json,subprocess,sys
module=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(module))
from supplement import compose_body,compose_policy_body

def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--docker',required=True);parser.add_argument('--plan');parser.add_argument('--evidence');parser.add_argument('--expected-plan-sha');args=parser.parse_args()
    if any([args.plan,args.evidence,args.expected_plan_sha]) and not all([args.plan,args.evidence,args.expected_plan_sha]):raise ValueError('All private reviewed artifact arguments required together')
    files=['ImportPlan.php','SupplementPlan.php','SupplementApply.php','supplement-native.php','supplement.py','stage-supplement.py','tests/supplement-policy.php','tests/supplement-offline.py']
    digest=lambda file:hashlib.sha256(file.read_bytes()).hexdigest()
    before={name:digest(module/name) for name in files};checks=[]
    base=[args.docker,'run','-i','--rm','--pull','never','--network','none','--read-only','--cap-drop','ALL','--security-opt','no-new-privileges','--mount','type=bind,source='+str(module)+',target=/qa/import,readonly','--entrypoint','php','mapa-cloud-api:pilot']
    def run(label,command,body=None):
        process=subprocess.run(command,input=body,text=True,encoding='utf-8',errors='strict',capture_output=True,timeout=45)
        checks.append({'id':label,'pass':process.returncode==0,'detail':(process.stdout+process.stderr).strip()})
        return process
    for name in files:
        if name.endswith('.php'):run('lint-'+name,[*base,'-l','/qa/import/'+name])
    run('pure-supplement-policy',[*base,'/qa/import/tests/supplement-policy.php'])
    run('exact-native-launcher-body-lint',[*base,'-l'],compose_body({'plan_base64':'DEMO lint only'}))
    run('exact-offline-policy-body-lint',[*base,'-l'],compose_policy_body({'plan_base64':'DEMO lint only'}))
    reviewed=None
    if args.plan:
        raw=Path(args.plan).read_bytes();reviewed=digest(Path(args.plan));plan=json.loads(raw)
        if reviewed!=args.expected_plan_sha or digest(Path(args.evidence))!=plan.get('evidence_sha256'):raise ValueError('Exact reviewed supplement artifacts required')
        envelope={'ack':'REVIEWED_HOSPITAL_PIN_SUPPLEMENT','plan_base64':base64.b64encode(raw).decode('ascii'),'expected_plan_sha256':reviewed}
        result=run('exact-private-plan-pure-validation',[*base],compose_policy_body(envelope))
        expected={'contract':'asr-hospital-pin-supplement-policy-v1','hospitals':len(plan['hospitals']),'vehicle_links':len(plan['vehicle_links'])}
        if result.returncode==0:checks[-1]['pass']=json.loads(result.stdout)==expected
    checks.append({'id':'source-stability','pass':all(digest(module/name)==value for name,value in before.items())})
    report={'checked_at':datetime.now(timezone.utc).isoformat(),'state':'PASS' if all(c['pass'] for c in checks) else 'FAIL','proof':'offline syntax and pure supplement policy only; native integration separate','production_writes':0,'source_hashes':before,'reviewed_plan_sha256':reviewed,'checks':checks}
    output=module/'results'/'supplement-offline.json';output.parent.mkdir(exist_ok=True);output.write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8')
    for check in checks:print(check['id']+': '+('PASS' if check['pass'] else 'FAIL')+' '+check.get('detail',''))
    print('Report: '+str(output));return 0 if report['state']=='PASS' else 1
if __name__=='__main__':sys.exit(main())

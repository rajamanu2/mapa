"""Review/dry-run a separate researched hospital supplement. Root owns explicit --apply."""
from pathlib import Path
import argparse,base64,hashlib,json,subprocess,sys
from stage import COMPANY

def compose_policy_body(envelope):
    """Execute the exact PHP policy only, with no application/database bootstrap."""
    module=Path(__file__).resolve().parent;body='<?php\n'
    for name in ['ImportPlan.php','SupplementPlan.php']:
        body+=(module/name).read_text(encoding='utf-8').replace('<?php','',1).replace('namespace ASR\\Import;','namespace ASR\\Import {',1)+'\n}\n'
    body+='namespace {\n$input=json_decode(base64_decode("'+base64.b64encode(json.dumps(envelope).encode('utf-8')).decode('ascii')+'"),true,512,JSON_THROW_ON_ERROR);\n'
    return body+'$plan=\\ASR\\Import\\SupplementPlan::unpack($input);echo json_encode(["contract"=>"asr-hospital-pin-supplement-policy-v1","hospitals"=>count($plan["hospitals"]),"vehicle_links"=>count($plan["vehicle_links"])]),PHP_EOL;\n}\n'

def compose_body(envelope):
    module=Path(__file__).resolve().parent;body='<?php\n'
    for name in ['ImportPlan.php','SupplementPlan.php','SupplementApply.php']:
        body+=(module/name).read_text(encoding='utf-8').replace('<?php','',1).replace('namespace ASR\\Import;','namespace ASR\\Import {',1)+'\n}\n'
    body+='namespace {\n$asrSupplementInput=json_decode(base64_decode("'+base64.b64encode(json.dumps(envelope).encode('utf-8')).decode('ascii')+'"),true,512,JSON_THROW_ON_ERROR);\n'
    source=(module/'supplement-native.php').read_text(encoding='utf-8').replace('<?php','',1)
    for name in ['ImportPlan.php','SupplementPlan.php','SupplementApply.php']:source=source.replace("require '/fleetbase/api/app/AsrImport/"+name+"';",'')
    return body+source+'\n}\n'

def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--plan',required=True);parser.add_argument('--evidence',required=True);parser.add_argument('--expected-plan-sha',required=True)
    parser.add_argument('--apply',action='store_true');parser.add_argument('--docker');parser.add_argument('--output');args=parser.parse_args()
    raw=Path(args.plan).read_bytes();digest=hashlib.sha256(raw).hexdigest();plan=json.loads(raw)
    if digest!=args.expected_plan_sha or plan.get('company_uuid')!=COMPANY or plan.get('contract')!='asr-hospital-pin-supplement-v1':raise ValueError('Exact reviewed scoped supplement required')
    if hashlib.sha256(Path(args.evidence).read_bytes()).hexdigest()!=plan.get('evidence_sha256'):raise ValueError('Exact reviewed public research evidence required')
    envelope={'ack':'REVIEWED_HOSPITAL_PIN_SUPPLEMENT','plan_base64':base64.b64encode(raw).decode('ascii'),'expected_plan_sha256':digest}
    if not args.apply:
        checked=False
        if args.docker:
            process=subprocess.run([args.docker,'run','-i','--rm','--pull','never','--network','none','--read-only','--cap-drop','ALL','--security-opt','no-new-privileges','--entrypoint','php','mapa-cloud-api:pilot'],input=compose_policy_body(envelope),text=True,encoding='utf-8',errors='strict',capture_output=True,timeout=45)
            if process.returncode:raise ValueError('Offline supplemental policy validation failed; diagnostics withheld')
            policy=json.loads(process.stdout)
            checked=policy=={'contract':'asr-hospital-pin-supplement-policy-v1','hospitals':len(plan['hospitals']),'vehicle_links':len(plan['vehicle_links'])}
            if not checked:raise ValueError('Unrecognized offline supplemental policy result')
        print(json.dumps({'mode':'dry_run_no_database','plan_sha256':digest,'summary':{'proposed_hospitals':len(plan['hospitals']),'reviewed_inventory_metadata_links':len(plan['vehicle_links']),'unresolved_hospitals':len(plan.get('unresolved',[]))},'php_policy_validated':checked,'production_writes':0}));return 0
    if not args.docker or not args.output:raise ValueError('Explicit installed Docker and private receipt output required')
    result=subprocess.run([args.docker,'exec','-i','mapa-live-access-api-1','php','-d','xdebug.mode=off'],input=compose_body(envelope),text=True,encoding='utf-8',errors='strict',capture_output=True,timeout=120)
    if result.returncode:raise ValueError('Native supplement failed; runtime diagnostics withheld')
    receipt=json.loads(result.stdout)
    if receipt.get('contract')!='asr-hospital-pin-supplement-apply-v1' or receipt.get('company_uuid')!=COMPANY or receipt.get('plan_sha256')!=digest:raise ValueError('Unrecognized native supplement receipt')
    output=Path(args.output);output.parent.mkdir(parents=True,exist_ok=True);output.write_text(json.dumps(receipt,indent=2)+'\n',encoding='utf-8')
    print(json.dumps(receipt));return 0
if __name__=='__main__':
    try:sys.exit(main())
    except (ValueError,OSError) as error:print('Supplement failed: '+str(error),file=sys.stderr);sys.exit(1)

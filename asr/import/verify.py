"""Authorized read-only post-import verification. No native app files copied or mutated."""
import argparse,base64,hashlib,json,subprocess,sys
from pathlib import Path
from stage import COMPANY,validate_snapshot

def compose_body(envelope):
    module=Path(__file__).resolve().parent
    body='<?php\n'
    for name in ['ImportPlan.php','VerifyInventory.php']:
        body+=(module/name).read_text(encoding='utf-8').replace('<?php','',1).replace('namespace ASR\\Import;','namespace ASR\\Import {',1)+'\n}\n'
    body+='namespace {\n$asrVerifyInput=json_decode(base64_decode("'+base64.b64encode(json.dumps(envelope).encode()).decode()+'"),true,512,JSON_THROW_ON_ERROR);\n'
    source=(module/'verify-native.php').read_text(encoding='utf-8').replace('<?php','',1)
    for name in ['ImportPlan.php','VerifyInventory.php']:source=source.replace("require '/fleetbase/api/app/AsrImport/"+name+"';",'')
    return body+source+'\n}\n'

def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--plan',required=True);parser.add_argument('--snapshot',required=True)
    parser.add_argument('--expected-plan-sha',required=True);parser.add_argument('--docker',required=True);parser.add_argument('--output',required=True)
    parser.add_argument('--legacy-snapshot-windows1252',action='store_true',help='Explicitly invert the proven historical Windows capture encoding bug after original hash validation; retain strict preservation checks.')
    args=parser.parse_args();raw=Path(args.plan).read_bytes();snapshot_raw=Path(args.snapshot).read_bytes();digest=hashlib.sha256(raw).hexdigest();plan=json.loads(raw)
    if digest!=args.expected_plan_sha:raise ValueError('Exact reviewed plan fingerprint required.')
    validate_snapshot(json.loads(snapshot_raw));snapshot_digest=hashlib.sha256(snapshot_raw).hexdigest()
    if plan.get('company_uuid')!=COMPANY or plan.get('fingerprints',{}).get('snapshot_sha256')!=snapshot_digest:raise ValueError('Wrong scope or verification snapshot fingerprint.')
    envelope={'ack':'REVIEWED_RESOURCE_CREATION_ONLY','plan_base64':base64.b64encode(raw).decode(),'snapshot_base64':base64.b64encode(snapshot_raw).decode(),
        'expected_plan_sha256':digest,'expected_snapshot_sha256':snapshot_digest,'legacy_snapshot_windows1252':args.legacy_snapshot_windows1252}
    result=subprocess.run([args.docker,'exec','-i','mapa-live-access-api-1','php','-d','xdebug.mode=off'],input=compose_body(envelope),text=True,encoding='utf-8',errors='strict',capture_output=True,timeout=120)
    if result.returncode:raise ValueError('Read-only native verification failed; runtime diagnostics withheld.')
    report=json.loads(result.stdout)
    if report.get('contract')!='asr-source-resource-verification-v1' or report.get('company_uuid')!=COMPANY or report.get('plan_sha256')!=digest or report.get('read_only') is not True:raise ValueError('Unrecognized read-only verification receipt.')
    output=Path(args.output);output.parent.mkdir(parents=True,exist_ok=True);output.write_text(json.dumps(report,indent=2,ensure_ascii=False)+'\n',encoding='utf-8')
    print(json.dumps({'state':report['state'],'read_only':True,'private_report':str(output),'counts':report['counts'],'imported_vehicle_types':report['imported_vehicle_types'],
        'error_count':len(report['errors']),'warning_count':len(report['warnings']),'production_writes':0}));return 0 if report['state']=='PASS' else 1

if __name__=='__main__':
    try:sys.exit(main())
    except (ValueError,OSError) as error:print('Verification failed: '+str(error),file=sys.stderr);sys.exit(1)

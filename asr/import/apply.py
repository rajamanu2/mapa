"""Review guard/launcher. Default validates fingerprints only; --apply is resource-only."""
import argparse,base64,hashlib,json,subprocess,sys
from pathlib import Path
from stage import COMPANY,validate_snapshot

def compose_body(envelope):
    source=Path(__file__).with_name('apply-native.php').read_text(encoding='utf-8')
    policy=Path(__file__).with_name('ImportPlan.php').read_text(encoding='utf-8')
    shared=Path(__file__).with_name('ImportApply.php').read_text(encoding='utf-8')
    encoded=base64.b64encode(json.dumps(envelope).encode()).decode()
    body='<?php\n'+policy.replace('<?php','',1).replace('namespace ASR\\Import;','namespace ASR\\Import {',1)+'\n}\n'
    body+=shared.replace('<?php','',1).replace('namespace ASR\\Import;','namespace ASR\\Import {',1)+'\n}\nnamespace {\n'
    body+='$asrImportInput=json_decode(base64_decode("'+encoded+'"),true,512,JSON_THROW_ON_ERROR);\n'
    body+=source.replace('<?php','',1).replace("require '/fleetbase/api/app/AsrImport/ImportPlan.php';",'').replace("require '/fleetbase/api/app/AsrImport/ImportApply.php';",'')+'\n}\n'
    return body

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--plan',required=True);parser.add_argument('--snapshot',required=True);parser.add_argument('--expected-plan-sha',required=True)
    parser.add_argument('--docker');parser.add_argument('--apply',action='store_true')
    args=parser.parse_args();raw=Path(args.plan).read_bytes();snapshot_raw=Path(args.snapshot).read_bytes()
    digest=hashlib.sha256(raw).hexdigest();snapshot_digest=hashlib.sha256(snapshot_raw).hexdigest();plan=json.loads(raw)
    if digest!=args.expected_plan_sha:raise ValueError('Plan differs from the exact reviewed fingerprint.')
    validate_snapshot(json.loads(snapshot_raw))
    if plan.get('company_uuid')!=COMPANY or plan.get('driver_import_allowed') is not False or plan.get('fingerprints',{}).get('snapshot_sha256')!=snapshot_digest:raise ValueError('Wrong scope or snapshot fingerprint.')
    if plan.get('fingerprints',{}).get('staging_source_sha256')!=hashlib.sha256(Path(__file__).with_name('stage.py').read_bytes()).hexdigest():raise ValueError('Staging source changed; create and review a fresh plan.')
    if not args.apply:
        print(json.dumps({'dry_run':True,'plan_sha256':digest,'summary':plan['summary'],'production_writes':0}));return 0
    if not args.docker:raise ValueError('Explicit Docker executable is required for reviewed apply.')
    envelope={'ack':'REVIEWED_RESOURCE_CREATION_ONLY','plan_base64':base64.b64encode(raw).decode(),'expected_plan_sha256':digest,'expected_snapshot_sha256':snapshot_digest}
    # Include pure policy as source: no file copy or container mutation needed.
    body=compose_body(envelope)
    completed=subprocess.run([args.docker,'exec','-i','mapa-live-access-api-1','php','-d','xdebug.mode=off'],input=body,text=True,capture_output=True,timeout=180)
    if completed.returncode:print('Resource import receipt unconfirmed. Inspect or retry the exact reviewed plan before replacing it; private diagnostics were not emitted.',file=sys.stderr);return 1
    result=json.loads(completed.stdout)
    if result.get('contract')!='asr-source-resource-apply-v1' or result.get('company_uuid')!=COMPANY or result.get('plan_sha256')!=digest:raise ValueError('Unrecognized apply receipt; inspect native data before retrying.')
    print(json.dumps(result));return 0

if __name__=='__main__':
    try:sys.exit(main())
    except (ValueError,OSError) as error:print('Review guard failed: '+str(error),file=sys.stderr);sys.exit(1)

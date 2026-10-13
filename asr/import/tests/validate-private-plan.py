"""Pure policy-check the exact private plan in an offline cached image; no app/DB."""
import argparse,base64,hashlib,json,subprocess,sys
from pathlib import Path

parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('--plan',required=True);parser.add_argument('--expected-plan-sha',required=True);parser.add_argument('--docker',required=True)
args=parser.parse_args();module=Path(__file__).resolve().parent.parent
raw=Path(args.plan).read_bytes();digest=hashlib.sha256(raw).hexdigest()
if digest!=args.expected_plan_sha:raise SystemExit('Exact reviewed plan fingerprint required.')
plan=json.loads(raw)
envelope={'ack':'REVIEWED_RESOURCE_CREATION_ONLY','plan_base64':base64.b64encode(raw).decode(),'expected_plan_sha256':digest,'expected_snapshot_sha256':plan['fingerprints']['snapshot_sha256']}
encoded=base64.b64encode(json.dumps(envelope).encode()).decode()
body='<?php\nrequire "/qa/import/ImportPlan.php";\n'
body+='$input=json_decode(base64_decode("'+encoded+'"),true,512,JSON_THROW_ON_ERROR);\n'
body+='try { ASR\\Import\\ImportPlan::unpack($input); echo "PASS exact reviewed private resource plan policy; no app/database bootstrap.\\n"; } catch(Throwable $e) { fwrite(STDERR,"FAIL private plan policy: ".get_class($e).": ".$e->getMessage()."\\n"); exit(1); }\n'
command=[args.docker,'run','-i','--rm','--pull','never','--network','none','--read-only','--cap-drop','ALL','--security-opt','no-new-privileges','--mount','type=bind,source='+str(module)+',target=/qa/import,readonly','--entrypoint','php','mapa-cloud-api:pilot']
result=subprocess.run(command,input=body,text=True,capture_output=True,timeout=60)
if result.returncode or not result.stdout.startswith('PASS exact reviewed'):
    print('FAIL exact reviewed plan policy; private rows were not emitted.',file=sys.stderr)
    if result.stderr.startswith('FAIL private plan policy: '):print(result.stderr.strip(),file=sys.stderr)
    sys.exit(1)
print(result.stdout.strip());print('plan_sha256: '+digest)

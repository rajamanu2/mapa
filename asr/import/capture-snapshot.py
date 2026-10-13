"""Authorized read-only runtime inspection; private rows go only to the explicit output."""
import argparse, json, subprocess, sys
from pathlib import Path

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--docker',required=True)
    parser.add_argument('--output',required=True)
    parser.add_argument('--container',default='mapa-live-access-api-1',choices=['mapa-live-access-api-1'])
    args=parser.parse_args()
    source=Path(__file__).with_name('snapshot-native.php').read_text(encoding='utf-8')
    completed=subprocess.run([args.docker,'exec','-i',args.container,'php','-d','xdebug.mode=off'],input=source,text=True,encoding='utf-8',errors='strict',capture_output=True,timeout=90)
    if completed.returncode:
        print('Read-only snapshot failed; private rows and runtime diagnostics were not emitted.',file=sys.stderr);return 1
    try:
        snapshot=json.loads(completed.stdout)
        assert snapshot['contract']=='asr-native-import-snapshot-v1' and snapshot['read_only'] is True
        assert snapshot['company_uuid']=='29eff7bc-7b9b-4d8a-b1b4-55f33681bd2d'
    except (ValueError,AssertionError,KeyError):
        print('Invalid read-only snapshot; nothing saved.',file=sys.stderr);return 1
    output=Path(args.output);output.parent.mkdir(parents=True,exist_ok=True)
    output.write_text(json.dumps(snapshot,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
    print(json.dumps({'read_only':True,'saved_private_snapshot':str(output),'counts':{key:len(snapshot[key]) for key in ['vehicles','places','users','drivers']}}))
    return 0

if __name__=='__main__':sys.exit(main())

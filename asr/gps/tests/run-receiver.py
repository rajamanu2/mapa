"""Disposable internal Docker network only. No production volumes, ports or credentials."""
import argparse,importlib.util,json,subprocess,tempfile,uuid
from pathlib import Path
parser=argparse.ArgumentParser();parser.add_argument('--docker',default='docker');args=parser.parse_args()
root=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('prepare',root/'prepare-local.py');prepare=importlib.util.module_from_spec(spec);spec.loader.exec_module(prepare)
tag=uuid.uuid4().hex[:8];network='mapa-gt06-qa-'+tag;created=[];net=None
def run(*cmd,check=True):
    r=subprocess.run([args.docker,*cmd],capture_output=True,text=True)
    if check and r.returncode:raise RuntimeError(r.stdout+r.stderr)
    return r
try:
    for image in ('traccar/traccar:6.16.0-alpine','python:3.12-alpine'):run('image','inspect',image)
    net=run('network','create','--internal','--label','mapa.qa=gt06',network).stdout.strip()
    with tempfile.TemporaryDirectory(prefix='mapa-gt06-qa-') as folder:
        folder=Path(folder);secret='b'*64
        (folder/'traccar.xml').write_text(prepare.receiver_xml(secret))
        (folder/'bridge.json').write_text(json.dumps({'local_test_only':True,'ingest_url':'http://127.0.0.1:8897/mapa/mobile/v1/tracking/ingest','forward_secret':secret,'devices':{'123456789012345':'a'*64}}))
        receiver=run('run','-d','--name',network+'-receiver','--label','mapa.qa=gt06','--network',network,'--network-alias','receiver',
            '--tmpfs','/opt/traccar/data:rw,size=268435456','--mount',f'type=bind,source={folder}/traccar.xml,target=/opt/traccar/conf/traccar.xml,readonly','traccar/traccar:6.16.0-alpine').stdout.strip();created.append(receiver)
        print('Started isolated GT06 receiver; no published ports or production connections.',flush=True)
        client=run('create','--name',network+'-client','--label','mapa.qa=gt06','--network',network,'--network-alias','bridge','--read-only','--tmpfs','/tmp',
            '--mount',f'type=bind,source={root},target=/app,readonly','--mount',f'type=bind,source={folder},target=/qa,readonly',
            'python:3.12-alpine','python','-B','/app/tests/receiver-client.py').stdout.strip();created.append(client)
        result=run('start','-a',client,check=False);print(result.stdout,flush=True)
        if result.returncode:
            print(result.stderr);print(run('logs','--tail','40',receiver,check=False).stdout)
            raise RuntimeError('GT06 receiver test failed')
finally:
    for container in reversed(created):
        state=json.loads(run('inspect',container).stdout)[0]
        if state['Config']['Labels'].get('mapa.qa')=='gt06' and state['Name'].startswith('/'+network):run('rm','-f',container)
    if net:
        state=json.loads(run('network','inspect',net).stdout)[0]
        if state['Internal'] and state['Labels'].get('mapa.qa')=='gt06':run('network','rm',net)
    print('Disposed only isolated GT06 QA resources.',flush=True)

"""Isolated synthetic cURL checks; no app bootstrap, DB, published ports or live resources."""
import json,subprocess,uuid
from pathlib import Path
docker=r'C:/Users/roysh/AppData/Local/Programs/DockerDesktop/resources/bin/docker.exe'
name='mapa-road-qa-'+uuid.uuid4().hex[:8]
def run(args):
    return subprocess.run([docker,*args],text=True,capture_output=True,check=True)
network=run(['network','create','--internal','--label','mapa.qa=private-road',name]).stdout.strip()
try:
    result=run(['run','--rm','--network',name,'--read-only','--tmpfs','/tmp:rw,size=16777216','-e','MAPA_ROAD_QA_ISOLATED=1','--mount','type=bind,source='+str(Path(__file__).resolve().parent.parent)+',target=/work,readonly','--entrypoint','php','mapa-cloud-api:pilot','/work/tests/private-road-transport.php'])
    print(result.stdout,end='')
except subprocess.CalledProcessError as error:
    print(error.stdout,error.stderr);raise
finally:
    state=json.loads(run(['network','inspect',network]).stdout)[0]
    if state['Name']==name and state['Internal'] and state.get('Labels',{}).get('mapa.qa')=='private-road':run(['network','rm',network])

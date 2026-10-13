"""New isolated tmpfs source ownership database; never use original application data."""
import argparse,json,pathlib,subprocess,time,uuid
p=argparse.ArgumentParser();p.add_argument('--docker',required=True);a=p.parse_args()
root=pathlib.Path(__file__).resolve().parent;mobile=root.parent/'mobile'
name='mapa-source-qa-'+uuid.uuid4().hex[:10];network=name+'-net';db_id=net_id=None
def run(args,check=True):
    r=subprocess.run([a.docker,*args],capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=180)
    if check and r.returncode:raise RuntimeError(r.stderr or r.stdout)
    return r
try:
    net_id=run(['network','create','--internal','--label','mapa.qa=source-dispatch',network]).stdout.strip()
    db_id=run(['run','-d','--name',name,'--network',network,'--label','mapa.qa=source-dispatch','--tmpfs','/var/lib/mysql:rw,size=512m','-e','MYSQL_ROOT_PASSWORD=DEMO-source-qa-only','mysql:8.0-oracle']).stdout.strip()
    for _ in range(60):
        if run(['exec',db_id,'mysqladmin','ping','-h','127.0.0.1','-uroot','-pDEMO-source-qa-only'],False).returncode==0:break
        time.sleep(1)
    r=run(['run','--rm','--network',network,'--label','mapa.qa=source-dispatch','--mount',f'type=bind,source={root},target=/work,readonly','--mount',f'type=bind,source={mobile},target=/mobile,readonly','-e',f'QA_DB_HOST={name}','--entrypoint','php','mapa-cloud-api:pilot','/work/test-dispatch.php'],False)
    print(r.stdout)
    if r.returncode or 'SOURCE DISPATCH PASS' not in r.stdout:raise RuntimeError(r.stderr or 'Missing acceptance marker')
    (root/'dispatch-test-results.txt').write_text(r.stdout,encoding='utf-8')
finally:
    if db_id:
        state=json.loads(run(['inspect',db_id]).stdout)[0]
        if state['Name']=='/'+name and state['Config']['Labels'].get('mapa.qa')=='source-dispatch':run(['rm','-f',db_id])
    if net_id:
        state=json.loads(run(['network','inspect',net_id]).stdout)[0]
        if state['Name']==network and state['Internal'] and state['Labels'].get('mapa.qa')=='source-dispatch':run(['network','rm',net_id])

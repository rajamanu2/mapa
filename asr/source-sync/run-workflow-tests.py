import argparse,subprocess,uuid,json,time,pathlib
p=argparse.ArgumentParser();p.add_argument('--docker',required=True);a=p.parse_args()
root=pathlib.Path(__file__).resolve().parent
name='mapa-source-qa-'+uuid.uuid4().hex[:10];network=name+'-net';db_id=None;net_id=None
def run(args,check=True):
    r=subprocess.run([a.docker,*args],capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=180)
    if check and r.returncode:raise RuntimeError(r.stderr)
    return r
try:
    net_id=run(['network','create','--internal','--label','mapa.qa=source-workflow',network]).stdout.strip()
    db_id=run(['run','-d','--name',name,'--network',network,'--label','mapa.qa=source-workflow','--tmpfs','/var/lib/mysql:rw,size=512m','-e','MYSQL_ROOT_PASSWORD=DEMO-source-qa-only','mysql:8.0-oracle']).stdout.strip()
    for _ in range(60):
        if run(['exec',db_id,'mysqladmin','ping','-h','127.0.0.1','-uroot','-pDEMO-source-qa-only'],False).returncode==0:break
        time.sleep(1)
    result=run(['run','--rm','--network',network,'--label','mapa.qa=source-workflow','--mount',f'type=bind,source={root},target=/work,readonly','--mount',f'type=bind,source={root.parent / "mobile"},target=/mobile,readonly','-e',f'QA_DB_HOST={name}','--entrypoint','php','mapa-cloud-api:pilot','/work/test-workflow.php'],False)
    print(result.stdout)
    if result.returncode or 'SOURCE WORKFLOW PASS' not in result.stdout:raise RuntimeError(result.stderr or 'Missing workflow pass marker')
    (root/'workflow-test-results.txt').write_text(result.stdout,encoding='utf-8')
    for file,marker,output in [('test-master.php','SOURCE MASTER PASS','master-test-results.txt'),('test-coordination-policy.php','SOURCE COORDINATION POLICY PASS','coordination-policy-test-results.txt'),('test-manual.php','SOURCE MANUAL FORMS PASS','manual-form-test-results.txt'),('test-photos.php','SOURCE REPAIR PHOTOS PASS','repair-photo-test-results.txt'),('test-commands.php','SOURCE COMMANDS PASS','coordinated-commands-test-results.txt')]:
        check=run(['run','--rm','--network',network,'--label','mapa.qa=source-workflow','--mount',f'type=bind,source={root},target=/work,readonly','--mount',f'type=bind,source={root.parent / "mobile"},target=/mobile,readonly','-e',f'QA_DB_HOST={name}','--entrypoint','php','mapa-cloud-api:pilot','/work/'+file],False)
        print(check.stdout)
        if check.returncode or marker not in check.stdout:raise RuntimeError(check.stderr or 'Missing '+marker)
        (root/output).write_text(check.stdout,encoding='utf-8')
finally:
    if db_id:
        item=json.loads(run(['inspect',db_id]).stdout)[0]
        if item['Name']=='/'+name and item['Config']['Labels'].get('mapa.qa')=='source-workflow':run(['rm','-f',db_id])
    if net_id:
        item=json.loads(run(['network','inspect',net_id]).stdout)[0]
        if item['Name']==network and item['Internal'] and item['Labels'].get('mapa.qa')=='source-workflow':run(['network','rm',net_id])

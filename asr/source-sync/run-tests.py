import argparse,subprocess,uuid,json,time,pathlib
p=argparse.ArgumentParser();p.add_argument('--docker',required=True);a=p.parse_args()
root=pathlib.Path(__file__).resolve().parent
name='mapa-source-qa-'+uuid.uuid4().hex[:10];network=name+'-net';db_id=None;net_id=None
def run(args,check=True):
    result=subprocess.run([a.docker,*args],capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=180)
    if check and result.returncode:raise RuntimeError(result.stderr)
    return result
try:
    net_id=run(['network','create','--internal','--label','mapa.qa=source-sync',network]).stdout.strip()
    db_id=run(['run','-d','--name',name,'--network',network,'--label','mapa.qa=source-sync','--tmpfs','/var/lib/mysql:rw,size=512m',
        '-e','MYSQL_DATABASE=mapa_source_qa','-e','MYSQL_ROOT_PASSWORD=DEMO-source-qa-only','mysql:8.0-oracle']).stdout.strip()
    for _ in range(60):
        if run(['exec',db_id,'mysqladmin','ping','-h','127.0.0.1','-uroot','-pDEMO-source-qa-only'],False).returncode==0:break
        time.sleep(1)
    result=run(['run','--rm','--network',network,'--label','mapa.qa=source-sync','--mount',f'type=bind,source={root},target=/work,readonly',
        '-e',f'QA_DB_HOST={name}','--entrypoint','php','mapa-cloud-api:pilot','/work/test.php'],False)
    print(result.stdout)
    if result.returncode or 'SOURCE SYNC PASS' not in result.stdout:raise RuntimeError(result.stderr or 'Missing pass marker')
    outputs=[result.stdout]
    if (root/'test-commands.php').is_file():
        commands=run(['run','--rm','--network',network,'--label','mapa.qa=source-sync','--mount',f'type=bind,source={root},target=/work,readonly',
            '-e',f'QA_DB_HOST={name}','--entrypoint','php','mapa-cloud-api:pilot','/work/test-commands.php'],False)
        print(commands.stdout)
        if commands.returncode or 'SOURCE COMMANDS PASS' not in commands.stdout:raise RuntimeError(commands.stderr or 'Missing command pass marker')
        outputs.append(commands.stdout)
    (root/'test-results.txt').write_text('\n'.join(outputs),encoding='utf-8')
finally:
    if db_id:
        item=json.loads(run(['inspect',db_id]).stdout)[0]
        if item['Name']=='/'+name and item['Config']['Labels'].get('mapa.qa')=='source-sync':run(['rm','-f',db_id])
    if net_id:
        item=json.loads(run(['network','inspect',net_id]).stdout)[0]
        if item['Name']==network and item['Internal'] and item['Labels'].get('mapa.qa')=='source-sync':run(['network','rm',net_id])

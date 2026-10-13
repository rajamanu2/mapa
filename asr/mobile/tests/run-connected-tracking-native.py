"""Fresh isolated MAPA QA containers only; never attach to live Docker networks/volumes."""
import argparse,json,re,subprocess,sys,uuid
from pathlib import Path
parser=argparse.ArgumentParser()
parser.add_argument('--docker',default='docker')
args=parser.parse_args()
module=Path(__file__).resolve().parent.parent
suffix=uuid.uuid4().hex[:8]
network='mapa-crew-qa-net-'+suffix
db='mapa-crew-qa-db-'+suffix
network_id=db_id=None
result=1
log=[]
def record(message):
    print(message,flush=True);log.append(message)
def run(arguments,check=True):
    process=subprocess.run([args.docker,*arguments],capture_output=True,text=True)
    if check and process.returncode:raise RuntimeError(process.stderr.strip() or process.stdout.strip())
    return process
try:
    # Explicit cached image names only. No pull, production volume or published port.
    for image in ['mysql:8.0-oracle','mapa-cloud-api:pilot']:run(['image','inspect',image])
    network_id=run(['network','create','--internal','--label','mapa.qa=crew-enrollment',network]).stdout.strip()
    db_id=run(['run','-d','--network',network,'--name',db,'--label','mapa.qa=crew-enrollment','--tmpfs','/var/lib/mysql:rw,size=1073741824','-e','MYSQL_DATABASE=mapa_crew_qa','-e','MYSQL_ROOT_PASSWORD=DEMO-crew-qa-local-only','mysql:8.0-oracle','--innodb-buffer-pool-size=32M','--innodb-redo-log-capacity=32M','--performance-schema=OFF']).stdout.strip()
    record('Fresh internal QA network and disposable MySQL started; no production volumes or ports.')
    command=['run','--rm','--network',network,'--read-only','--tmpfs','/tmp:rw,size=268435456','--label','mapa.qa=crew-enrollment','--mount','type=bind,source='+str(module)+',target=/fleetbase/api/app/MapaMobile,readonly','--entrypoint','php']
    environment={'MAPA_CREW_QA_ISOLATED':'1','DB_CONNECTION':'mysql','DB_HOST':db,'DB_PORT':'3306','DB_DATABASE':'mapa_crew_qa','DB_USERNAME':'root','DB_PASSWORD':'DEMO-crew-qa-local-only','DATABASE_URL':'','APP_ENV':'testing','APP_KEY':'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=','CACHE_DRIVER':'array','CACHE_STORE':'array','QUEUE_CONNECTION':'sync','MAIL_MAILER':'array','BROADCAST_DRIVER':'log','MAPA_MOBILE_ENABLED':'false','MAPA_VOICE_ENABLED':'false','MAPA_MOBILE_PUSH_ENABLED':'false','LOG_CHANNEL':'stderr'}
    for key,value in environment.items():command+=['-e',key+'='+value]
    command+=['mapa-cloud-api:pilot','/fleetbase/api/app/MapaMobile/tests/connected-tracking-dedicated.php']
    test=run(command,False)
    record(test.stdout)
    if test.stderr:record(test.stderr)
    passed=bool(re.search(r'^MAPA_CONNECTED_SUITE_COMPLETE: \d+ checks passed\.$',test.stdout,re.MULTILINE))
    result=0 if test.returncode==0 and passed else 1
    record('Dedicated native test result: '+('PASS' if result==0 else 'FAIL (no acceptance marker)'))
    if result:
        record(run(['logs','--tail','35',db_id],False).stdout)
        record(run(['logs','--tail','35',db_id],False).stderr)
except Exception as error:
    record(str(error));result=1
finally:
    if db_id:
        state=json.loads(run(['inspect',db_id]).stdout)[0]
        if state.get('Config',{}).get('Labels',{}).get('mapa.qa')=='crew-enrollment' and state['Name']=='/'+db:run(['rm','-f',db_id])
    if network_id:
        state=json.loads(run(['network','inspect',network_id]).stdout)[0]
        if state.get('Internal') is True and state.get('Labels',{}).get('mapa.qa')=='crew-enrollment' and state['Name']==network:run(['network','rm',network_id])
    record('Disposable QA resources removed; existing MAPA services untouched.')
    (module/'tests'/'connected-tracking-native-result.txt').write_text('\n'.join(log),encoding='utf-8')
sys.exit(result)

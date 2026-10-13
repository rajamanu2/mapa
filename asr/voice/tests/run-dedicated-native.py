"""Fresh isolated MAPA QA containers only; never attach to live Docker networks/volumes."""
import argparse,hashlib,json,re,subprocess,sys,uuid
from pathlib import Path
parser=argparse.ArgumentParser()
parser.add_argument('--docker',default='docker')
parser.add_argument('--api-image',default='mapa-cloud-api:pilot')
parser.add_argument('--suite',choices=['native','automatic','arrivals'],default='native')
args=parser.parse_args()
if not re.fullmatch(r'[A-Za-z0-9._:/@-]+',args.api_image):parser.error('Use an exact cached Docker image reference.')
module=Path(__file__).resolve().parent.parent
suffix=uuid.uuid4().hex[:8]
network='mapa-voice-qa-net-'+suffix
db='mapa-voice-qa-db-'+suffix
qa_image='mapa-voice-qa-api:'+suffix
network_id=db_id=qa_image_id=None
result=1
log=[]
snapshot=module/'tests'/'voice-status-current-qa.php'
snapshot_bytes=(module/'VoiceService.php').read_bytes()
snapshot.write_bytes(snapshot_bytes)
def record(message):
    print(message,flush=True);log.append(message)
def run(arguments,check=True,input_text=None):
    process=subprocess.run([args.docker,*arguments],capture_output=True,text=True,input=input_text,timeout=360)
    if check and process.returncode:raise RuntimeError(process.stderr.strip() or process.stdout.strip())
    return process
try:
    # Explicit cached image names only. No pull, production volume or published port.
    for image in ['mysql:8.0-oracle',args.api_image]:run(['image','inspect',image])
    # Remove cached configuration in a NEW labelled QA image only. Original image remains unchanged.
    dockerfile='FROM '+args.api_image+'\nUSER root\nRUN rm -f /fleetbase/api/bootstrap/cache/config.php\n'
    run(['build','--pull=false','--network=none','--label','mapa.qa=voice-eligibility','--label','mapa.qa.run='+suffix,'-t',qa_image,'-'],input_text=dockerfile)
    qa_image_id=run(['image','inspect','--format','{{.Id}}',qa_image]).stdout.strip()
    network_id=run(['network','create','--internal','--label','mapa.qa=voice-eligibility',network]).stdout.strip()
    db_id=run(['run','-d','--network',network,'--name',db,'--label','mapa.qa=voice-eligibility','--memory','1g','--cpus','1','--tmpfs','/var/lib/mysql:rw,size=1073741824','-e','MYSQL_DATABASE=mapa_voice_qa','-e','MYSQL_ROOT_PASSWORD=DEMO-voice-qa-local-only','mysql:8.0-oracle','--innodb-buffer-pool-size=32M','--innodb-redo-log-capacity=128M','--performance-schema=OFF']).stdout.strip()
    record('VoiceService frozen SHA256: '+hashlib.sha256(snapshot_bytes).hexdigest())
    record('QA source image: '+args.api_image+'; dedicated suite: '+args.suite)
    record('Fresh internal QA network and disposable MySQL started; no production volumes or ports.')
    command=['run','--rm','--network',network,'--read-only','--memory','768m','--cpus','1','--tmpfs','/tmp:rw,size=268435456','--label','mapa.qa=voice-eligibility','--mount','type=bind,source='+str(module)+',target=/fleetbase/api/app/MapaVoice,readonly','--mount','type=bind,source='+str(module.parent/'mobile')+',target=/fleetbase/api/app/MapaMobile,readonly','--entrypoint','php']
    environment={'MAPA_VOICE_QA_ISOLATED':'1','DB_CONNECTION':'mysql','DB_HOST':db,'DB_PORT':'3306','DB_DATABASE':'mapa_voice_qa','DB_USERNAME':'root','DB_PASSWORD':'DEMO-voice-qa-local-only','DATABASE_URL':'','APP_ENV':'testing','APP_KEY':'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=','CACHE_DRIVER':'array','CACHE_STORE':'array','QUEUE_CONNECTION':'sync','MAIL_MAILER':'array','BROADCAST_DRIVER':'log','MAPA_MOBILE_ENABLED':'false','MAPA_VOICE_ENABLED':'false','MAPA_MOBILE_PUSH_ENABLED':'false','LOG_CHANNEL':'stderr'}
    for key,value in environment.items():command+=['-e',key+'='+value]
    command+=['-e','MAPA_VOICE_QA_SUITE='+args.suite,qa_image,'/fleetbase/api/app/MapaVoice/tests/dedicated-native.php']
    test=run(command,False)
    record(test.stdout)
    if test.stderr:record(test.stderr)
    passed=('MAPA_ARRIVALS_SUITE_COMPLETE:' in test.stdout) if args.suite=='arrivals' else bool(re.search(r'\"ok\": true',test.stdout)) and '"production_rows_written": 0' in test.stdout
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
        record('Disposable QA database state: '+json.dumps({k:state['State'].get(k) for k in ['Status','OOMKilled','ExitCode']}))
        if state.get('Config',{}).get('Labels',{}).get('mapa.qa')=='voice-eligibility' and state['Name']=='/'+db:run(['rm','-f',db_id])
    if network_id:
        state=json.loads(run(['network','inspect',network_id]).stdout)[0]
        if state.get('Internal') is True and state.get('Labels',{}).get('mapa.qa')=='voice-eligibility' and state['Name']==network:run(['network','rm',network_id])
    if qa_image_id:
        state=json.loads(run(['image','inspect',qa_image_id]).stdout)[0]
        labels=state.get('Config',{}).get('Labels',{})
        if labels.get('mapa.qa')=='voice-eligibility' and labels.get('mapa.qa.run')==suffix:run(['image','rm',qa_image])
    if snapshot.is_file() and snapshot.read_bytes()==snapshot_bytes:snapshot.unlink()
    record('Disposable QA resources removed; existing MAPA services untouched.')
    (module/'tests'/('dedicated-'+args.suite+'-result.txt')).write_text('\n'.join(log),encoding='utf-8')
sys.exit(result)

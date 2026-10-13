"""Non-mutating acceptance against ASR Health using an immediately revoked test token.
No passwords, personal records, tokens or response bodies are printed.
"""
import argparse,base64,http.client,json,os,pathlib,secrets,subprocess,urllib.request,urllib.error,urllib.parse
p=argparse.ArgumentParser();p.add_argument('--base',required=True);p.add_argument('--docker',required=True);a=p.parse_args()
container='asr-fleetbase-pilot-application-1'
owner='3c648de0-64c5-4b0c-9197-35994ffd6bba';company='29eff7bc-7b9b-4d8a-b1b4-55f33681bd2d'
issue=r'''<?php
require '/fleetbase/api/vendor/autoload.php';$app=require '/fleetbase/api/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$u=Fleetbase\Models\User::where('uuid','3c648de0-64c5-4b0c-9197-35994ffd6bba')->firstOrFail();
if($u->company_uuid!=='29eff7bc-7b9b-4d8a-b1b4-55f33681bd2d'||$u->status!=='active'||!$u->isAdmin())throw new RuntimeException('Owner mismatch');
$name='mapa-live-access-check-'.bin2hex(random_bytes(8));$t=$u->createToken($name,['*'],now()->addMinutes(10));
echo json_encode(['token'=>$t->plainTextToken,'id'=>$t->accessToken->getKey(),'name'=>$name]);
'''
def docker_php(script):
    r=subprocess.run([a.docker,'exec','-i',container,'php','-d','xdebug.mode=off'],input=script,text=True,capture_output=True)
    if r.returncode:raise RuntimeError('Native acceptance-token operation failed; diagnostic output suppressed.')
    return r.stdout
cred=json.loads(docker_php(issue));origin=a.base.rstrip('/');token=cred['token'];socket_cookie=None
def fetch(path,method='GET',payload=None,authorized=False):
    headers={'Accept':'application/json','User-Agent':'MAPA-Live-Acceptance/1.0'}
    if authorized:headers['Authorization']='Bearer '+token
    body=None if payload is None else json.dumps(payload).encode()
    if body is not None:headers['Content-Type']='application/json'
    req=urllib.request.Request(origin+path,data=body,headers=headers,method=method)
    try:r=urllib.request.urlopen(req,timeout=35)
    except urllib.error.HTTPError as e:r=e
    with r:return r.status,r.headers,r.read(4*1024*1024)
def check(path,expected=200,**kw):
    status,headers,body=fetch(path,**kw)
    assert status==expected,f'{path}: expected {expected}, got {status}'
    print('PASS',status,path,flush=True)
    return headers,body
try:
    check('/healthz');check('/fleet-ops')
    _,raw=check('/fleetbase.config.json');assert json.loads(raw)['API_HOST']=='https://mapa-asr-health.pages.dev/api'
    check('/api/int/v1/settings/branding');check('/api/int/v1/onboard/should-onboard')
    for path in ['/api/int/v1/users/me','/api/int/v1/vehicles','/socketcluster/']:check(path,401)
    check('/api/int/v1/vehicles',401,method='POST',payload={})
    for path in ['/.env','/private/live-access/live.env','/api/int/v1/auth/sign-up','/mapa/voice/v1/bridge/events','/media']:
        check(path,404)
    headers,raw=check('/api/int/v1/users/me',authorized=True)
    user=json.loads(raw)['user'];assert user['is_admin'] is True and user['company_uuid']==company and user['status']=='active'
    socket_cookie=next((value.split(';')[0] for value in headers.get_all('Set-Cookie',[]) if value.startswith('mapa_console_socket=')), '')
    assert socket_cookie.startswith('mapa_console_socket='),'No authenticated socket cookie'
    for path in ['/api/int/v1/auth/session','/api/int/v1/auth/bootstrap','/api/int/v1/vehicles','/api/int/v1/drivers','/api/int/v1/places','/api/int/v1/orders','/api/int/v1/users','/api/int/v1/fleet-ops/settings/map']:
        check(path,authorized=True)
    # Native CreateOrderRequest requires order_config_uuid and validates before
    # createRecordFromRequest. Deliberately omit it; no order can be persisted.
    _, invalid=check('/api/int/v1/orders',422,method='POST',payload={'order':{'dispatch':False}},authorized=True)
    assert json.loads(invalid).get('errors'),'Expected native validation errors'
    # No native records are created, updated, dispatched, messaged or deleted.
    url=urllib.parse.urlparse(origin);conn=(http.client.HTTPSConnection if url.scheme=='https' else http.client.HTTPConnection)(url.hostname,url.port,timeout=15)
    key=base64.b64encode(secrets.token_bytes(16)).decode()
    conn.request('GET','/socketcluster/',headers={'Upgrade':'websocket','Connection':'Upgrade','Sec-WebSocket-Key':key,'Sec-WebSocket-Version':'13','Origin':'https://mapa-asr-health.pages.dev','Cookie':socket_cookie,'User-Agent':'MAPA-Live-Acceptance/1.0'})
    response=conn.getresponse();assert response.status==101,f'Authenticated socket expected 101, got {response.status}'
    conn.close();print('PASS 101 authenticated administrator WebSocket',flush=True)
finally:
    cleanup=r'''<?php
require '/fleetbase/api/vendor/autoload.php';$app=require '/fleetbase/api/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$u=Fleetbase\Models\User::where('uuid','3c648de0-64c5-4b0c-9197-35994ffd6bba')->firstOrFail();
'''+"$n=$u->tokens()->where('id',"+json.dumps(cred['id'])+")->where('name',"+json.dumps(cred['name'])+")->delete();echo $n;"
    result=docker_php(cleanup).strip();assert result=='1','Acceptance token cleanup failed'
    print('Temporary acceptance token revoked; no operational data changed.',flush=True)

import argparse,json,pathlib,socket,urllib.request,urllib.error
p=argparse.ArgumentParser();p.add_argument('--base',required=True);p.add_argument('--resolve-ip');a=p.parse_args()
origin=a.base.rstrip('/'); hostname=urllib.parse.urlparse(origin).hostname
if a.resolve_ip:
    original=socket.getaddrinfo
    socket.getaddrinfo=lambda host,*args,**kwargs: original(a.resolve_ip if host==hostname else host,*args,**kwargs)
cred=json.loads(pathlib.Path('private/client-preview/credentials.json').read_text(encoding='utf-8-sig'))
def fetch(path, method='GET', payload=None, token=None):
    headers={'Accept':'application/json','User-Agent':'MAPA-Preview-Verification/1.0'}
    if token: headers['Authorization']='Bearer '+token
    body=None if payload is None else json.dumps(payload).encode()
    if body is not None:headers['Content-Type']='application/json'
    req=urllib.request.Request(origin+path,data=body,headers=headers,method=method)
    try:r=urllib.request.urlopen(req,timeout=40)
    except urllib.error.HTTPError as e:r=e
    with r:return r.status,r.headers,r.read(8*1024*1024)
def check(path,expected,**kwargs):
    status,headers,body=fetch(path,**kwargs)
    assert status==expected,f'{path}: expected {expected}, got {status}'
    assert 'no-store' in headers.get('Cache-Control',''),f'{path}: missing no-store'
    print('PASS',status,path)
    return body
check('/healthz',200)
check('/fleet-ops',200)
cfg=json.loads(check('/fleetbase.config.json',200));assert cfg['API_HOST']==origin+'/api'
check('/api/int/v1/auth/session',401)
check('/api/int/v1/auth/sign-up',404)
check('/api/int/v1/onboard/create-account',404)
check('/.env',404)
check('/private/client-preview/credentials.json',404)
check('/socketcluster/',503)
check('/media',404)
check('/mapa/voice/v1/bridge/events',405,method='POST',payload={})
response=json.loads(check('/api/int/v1/auth/login',200,method='POST',payload={'identity':cred['email'],'password':cred['password']}))
token=response.get('token');assert isinstance(token,str) and token
for path in ['/api/int/v1/auth/session','/api/int/v1/auth/bootstrap','/api/int/v1/users/me','/api/int/v1/places','/api/int/v1/vehicles','/api/int/v1/drivers','/api/int/v1/orders']:
    body=check(path,200,token=token)
    assert b'rajamanu2@gmail.com' not in body and b'919999999999' not in body,'Original profile data in isolated demo!'
    if path.endswith('/users/me'):
        assert b'fleet-ops see extension' in body,'Viewer cannot open the native operations extension.'
for method,path in [('POST','/api/int/v1/places'),('PATCH','/api/int/v1/vehicles/does-not-exist'),('DELETE','/api/int/v1/orders/does-not-exist')]:
    check(path,405,method=method,payload={},token=token)
check('/api/int/v1/auth/logout',200,method='POST',payload={},token=token)
print('Public HTTPS demo login, read access, write denial and logout verified. No secrets printed.')

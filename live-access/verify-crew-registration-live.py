"""Read-only acceptance of the existing MAPA crew registration deployment.
Uses the existing private staff password, tests invalid input before persistence,
and revokes only its own short-lived phone session. No private response is logged.
"""
import argparse,json,urllib.request,urllib.error
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('--base',default='https://mapa-asr-health.pages.dev');args=p.parse_args()
root=Path(__file__).resolve().parents[2]
creds=dict(line.split(': ',1) for line in (root/'private/live-access/Customer-login.txt').read_text(encoding='utf-8').splitlines() if ': ' in line)
base=args.base.rstrip('/'); token=None

def request(path,method='GET',body=None,auth=False):
 headers={'User-Agent':'MAPA-Live-Acceptance/1.0','Accept':'application/json','Cache-Control':'no-cache'}
 if auth:headers['Authorization']='Bearer '+token
 if body is not None:headers['Content-Type']='application/json'
 req=urllib.request.Request(base+path,headers=headers,data=None if body is None else json.dumps(body).encode(),method=method)
 try:resp=urllib.request.urlopen(req,timeout=35)
 except urllib.error.HTTPError as e:resp=e
 with resp:
  raw=resp.read(4*1024*1024)
  data=json.loads(raw) if 'json' in resp.headers.get('Content-Type','') else None
  return resp.status,data,raw,resp.headers

def check(path,expected=200,**kw):
 status,data,raw,headers=request(path,**kw)
 assert status==expected,f'{path}: expected {expected}, received {status}'
 print(f'PASS {status} {path}',flush=True)
 return data,raw,headers

try:
 check('/healthz');check('/auth')
 brand,_,_=check('/api/int/v1/settings/branding');assert brand['brand']['logo_url']=='/images/mapa-logo.svg'
 for path in ['/api/int/v1/users/me','/mapa/mobile/v1/snapshot','/mapa/mobile/v1/enrollments','/mapa/mobile/v1/enrollments/options','/mapa/mobile/v1/trip-reports','/mapa/mobile/v1/enrollment','/mapa/mobile/v1/media/00000000-0000-4000-8000-000000000000']:
  data,_,_=check(path,401);assert isinstance(data,dict)
 check('/mapa/mobile/v1/connect',401,method='POST',body={})
 # Required fields absent: validation must reject before any enrollment/media insert.
 data,_,_=check('/mapa/mobile/v1/enrollments',422,method='POST',body={'invalid_acceptance_probe':'x'*20000})
 assert isinstance(data.get('fields'),dict) and 'action_id' in data['fields']
 # An unauthenticated camera-sized activity is accepted by ingress, then denied by auth.
 check('/mapa/mobile/v1/orders/unknown_acceptance_probe/activity',401,method='POST',body={'action_id':'00000000-0000-4000-8000-000000000001','invalid_acceptance_probe':'x'*20000})
 result,_,_=check('/mapa/mobile/v1/login',method='POST',body={'email':creds['Email'],'password':creds['Password'],'device_name':'MAPA crew read-only release acceptance'})
 token=result['token'];assert result['user']['role'] in ['admin','superadmin']
 snapshot,_,_=check('/mapa/mobile/v1/snapshot',auth=True)
 assert snapshot['contract']=='mapa-native-mobile-v1'
 for path,key in [('/mapa/mobile/v1/enrollments','enrollments'),('/mapa/mobile/v1/enrollments/options','vehicles'),('/mapa/mobile/v1/trip-reports','reports')]:
  data,_,_=check(path,auth=True);assert data['contract']=='mapa-native-mobile-v1' and isinstance(data[key],list)
  assert not any(any(k in row for k in ['connect_token','connect_hash','credential_hash','password','request_hash']) for row in data[key])
 data,_,_=check('/mapa/mobile/v1/media/00000000-0000-4000-8000-000000000000',404,auth=True)
 for path in ['/private/live-access/Customer-login.txt','/.env','/backups/database.sql']:check(path,404)
 print('PASS scoped staff reads, JSON errors and private media boundaries; no registration, GPS, duty or call created.',flush=True)
finally:
 if token:
  check('/mapa/mobile/v1/logout',method='POST',body={},auth=True)
  check('/mapa/mobile/v1/snapshot',401,auth=True)
  print('Only the acceptance phone session was revoked.',flush=True)
"""Read-only public directory acceptance; no review, dispatch, duty or GPS writes."""
from pathlib import Path
import argparse,json,urllib.request,urllib.error
parser=argparse.ArgumentParser();parser.add_argument("--expected-min",type=int,default=93);args=parser.parse_args()
root=Path(__file__).resolve().parents[2]
creds=dict(line.split(': ',1) for line in (root/'private/live-access/Customer-login.txt').read_text(encoding='utf-8').splitlines() if ': ' in line)
base='https://mapa-asr-health.pages.dev'
token=None
def request(path,body=None,auth=False):
 headers={'Accept':'application/json','User-Agent':'MAPA-Directory-Read-Check/1.0','Cache-Control':'no-cache'}
 if auth:headers['Authorization']='Bearer '+token
 if body is not None:headers['Content-Type']='application/json'
 req=urllib.request.Request(base+path,headers=headers,data=None if body is None else json.dumps(body).encode())
 try:resp=urllib.request.urlopen(req,timeout=40)
 except urllib.error.HTTPError as error:resp=error
 with resp:
  raw=resp.read(6*1024*1024)
  data=json.loads(raw) if 'json' in resp.headers.get('Content-Type','') else None
  return resp.status,data,raw
try:
 for path in ['/healthz','/auth','/api/int/v1/settings/branding']:
  status,_,_=request(path);assert status==200;print('PASS public',path,flush=True)
 for path in ['/api/int/v1/users/me','/mapa/voice/v1/calls']:
  status,_,_=request(path);assert status==401;print('PASS anonymous denied',path,flush=True)
 status,data,_=request('/mapa/mobile/v1/login',{'email':creds['Email'],'password':creds['Password'],'device_name':'MAPA directory read-only acceptance'})
 assert status==200 and data['user']['role'] in ['admin','superadmin'];token=data['token']
 status,data,_=request('/mapa/voice/v1/calls',auth=True);assert status==200
 current=next((row for row in data['calls'] if row.get('demo') is False and row.get('state')=='ready'),None)
 assert current,'No existing reviewed real call available for read-only directory proof.'
 status,data,_=request('/mapa/voice/v1/calls/'+current['id'],auth=True);assert status==200
 rows=data['candidates']+data['unavailable']
 assert len(rows)>=args.expected_min and len({row['vehicle_id'] for row in rows})==len(rows)
 assert all(row.get('simulated') is False and isinstance(row.get('vehicle_registration'),str) for row in rows)
 matches=[row for row in rows if 'araku' in ' '.join(str(row.get(key,'')) for key in ['hospital_name','base_name','mandal','secretariat','search_text']).lower()]
 assert matches,'Source Araku area context is absent.'
 assert all(isinstance(row.get('message'),str) and row['message'] for row in data['unavailable'])
 from collections import Counter
 print('Availability reasons: '+json.dumps(dict(Counter(row.get('code','unknown') for row in data['unavailable']))),flush=True)
 print('PASS reviewed call directory: '+str(len(rows))+' current ambulances; '+str(len(matches))+' Araku matches; '+str(len(data['candidates']))+' currently eligible.',flush=True)
 status,_,raw=request('/mapa-voice.js');assert status==200 and b'function ambulanceDirectory(' in raw and b'Find ambulances by area or registration' in raw
 print('PASS public searchable dropdown asset; no operational writes.',flush=True)
finally:
 if token:
  status,_,_=request('/mapa/mobile/v1/logout',{},auth=True);assert status==200
  status,_,_=request('/mapa/voice/v1/calls',auth=True);assert status==401
  print('PASS only verification session revoked.',flush=True)

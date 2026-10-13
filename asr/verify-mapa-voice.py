"""Local-only, explicitly fictional MAPA voice-to-crew acceptance. Private JSON on stdin."""
import datetime
import json
import sys
import urllib.request
import urllib.error
import uuid
from pathlib import Path

class Failure(Exception): pass
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args): return None

def main():
    private=json.loads(sys.stdin.read(16385).lstrip('\ufeff'))
    if private.get('dataset')!='mapa-voice-demo-v1': raise Failure('Explicit fictional dataset required')
    origin='http://localhost:4200'
    opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect())
    checks=0
    def check(ok,label):
        nonlocal checks
        if not ok: raise Failure(label)
        checks+=1
    def request(path,token=None,body=None,status=200):
        headers={'Accept':'application/json'}
        if token: headers['Authorization']='Bearer '+token
        raw=None if body is None else json.dumps(body).encode()
        if raw is not None: headers['Content-Type']='application/json'
        req=urllib.request.Request(origin+path,data=raw,headers=headers,method='GET' if raw is None else 'POST')
        try: response=opener.open(req,timeout=35)
        except urllib.error.HTTPError as e: response=e
        with response:
            check(response.code==status,f'{path}: expected HTTP {status}, got {response.code}')
            check('no-store' in response.headers.get('Cache-Control',''),'Sensitive response cache policy')
            data=json.loads(response.read(1048576))
        check(isinstance(data,dict),'Object response required')
        return data
    mobile='/mapa/mobile/v1';voice='/mapa/voice/v1';sessions=[]
    try:
        request(voice+'/calls',status=401)
        tokens={}
        for role in ['admin','crew']:
            entry=private[role]
            check(entry['email'].endswith('@mapa.invalid'),'Fictional accounts only')
            result=request(mobile+'/login',body={**entry,'device_name':'MAPA voice local acceptance'})
            tokens[role]=result['token'];sessions.append(result['token'])
        admin=tokens['admin'];crew=tokens['crew']
        request(voice+'/calls',crew,status=403)
        capabilities=request(voice+'/calls',admin)['capabilities']
        check(capabilities['demo'] and not capabilities['live_intake'],'Local demo only; live remains disabled')
        event={'event_id':str(uuid.uuid4()),'event_type':'stream.started','account_sid':'DEMO-ACCOUNT','call_sid':'DEMO-'+str(uuid.uuid4()),'stream_sid':'DEMO-STREAM','sequence':1,'occurred_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'demo':False,'payload':{'caller':'','to':''}}
        request(voice+'/bridge/events',body=event,status=401)
        request(voice+'/bridge/events',private['bridge_secret'],event,status=503)
        event['demo']=True
        incoming=request(voice+'/bridge/events',private['bridge_secret'],event)
        check(request(voice+'/bridge/events',private['bridge_secret'],event)['replayed'],'Provider retry idempotent')
        request(voice+'/bridge/events',private['bridge_secret'],{**event,'sequence':9},status=409)
        final={**event,'event_id':str(uuid.uuid4()),'sequence':2,'event_type':'transcript.final','payload':{'text':'DEMO fictional caller, pickup near Paderu. No real patient.','language':'en','draft':{'pickup_address':'DEMO Paderu'}}}
        request(voice+'/bridge/events',private['bridge_secret'],final)
        check(request(voice+'/bridge/events',private['bridge_secret'],final)['replayed'],'Transcript retry idempotent')
        provider=request(voice+'/calls/'+incoming['call_id'],admin)['call']
        check(provider['transcript'].count('DEMO fictional caller')==1,'No duplicate transcript')
        check(provider['state']=='needs_review' and provider['order_id'] is None,'Provider cannot dispatch')
        request(voice+'/calls/'+provider['id']+'/dismiss',admin,{'action_id':str(uuid.uuid4()),'expected_revision':provider['revision'],'reason':'DEMO provider correlation test complete'})
        action={'action_id':str(uuid.uuid4())}
        demo=request(voice+'/demo',admin,action)['call']
        check(request(voice+'/demo',admin,action)['replayed'],'Training retry idempotent')
        detail=request(voice+'/calls/'+demo['id'],admin)
        check(detail['candidates']==[],'No candidate until dispatcher review')
        check(any(p['id']=='place_mapa_mobile_demo_hospital' for p in detail['places']),'Fictional receiving facility available')
        review={'action_id':str(uuid.uuid4()),'expected_revision':demo['revision'],'confirmed':True,'caller_name':'DEMO Caller','callback_number':None,'reported_problem':'Fictional presentation transport request; no actual patient.','pickup_address':'DEMO pickup Paderu','landmark':'DEMO training point','latitude':18.088,'longitude':82.674,'priority':'routine','vehicle_requirement':'bls','dropoff_id':'place_mapa_mobile_demo_hospital'}
        request(voice+'/calls/'+demo['id']+'/review',admin,{**review,'confirmed':False},status=422)
        ready=request(voice+'/calls/'+demo['id']+'/review',admin,review)
        check(ready['call']['state']=='ready','Reviewed intake ready')
        check(request(voice+'/calls/'+demo['id']+'/review',admin,review)['replayed'],'Review retry idempotent')
        request(voice+'/calls/'+demo['id']+'/review',admin,{**review,'action_id':str(uuid.uuid4())},status=409)
        selected=next((c for c in ready['candidates'] if c['id']=='driver_mapa_voice_demo_01'),None)
        check(selected is not None and selected['simulated'],'Dedicated simulated crew eligible')
        check(selected['distance_basis']=='straight_line_not_road_eta','No invented road ETA')
        dispatch={'action_id':str(uuid.uuid4()),'expected_revision':ready['call']['revision'],'confirmed':True,'driver_id':selected['id']}
        endpoint=voice+'/calls/'+demo['id']+'/dispatch'
        request(endpoint,admin,{**dispatch,'confirmed':False},status=422)
        sent=request(endpoint,admin,dispatch)
        replay=request(endpoint,admin,dispatch)
        check(replay['replayed'] and replay['order_id']==sent['order_id'],'One native order on repeated dispatch')
        request(endpoint,admin,{**dispatch,'action_id':str(uuid.uuid4())},status=409)
        snapshot=request(mobile+'/snapshot',crew)
        order=next((o for o in snapshot['orders'] if o['id']==sent['order_id']),None)
        check(order is not None and order['can_acknowledge'],'Same order visible to crew API')
        ack={'action_id':str(uuid.uuid4()),'expected_revision':order['revision']}
        ackpath=mobile+'/orders/'+order['id']+'/ack'
        check(request(ackpath,crew,ack)['ok'],'Crew acknowledgement saved')
        check(request(ackpath,crew,ack)['replayed'],'Crew acknowledgement retry idempotent')
        finished=request(voice+'/calls/'+demo['id'],admin)['call']
        check(finished['acknowledged'] and finished['state']=='dispatched','Crew acknowledgement visible to dispatcher')
        check(finished['order_status']=='dispatched','ACK does not invent completed transport')
        result={'ok':True,'checks_before_cleanup':checks,'simulated':True,'call_id':demo['id'],'order_id':sent['order_id'],'crew_acknowledged':True,'real_phone_call':False,'physical_android_device':False,'live_enabled':False}
    finally:
        for token in sessions:
            request(mobile+'/logout',token,{})
            request(mobile+'/capabilities',token,status=401)
    result['checks']=checks;result['sessions_revoked']=True
    print(json.dumps(result,indent=2))

if __name__=='__main__':
    try: main()
    except Failure as e: print('FAILED: '+str(e),file=sys.stderr);sys.exit(1)
    except Exception: print('FAILED: unexpected verification error; private details suppressed.',file=sys.stderr);sys.exit(1)

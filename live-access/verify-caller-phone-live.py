"""Focused user-authorized callback handover acceptance on one existing DEMO call.

Changes only mapa_caller_phone, finally leaving fictional +12025550142.
No assignment, status, duty, GPS, dispatch, outbound call or notification actions.
Credentials/tokens stay private; all test sessions are revoked.
"""
import argparse
import copy
import json
from pathlib import Path
import runpy
import urllib.error
import urllib.request

HERE=Path(__file__).resolve().parent
ROOT=HERE.parents[1]
COMMON=runpy.run_path(str(HERE/'provision-customer-access.py'))
SUPER=runpy.run_path(str(HERE/'provision-customer-superadmin.py'))
BASE='https://mapa-asr-health.pages.dev'
COMPANY='29eff7bc-7b9b-4d8a-b1b4-55f33681bd2d'
ORDER='order_mapa_mobile_demo_01'
DRIVER='driver_mapa_mobile_demo_01'
VEHICLE='vehicle_mapa_mobile_demo_01'
PHONE='+12025550142'
KEY='mapa_caller_phone'

GUARD=r"""
$o=Fleetbase\FleetOps\Models\Order::withoutGlobalScopes()->where('company_uuid',$input['company'])->where('public_id',$input['order'])->whereNull('deleted_at')->firstOrFail();
$d=Fleetbase\FleetOps\Models\Driver::withoutGlobalScopes()->where('company_uuid',$input['company'])->where('public_id',$input['driver'])->whereNull('deleted_at')->firstOrFail();
$v=Fleetbase\FleetOps\Models\Vehicle::withoutGlobalScopes()->where('company_uuid',$input['company'])->where('public_id',$input['vehicle'])->whereNull('deleted_at')->firstOrFail();
if(data_get($o->meta,'mapa_demo')!==true||$o->driver_assigned_uuid!==$d->uuid||$o->vehicle_assigned_uuid!==$v->uuid||$d->vehicle_uuid!==$v->uuid)throw new RuntimeException('Fixture scope mismatch');
echo 'MAPA_RESULT:'.json_encode(['uuid'=>$o->uuid,'driver_uuid'=>$d->uuid,'vehicle_uuid'=>$v->uuid,'status'=>$o->status,'dispatched'=>(bool)$o->dispatched,'started'=>(bool)$o->started]);
"""

SERIALIZERS=r"""
$o=Fleetbase\FleetOps\Models\Order::withoutGlobalScopes()->where('company_uuid',$input['company'])->where('public_id',$input['order'])->whereNull('deleted_at')->firstOrFail();
if(data_get($o->meta,'mapa_demo')!==true||data_get($o->meta,'mapa_caller_phone')!==$input['phone'])throw new RuntimeException('Fixture callback mismatch');
$out=[];
foreach(['public'=>'Fleetbase\\FleetOps\\Http\\Controllers\\Api\\v1\\OrderController@findRecord','portal'=>'Fleetbase\\CustomerPortal\\Http\\Controllers\\Internal\\v1\\OrderController@order','operator'=>'Fleetbase\\FleetOps\\Http\\Controllers\\Internal\\v1\\OrderController@findRecord'] as $kind=>$action){
 $path=$kind==='public'?'v1/orders/{id}':($kind==='portal'?'customer-portal/int/v1/orders/{id}':'int/v1/orders/{id}');
 $r=Illuminate\Http\Request::create('/'.str_replace('{id}',$o->public_id,$path));
 $route=new Illuminate\Routing\Route('GET',$path,['uses'=>$action,'controller'=>$action]);$route->bind($r);$r->setRouteResolver(fn()=>$route);app()->instance('request',$r);
 $data=(new Fleetbase\FleetOps\Http\Resources\v1\Order($o))->resolve($r);
 $meta=(array)($data['meta']??[]);$visible=isset($meta['mapa_caller_phone']);$review=(array)($meta['mapa_dispatch_review']??[]);
 if($kind==='operator')$out[$kind]=$visible&&$meta['mapa_caller_phone']===$input['phone'];
 else $out[$kind]=!$visible&&!array_key_exists('callback_number',$review);
}
$webhook=(new Fleetbase\FleetOps\Http\Resources\v1\Order($o))->toWebhookPayload();$wm=(array)$webhook['meta'];
$out['webhook']=!array_key_exists('mapa_caller_phone',$wm)&&!array_key_exists('callback_number',(array)($wm['mapa_dispatch_review']??[]));
echo 'MAPA_RESULT:'.json_encode($out);
"""


def credentials(name):
    return dict(line.split(': ',1) for line in (ROOT/'private/live-access'/name).read_text(encoding='utf-8').splitlines() if ': ' in line)


def request(path,token=None,body=None,method=None):
    headers={'Accept':'application/json','User-Agent':'MAPA-Caller-Handover-Acceptance/1.0','Cache-Control':'no-store'}
    if token:headers['Authorization']='Bearer '+token
    data=None
    if body is not None:headers['Content-Type']='application/json';data=json.dumps(body).encode()
    req=urllib.request.Request(BASE+path,data=data,headers=headers,method=method)
    try:response=urllib.request.urlopen(req,timeout=40)
    except urllib.error.HTTPError as error:response=error
    with response:
        try:parsed=json.loads(response.read(4*1024*1024))
        except (ValueError,UnicodeError):parsed=None
        return response.status,parsed


def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--docker',required=True);args=parser.parse_args()
    admin=credentials('Super-admin-login.txt');crew=credentials('Crew-Android-login.txt')
    assert admin['Company UUID']==COMPANY and admin['Marker']==SUPER['MARKER']
    assert crew['Driver ID']==DRIVER and crew['Vehicle ID']==VEHICLE
    scope={'company':COMPANY,'order':ORDER,'driver':DRIVER,'vehicle':VEHICLE}
    before=COMMON['docker_php'](args.docker,GUARD,scope)
    native_token=crew_token=None;checks=[]
    def passed(name):checks.append(name);print('PASS '+name,flush=True)
    try:
        status,body=request('/api/int/v1/auth/login',body={'identity':admin['Email'],'password':admin['Password']})
        assert status==200 and body.get('token'),'Native administrator login failed; response suppressed'
        native_token=body['token']
        status,body=request('/mapa/mobile/v1/login',body={'email':crew['Email'],'password':crew['Password'],'device_name':'MAPA callback handover acceptance'})
        assert status==200 and body.get('token'),'Crew login failed; response suppressed'
        crew_token=body['token'];assert body['user']['company_id']==COMPANY and body['user']['driver_id']==DRIVER and body['user']['vehicle_id']==VEHICLE
        path='/api/int/v1/orders/'+before['uuid']
        def current():
            code,result=request(path,native_token);assert code==200 and isinstance(result,dict),'Native order read failed'
            row=result.get('order',result.get('data',result))
            assert row['public_id']==ORDER and row['company_uuid']==COMPANY and row['driver_assigned_uuid']==before['driver_uuid'] and row['vehicle_assigned_uuid']==before['vehicle_uuid'] and row['meta']['mapa_demo'] is True,'Order scope changed'
            assert row['status']==before['status'] and bool(row['dispatched'])==before['dispatched'] and bool(row['started'])==before['started'],'Operational state changed'
            return row
        initial=current();base_meta=copy.deepcopy(initial['meta']);base_meta.pop(KEY,None)
        def write_phone(value,expected=200):
            row=current();meta=copy.deepcopy(row['meta']);unrelated=copy.deepcopy(meta);unrelated.pop(KEY,None);assert unrelated==base_meta,'Concurrent unrelated metadata change; stopping'
            meta[KEY]=value;code,response=request(path,native_token,{'order':{'meta':meta}},'PUT');assert code==expected,f'Native update status {code}, expected {expected}'
            return response
        unchanged=copy.deepcopy(initial['meta']);bad=write_phone('not-a-phone',422)
        assert 'order.meta.mapa_caller_phone' in bad.get('errors',{}) and current()['meta']==unchanged
        passed('public native invalid phone rejected with JSON422 and no persistence')
        def crew_phone(expected):
            code,state=request('/mapa/mobile/v1/snapshot',crew_token);assert code==200 and state['user']['driver_id']==DRIVER
            rows=[o for o in state['orders'] if o['id']==ORDER];assert len(rows)==1,'Assigned DEMO call missing from crew snapshot'
            # PHP serializes an empty details array as []; both mean no handover fields.
            details=rows[0]['dispatch_details'] or {}
            assert isinstance(details,dict) and details.get('callback_number')==expected,'Crew callback mismatch'
        write_phone('+1 (202) 555-0142');assert current()['meta'][KEY]==PHONE;crew_phone(PHONE)
        passed('formatted native caller phone persists normalized and reaches assigned crew')
        write_phone('');assert current()['meta'][KEY]=='';crew_phone(None)
        passed('explicit unknown clears crew callback without reviving prior review number')
        write_phone(PHONE);assert current()['meta'][KEY]==PHONE;crew_phone(PHONE)
        passed('fictional callback restored for demo; all other order metadata and operational state preserved')
        assert COMMON['docker_php'](args.docker,GUARD,scope)==before
        redaction=COMMON['docker_php'](args.docker,SERIALIZERS,{**scope,'phone':PHONE})
        assert all(redaction.values()) and set(redaction)=={'public','portal','operator','webhook'},'Native resource privacy verification failed'
        passed('deployed resource serializers redact public portal webhook callbacks and preserve native operator access')
    finally:
        cleanup_errors=[]
        if crew_token:
            try:
                code,_=request('/mapa/mobile/v1/logout',crew_token,{});assert code==200
                code,_=request('/mapa/mobile/v1/snapshot',crew_token);assert code==401
                passed('exact crew test session revoked')
            except Exception:cleanup_errors.append('crew session cleanup failed')
        if native_token:
            try:
                result=COMMON['docker_php'](args.docker,COMMON['CLEANUP'],{'user':SUPER['USER_ID'],'company':COMPANY,'marker':SUPER['MARKER'],'token':native_token});assert result['revoked']
                passed('exact native administrator test token revoked')
            except Exception:cleanup_errors.append('native token cleanup failed')
        if cleanup_errors:raise RuntimeError('; '.join(cleanup_errors))
    (HERE/'CALLER-PHONE-LIVE-RESULTS.json').write_text(json.dumps({'origin':BASE,'order':ORDER,'fictional_callback':PHONE,'checks':checks,'tokens_revoked':True},indent=2)+'\n',encoding='utf-8')


if __name__=='__main__':main()

"""Loopback-only visual QA with synthetic records. Never connects to an ASR server."""

from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer

from pathlib import Path

from urllib.parse import urlsplit,parse_qs

import json

from time import time

root=Path(__file__).resolve().parents[2]/'console'/'public'

html='''<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>DEMO · ASR Operations integration QA</title><link rel="stylesheet" href="/native-control-hub.css"><link rel="stylesheet" href="/mapa-source-native.css"><style>

*{box-sizing:border-box}body{margin:0;background:#0e1a28;color:#edf4fa;font:14px system-ui;height:100vh;overflow:hidden}.shell-top{height:56px;padding:12px 20px;display:flex;align-items:center;gap:30px;border-bottom:1px solid #33445a;background:#101827}.shell-top strong{color:#65dfcf}.shell-top small{margin-left:auto;color:#ecce84}.shell{display:flex;height:calc(100vh - 56px)}.shell>aside{width:245px;flex-shrink:0;background:#142030;border-right:1px solid #34445b;padding:12px 10px;display:flex;flex-direction:column}.next-sidebar-navigator-view-in>button{width:100%;padding:12px;border:0;border-radius:7px;background:transparent;color:#ecf3fa;text-align:left;font:inherit}.next-sidebar-navigator-view-in>button:first-child{background:#253b51}.shell>aside footer{margin-top:auto;color:#64dbd1;padding:18px 12px}.next-view-section{flex:1;min-width:0;min-height:0}.next-view-section-container{height:100%;overflow:auto}.operation-fixture{padding:24px}.operation-fixture input{display:block;margin:12px 0;padding:12px;background:#223951;color:white;border:1px solid #56718a;border-radius:6px}.map-placeholder{height:300px;margin-top:24px;border:1px solid #38605b;border-radius:8px;background:repeating-linear-gradient(25deg,#152c31,#152c31 50px,#1e3940 51px,#1e3940 53px);padding:20px}.qa-tools{margin-top:20px;font-size:12px}.qa-tools select{max-width:100%;margin-top:6px}button{cursor:pointer}@media(max-width:650px){.shell>aside{width:160px;padding:8px 5px}.shell-top{padding:10px;gap:14px}.shell-top>span{display:none}.shell-top small{font-size:10px}.mapa-integration-sidebar{padding:9px 8px;font-size:13px;gap:6px}}

</style></head><body data-theme="dark"><header class="shell-top"><strong>ASR HEALTH</strong><span>Operations command centre</span><small>DEMO · isolated test data</small></header><div class="shell"><aside><nav class="fleet-ops-sidebar-navigator"><div class="next-sidebar-navigator-viewport"><div class="next-sidebar-navigator-view-in"><button type="button">Operations</button><button type="button">Resources</button><button type="button">Maintenance</button><button type="button">Connectivity</button><button type="button">Analytics</button><button type="button">Settings</button></div></div><div class="live-monitor" style="border-top:1px solid #374151;margin-top:14px;padding:12px 8px;color:#9ca3af">LIVE OPERATIONS<br><small>DEMO fleet status only</small></div></nav><div class="qa-tools"><label>QA response <select id="qa-state"><option value="ok">Healthy</option><option value="stale">Stale source</option><option value="error">Source unavailable</option><option value="denied">Access revoked</option></select></label></div><footer>MAPA.DEV</footer></aside><section class="next-view-section"><div class="next-view-section-container"><div class="operation-fixture"><h1>Operations</h1><p>Synthetic workspace · existing view stays mounted while reviewing ASR records.</p><label>Draft note<input aria-label="Draft note" value="DEMO unsaved operator note"></label><div class="map-placeholder">DEMO operations map placeholder · no live vehicles</div></div></div></section></div><script>

const session={isAuthenticated:true,data:{authenticated:{token:'DEMO-private-token',user:'DEMO-admin'}}};

const user={companyId:'DEMO-company'};

const realFetch=window.fetch.bind(window);

window.fetch=(url,opts)=>{const mode=document.getElementById('qa-state').value;if(mode==='error')return Promise.resolve({ok:false,status:503});if(mode==='denied')return Promise.resolve({ok:false,status:403});return realFetch(url+(url.includes('?')?'&':'?')+'qa='+mode,opts)};

window.define=(name,deps,factory)=>{const exports={};factory(exports,{registerDestructor(){}});exports.default.initialize({lookup(name){return {'service:session':session,'service:current-user':user,'service:fetch':{getHeaders(){return {Authorization:'Bearer DEMO-private-token'}}}}[name]}})};

</script><script src="/mapa-integration-editor.js"></script><script src="/mapa-integration-view.js"></script><script src="/mapa-source-native.js"></script></body></html>'''

class Handler(BaseHTTPRequestHandler):

 def do_POST(self):

  if urlsplit(self.path).path=='/api/int/v1/mapa/source-commands':

   command=json.loads(self.rfile.read(int(self.headers.get('Content-Length','0'))))

   result=dict(contract='mapa-asr-command-v1',company_id='DEMO-company',request_id=command['request_id'],action=command['action'],record_id=command['record_id'],outcome='applied',refresh_status='pending')

   self.send_response(200);self.send_header('Content-Type','application/json');self.end_headers();self.wfile.write(json.dumps(result).encode())

  else:self.do_GET()

 def do_GET(self):

  u=urlsplit(self.path);q=parse_qs(u.query);mime='text/html; charset=utf-8';status=200

  if u.path=='/':body=html.encode()

  elif u.path=='/native-control-hub.css':
   css=(root.parents[2]/'fleetops-source'/'addon'/'styles'/'fleetops-engine.css').read_text(encoding='utf-8')
   body=css[css.index('.fleet-ops-control-hub {'):css.index("body[data-theme='dark'] .fleet-ops-hub-subtitle,")].encode();mime='text/css'

  elif u.path=='/mobile':body=b'<!doctype html><title>DEMO mobile QA</title><style>body{background:#111}iframe{width:390px;height:844px;border:1px solid #999}</style><iframe title="390px mobile preview" src="/"></iframe>'

  elif u.path in ['/mapa-source-native.js','/mapa-source-native.css','/mapa-integration-view.js','/mapa-integration-editor.js']:

   body=(root/u.path[1:]).read_bytes();mime='text/javascript' if u.path.endswith('.js') else 'text/css'

  elif u.path=='/api/int/v1/mapa/source-commands':

   body=json.dumps(dict(contract='mapa-asr-command-v1',company_id='DEMO-company',enabled=True,actions=['driver.transfer','ambulance.transfer','ambulance.profile'])).encode();mime='application/json'

  elif u.path.startswith('/api/int/v1/mapa/source-inventory/'):

   dataset=u.path.rsplit('/',1)[-1]

   rows={'drivers':[dict(id=i,name=f'DEMO driver {i}',mobile_number='9000000000',expected_revision='a'*64,vehicle_type='Special',hospital_name='DEMO district hospital',meta={'mapa_demo':True}) for i in range(1,102)],

    'ambulances':[dict(id=1,vehicle_number='DEMO-AMB-001',expected_revision='a'*64,vehicle_model='DEMO model',model_year=2020,vehicle_type='Special',hospital_name='DEMO district hospital')],

    'hospitals':[dict(phc_id='DEMO-PHC',name='DEMO district hospital',type='1')],

    'attendance':[dict(id=1,name='DEMO driver 1',vehicle_type='Special',month=q.get('month',['2026-10'])[0],present=2,absent=2,daily_marks='- P A P A - -')],

    'driver_statistics':[dict(driver_id=1,driver_name='DEMO driver 1',trip_type='Patient',purpose='General Emergency',total_kms=25,trip_count=2)],

    'vehicle_statistics':[dict(ambulance_id=1,registration='DEMO-AMB-001',trip_type='Patient',purpose='General Emergency',total_kms=25,trip_count=2)],

    'daily_status':[dict(id=1,ambulance_id=1,registration='DEMO-AMB-001',record_date='2026-10-05',status='On Road')],

    'allocations':[dict(daily_status_id=1,driver_id=1,driver_name='DEMO driver 1')],

    'repairs':[dict(id=1,ambulance_id=1,registration='DEMO-AMB-001',nature_of_repair='DEMO scheduled inspection')],

    'changes':[dict(dataset='drivers',source_id='1',kind='changed',fields=['phc_id'],observed_at=time())]}.get(dataset,[])

   for f in ['vehicle_type','driver_id','ambulance_id']:

    if f in q:rows=[r for r in rows if str(r.get(f))==q[f][0]]

   if q.get('search'):rows=[r for r in rows if any(q['search'][0].lower() in str(r.get(f,'')).lower() for f in ['name','vehicle_number','mobile_number'])]

   page=int(q.get('page',['1'])[0]);total=len(rows)

   result=dict(contract='mapa-asr-source-v1',company_id='DEMO-company',meta={'mapa_demo':True},source_date='2026-10-05',received_at=int(time())-(180 if q.get('qa')==['stale'] else 0),stale=q.get('qa')==['stale'],read_only=True,dataset=dataset,total=total,page=page,has_more=page*100<total,rows=rows[(page-1)*100:page*100])

   result.update(enabled=True,fetch_allowed=True,has_snapshot=True,counts={'drivers':101,'ambulances':1,'hospitals':1,'repairs':1})

   body=json.dumps(result).encode();mime='application/json'

  else:status=404;body=b'Not found'

  self.send_response(status);self.send_header('Content-Type',mime);self.send_header('Cache-Control','no-store');self.end_headers();self.wfile.write(body)

 def log_message(self,*args):pass

print('DEMO-only source preview: http://127.0.0.1:8930',flush=True)

ThreadingHTTPServer(('127.0.0.1',8930),Handler).serve_forever()


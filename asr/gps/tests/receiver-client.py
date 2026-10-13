"""Synthetic binary GT06 -> real Traccar -> real bridge -> isolated HTTP capture."""
import base64,json,socket,struct,sys,threading,time,urllib.request
from datetime import datetime,timezone
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
sys.path.insert(0,'/app')
from bridge import BoundedServer,make_handler,load_config

cfg=load_config('/qa/bridge.json');received=[];ready=threading.Event()
class Capture(BaseHTTPRequestHandler):
    def log_message(self,*args):pass
    def do_POST(self):
        assert self.path=='/mapa/mobile/v1/tracking/ingest'
        assert self.headers['Authorization']=='Bearer '+'a'*64
        received.append(json.loads(self.rfile.read(int(self.headers['Content-Length']))))
        self.send_response(200);self.send_header('Content-Type','application/json');self.end_headers();self.wfile.write(b'{"ok":true}');ready.set()
capture=ThreadingHTTPServer(('127.0.0.1',8897),Capture)
bridge=BoundedServer(('0.0.0.0',8090),make_handler(cfg))
for server in (capture,bridge):threading.Thread(target=server.serve_forever,daemon=True).start()
password='DEMO-only-'+str(time.time_ns())
auth='Basic '+base64.b64encode(('demo-gps@example.invalid:'+password).encode()).decode()
def api(path,body=None):
    req=urllib.request.Request('http://receiver:8082/api/'+path,headers={'Authorization':auth,'Content-Type':'application/json'},data=json.dumps(body).encode() if body else None)
    with urllib.request.urlopen(req,timeout=3) as r:return json.load(r)
for attempt in range(90):
    try:api('server');break
    except Exception:time.sleep(1)
else:raise AssertionError('Receiver not ready')
api('users',{'name':'DEMO isolated GPS QA','email':'demo-gps@example.invalid','password':password})
api('devices',{'name':'DEMO synthetic GT06','uniqueId':'123456789012345'})
def crc(buf):
    value=0xffff
    for b in buf:
        value^=b
        for _ in range(8):value=(value>>1)^0x8408 if value&1 else value>>1
    return value^0xffff
def frame(kind,payload,serial):
    body=bytes([len(payload)+5,kind])+payload+struct.pack('>H',serial)
    return b'xx'+body+struct.pack('>H',crc(body))+b'\r\n'
def gps(valid=True):
    now=datetime.now(timezone.utc)
    stamp=bytes([now.year-2000,now.month,now.day,now.hour,now.minute,now.second])
    return stamp+b'\xc8'+struct.pack('>IIBH',round(18.33*1800000),round(82.87*1800000),30,(0x1400 if valid else 0x0400)|90)+bytes.fromhex('0194010001000001')
with socket.create_connection(('receiver',5023),timeout=5) as s:
    login=frame(1,bytes.fromhex('0123456789012345')+b'\x00\x01',1)
    s.sendall(login[:4]);time.sleep(.05);s.sendall(login[4:])
    ack=s.recv(100);assert ack==frame(1,b'',1),('login_ack',ack.hex())
    s.sendall(frame(0x12,gps(),2))
    assert ready.wait(15),'No decoded fix arrived at MAPA capture'
    fix=received[0]
    assert abs(fix['latitude']-18.33)<.000001 and abs(fix['longitude']-82.87)<.000001,fix
    assert fix['accuracy_m'] is None and fix['satellites']==8 and fix['gps_valid'] is True
    assert fix['source_protocol']=='gt06' and fix['external_id']=='123456789012345'
    before=len(received);s.sendall(frame(0x12,gps(False),3));time.sleep(1)
    assert len(received)==before,'Invalid GPS reached MAPA'
with socket.create_connection(('receiver',5023),timeout=3) as s:
    s.settimeout(1)
    s.sendall(frame(1,bytes.fromhex('0999999999999999')+b'\x00\x01',1))
    try:ack=s.recv(100)
    except socket.timeout:ack=b''
    assert not ack,'Unknown device received a login acknowledgement'
print('PASS: fragmented binary login/ACK; real GT06 decode; authenticated bridge; correct coordinates; unknown accuracy; invalid fix and unknown device rejection.',flush=True)
bridge.shutdown();capture.shutdown()

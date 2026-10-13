"""Loopback-only launch review. Existing compiled UI + local assets, with NO live API proxy."""
import argparse,gzip,json,mimetypes,re
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
from pathlib import Path
from urllib.parse import unquote,urlsplit
CONSOLE=Path(__file__).resolve().parents[1]
PUBLIC=CONSOLE/'public'
BUILT=CONSOLE.parents[1]/'private/live-access/console'
PORT=8878
COMPILED_ONLY=False

def index():
    html=(BUILT/'index.html').read_text(encoding='utf-8')
    html=html.replace('<html>','<html lang="en">').replace(', maximum-scale=1','')
    source=(CONSOLE/'app/index.html').read_text(encoding='utf-8')
    additions='\n'.join(line for line in source.splitlines() if any(x in line for x in ('name="description"','name="robots"','property="og:','name="twitter:')))
    if 'name="description"' not in html:
        html=html.replace('</head>',additions+'\n</head>')
    return html.encode()

class Handler(BaseHTTPRequestHandler):
    def log_message(self,*args):pass
    def respond(self,status,body,kind):
        if isinstance(body,str):body=body.encode()
        compressed='gzip' in self.headers.get('Accept-Encoding','') and len(body)>1024 and ('text/' in kind or 'javascript' in kind or 'json' in kind or 'svg' in kind or 'xml' in kind)
        if compressed:body=gzip.compress(body)
        self.send_response(status);self.send_header('Content-Type',kind);self.send_header('Content-Length',str(len(body)))
        self.send_header('Cache-Control','no-store');self.send_header('X-Robots-Tag','noindex, nofollow');self.send_header('X-Content-Type-Options','nosniff')
        if compressed:self.send_header('Content-Encoding','gzip');self.send_header('Vary','Accept-Encoding')
        self.end_headers();self.wfile.write(body)
    def do_POST(self):self.respond(401,'{"error":"Local audit: operational writes are disabled."}','application/json')
    def do_GET(self):
        path=unquote(urlsplit(self.path).path)
        if path=='/fleetbase.config.json':
            return self.respond(200,json.dumps({'API_HOST':f'http://127.0.0.1:{PORT}/api','API_NAMESPACE':'int/v1','SOCKETCLUSTER_HOST':'127.0.0.1','SOCKETCLUSTER_PORT':PORT,'SOCKETCLUSTER_SECURE':False,'SOCKETCLUSTER_PATH':'/socketcluster/'}),'application/json')
        if path=='/api/int/v1/settings/branding':
            return self.respond(200,'{"brand":{"icon_url":"/mapa-icon.svg","logo_url":"/images/mapa-logo.svg","default_theme":"dark"}}','application/json')
        if path=='/api/int/v1/onboard/should-onboard':return self.respond(200,'{"shouldOnboard":false,"should_onboard":false}','application/json')
        if path.startswith(('/api/','/mapa/mobile/','/mapa/voice/','/socketcluster/')):
            return self.respond(401,'{"errors":["Local audit: sign-in and writes are disabled."]}','application/json')
        forbidden=any(part.startswith('.') for part in Path(path).parts) or any(x in path.lower() for x in ('.map','.zip','.env','.sql','.php','.py','.key','/private/'))
        if not forbidden:
            if path in ('/','/auth','/onboard','/__mapa-route-missing') or path.startswith(('/auth/','/fleet-ops/','/__mapa-route-missing/')):
                return self.respond(200,index(),'text/html; charset=utf-8')
            for root in ((BUILT,) if COMPILED_ONLY else (PUBLIC,BUILT)):
                target=(root/path.lstrip('/')).resolve()
                if not target.is_relative_to(root.resolve()):continue
                if target.is_dir():target=target/'index.html'
                if target.is_file():
                    return self.respond(200,target.read_bytes(),mimetypes.guess_type(str(target))[0] or 'application/octet-stream')
        return self.respond(404,(PUBLIC/'404.html').read_bytes(),'text/html; charset=utf-8')

if __name__=='__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--built',type=Path,default=BUILT)
    parser.add_argument('--port',type=int,default=PORT)
    parser.add_argument('--compiled-only',action='store_true')
    args=parser.parse_args();BUILT=args.built.resolve();PORT=args.port;COMPILED_ONLY=args.compiled_only
    if not (BUILT/'index.html').is_file():parser.error('Built console index.html is required')
    print(f'Local audit preview http://127.0.0.1:{PORT} — no production API connection',flush=True)
    ThreadingHTTPServer(('127.0.0.1',PORT),Handler).serve_forever()

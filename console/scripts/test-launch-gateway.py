"""Validate actual nginx config/static routing on a disposable internal network."""
import argparse,json,subprocess,tempfile,uuid
from pathlib import Path
parser=argparse.ArgumentParser();parser.add_argument('--docker',default='docker');args=parser.parse_args()
fleetbase=Path(__file__).resolve().parents[2];prefix='mapa-launch-qa-'+uuid.uuid4().hex[:8];ids=[];net=None
def run(*cmd):
 r=subprocess.run([args.docker,*cmd],capture_output=True,text=True)
 if r.returncode:raise RuntimeError(r.stdout+r.stderr)
 return r.stdout.strip()
try:
 net=run('network','create','--internal','--label','mapa.qa=launch',prefix)
 with tempfile.TemporaryDirectory(prefix='mapa-launch-qa-') as folder:
  config=Path(folder)/'gateway.conf'
  config.write_text((fleetbase/'live-access/gateway.conf.template').read_text().replace('${MAPA_PUBLIC_HOST}','local.invalid'))
  container=run('run','-d','--network',prefix,'--network-alias','gateway','--label','mapa.qa=launch',
   '--mount',f'type=bind,source={config},target=/etc/nginx/conf.d/default.conf,readonly',
   '--mount',f'type=bind,source={fleetbase}/live-access/proxy.conf,target=/etc/nginx/mapa-proxy.conf,readonly',
   '--mount',f'type=bind,source={fleetbase}/console/public,target=/usr/share/nginx/html,readonly','nginx:1.31.6-alpine');ids.append(container)
  run('exec',container,'nginx','-t')
  code='''import urllib.request,urllib.error,json,gzip
def get(path):
 try:r=urllib.request.urlopen(urllib.request.Request('http://gateway:8080'+path,headers={'Accept-Encoding':'gzip'}),timeout=5)
 except urllib.error.HTTPError as e:r=e
 with r:return r.status,r.headers,r.read()
for path in ['/privacy/','/terms/','/data-rights/','/robots.txt','/sitemap.xml','/images/mapa-social-preview.png']:
 status,headers,body=get(path);assert status==200,(path,status)
status,headers,body=get('/legal/legal.css');assert headers.get('Content-Encoding')=='gzip';assert b'font-family' in gzip.decompress(body)
status,headers,body=get('/assets/missing.js');body=gzip.decompress(body) if headers.get('Content-Encoding')=='gzip' else body;assert status==404 and b'Page not found' in body
status,headers,body=get('/api/int/v1/auth/sign-up');assert status==404 and 'json' in headers.get('Content-Type');assert 'errors' in json.loads(body)
for path in ['/.env','/private/secrets.txt','/assets/vendor.map']:
 assert get(path)[0]==404,path
print('PASS: actual nginx syntax, legal assets, gzip, custom static 404, existing JSON denial and private-file restrictions.')
'''
  print(run('run','--rm','--network',prefix,'python:3.12-alpine','python','-c',code))
finally:
 for container in ids:
  state=json.loads(run('inspect',container))[0]
  if state['Config']['Labels'].get('mapa.qa')=='launch':run('rm','-f',container)
 if net:
  state=json.loads(run('network','inspect',net))[0]
  if state['Internal'] and state['Labels'].get('mapa.qa')=='launch':run('network','rm',net)

import argparse,re,pathlib,json
p=argparse.ArgumentParser();p.add_argument('--upstream',required=True);a=p.parse_args()
if a.upstream != 'https://app.itda.asrhealth.in' and not re.fullmatch(r'https://[a-z0-9-]+\.trycloudflare\.com',a.upstream):raise SystemExit('Expected the reviewed Cloudflare connector or app.itda.asrhealth.in HTTPS origin.')
root=pathlib.Path(__file__).resolve().parent
source=(root/'relay.mjs').read_text(encoding='utf-8')
if source.count('__MAPA_UPSTREAM__')!=1:raise SystemExit('Unexpected relay source.')
(root/'pages').mkdir(exist_ok=True)
(root/'pages/_worker.js').write_text(source.replace('__MAPA_UPSTREAM__',a.upstream),encoding='utf-8')
(root/'pages/_routes.json').write_text(json.dumps({'version':1,'include':['/*'],'exclude':[]}),encoding='utf-8')
print('Prepared live Pages relay for the reviewed upstream.')

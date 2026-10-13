"""Apply reviewed static launch assets to an explicit LOCAL console build directory."""
import argparse,hashlib,re,shutil
from pathlib import Path
parser=argparse.ArgumentParser();parser.add_argument('--console-dir',type=Path,required=True);args=parser.parse_args()
source=Path(__file__).resolve().parents[1]
target=args.console_dir.resolve()
if target==source/'public' or not (target/'index.html').is_file():parser.error('Use a separate compiled console directory containing index.html')
index=(target/'index.html').read_text(encoding='utf-8')
if 'mapa-legal.js' not in index:parser.error('Expected the reviewed MAPA console build')
index=index.replace('<html>','<html lang="en">').replace(', maximum-scale=1','')
for name in ('description','robots','twitter:card'):
    index=re.sub(r'<meta\s+name="'+re.escape(name)+r'"[^>]*>','',index)
index=re.sub(r'<meta\s+property="og:[^"]+"[^>]*>','',index)
head=(source/'app/index.html').read_text(encoding='utf-8')
meta='\n'.join(line.strip() for line in head.splitlines() if any(x in line for x in ('name="description"','name="robots"','property="og:','name="twitter:')))
index=index.replace('</head>',meta+'\n</head>')
files=['privacy/index.html','terms/index.html','data-rights/index.html','legal/legal.css','404.html','robots.txt','sitemap.xml','images/mapa-social-preview.png','mapa-icon.svg','mapa-legal.js','mapa-voice.js','call-intake/index.html','track/index.html','track/tracking.css','track/tracking.js']
for name in files:
    dest=target/name;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(source/'public'/name,dest)
for name in ('mapa-legal.js','mapa-voice.js'):
    digest=hashlib.sha256((source/'public'/name).read_bytes()).hexdigest()[:12]
    index=re.sub(r'("/'+re.escape(name)+r')(?:\?[^"\s]*)?"',lambda m:m[1]+'?v='+digest+'"',index)
(target/'index.html').write_text(index,encoding='utf-8')
print('Installed reviewed static launch assets locally. Legal drafts still require operator details; the Ember catch route requires a source build. Nothing deployed.')

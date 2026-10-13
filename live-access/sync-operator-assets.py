"""Copy only reviewed public operator assets and refresh their cache versions."""
import hashlib
from pathlib import Path
import re

root=Path(__file__).resolve().parents[2]
public=root/'fleetbase/console/public'
console=root/'private/live-access/console'
names=['mapa-voice.js','mapa-voice.css','mapa-crew-native.js','mapa-crew-native.css','call-intake/index.html','mapa-map/leaflet.js','mapa-map/leaflet.css','mapa-map/leaflet-license.txt']
assets={name:(public/name).read_bytes() for name in names}
index=(console/'index.html').read_text(encoding='utf-8')
for name in ['mapa-voice.js','mapa-voice.css']:
    version=hashlib.sha256(assets[name]).hexdigest()[:12]
    pattern=r'((?:src|href)="/'+re.escape(name)+r')(?:\?[^"\s]*)?("\s*)'
    index,count=re.subn(pattern,lambda m:m[1]+'?v='+version+m[2],index)
    if count!=1:raise SystemExit('Expected one operator reference: '+name)
    standalone=assets['call-intake/index.html'].decode('utf-8')
    standalone,count=re.subn(pattern,lambda m:m[1]+'?v='+version+m[2],standalone)
    if count!=1:raise SystemExit('Expected one standalone operator reference: '+name)
    assets['call-intake/index.html']=standalone.encode('utf-8')
# Register the native initializer after Ember's vendor bundle and before App boot.
index=re.sub(r'<script\b[^>]*src="/mapa-crew-native\.js(?:\?[^"\s]*)?"[^>]*></script>\s*', '', index)
index=re.sub(r'<link\b[^>]*href="/mapa-crew-native\.css(?:\?[^"\s]*)?"[^>]*>\s*', '', index)
vendor=re.search(r'<script\b[^>]*src="/assets/vendor\.js(?:\?[^"\s]*)?"[^>]*></script>',index)
app=re.search(r'<script\b[^>]*src="/assets/@fleetbase/console\.js(?:\?[^"\s]*)?"[^>]*></script>',index)
if not vendor or not app or vendor.end()>=app.start(): raise SystemExit('Native bundle ordering is not recognized.')
script_version=hashlib.sha256(assets['mapa-crew-native.js']).hexdigest()[:12]
style_version=hashlib.sha256(assets['mapa-crew-native.css']).hexdigest()[:12]
index=index[:vendor.end()]+'\n    <script src="/mapa-crew-native.js?v='+script_version+'"></script>'+index[vendor.end():]
if index.count('</head>')!=1: raise SystemExit('Expected one console head.')
index=index.replace('</head>','<link rel="stylesheet" href="/mapa-crew-native.css?v='+style_version+'">\n  </head>')
for name,content in assets.items():
    target=console/name;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(content)
(console/'index.html').write_text(index,encoding='utf-8',newline='\n')
print('Published operator assets and native crew registration review with refreshed asset versions.')

"""Isolated import checks: public map facts only, no requests or application writes."""
import copy
from pathlib import Path
import runpy

module=runpy.run_path(str(Path(__file__).resolve().parents[1]/'build-asr-landmarks.py'))
build=module['build']
station={'type':'node','id':2523199054,'lat':18.3329216,'lon':82.8652583,
         'tags':{'name':'Araku','railway':'station','ref':'ARK','phone':'PRIVATE TEST DO NOT COPY','operator':'TEST'}}
area={'type':'way','id':12,'center':{'lat':18.1,'lon':82.6},'tags':{'name':'TEST hospital','amenity':'hospital'}}
bad=copy.deepcopy(station);bad['id']=13;bad['lon']=float('nan')
outside=copy.deepcopy(station);outside['id']=14;outside['lat']=19.5
foreign=copy.deepcopy(station);foreign['id']=15;foreign['tags']['addr:country']='US'
unnamed=copy.deepcopy(station);unnamed['id']=16;unnamed['tags'].pop('name')
rows=build({'elements':[station,station,area,bad,outside,foreign,unnamed]}, {'mandal_aliases':{'1':['Araku','అరకు']}})
assert len(rows)==2
ark=next(r for r in rows if r['id']=='osm:N:2523199054')
assert ark['latitude']==18.3329216 and ark['longitude']==82.8652583
assert ark['name']=='Araku railway station (ARK)' and 'ARK' in ark['aliases']
assert any('అరకు' in a and 'రైల్వే' in a for a in ark['aliases'])
assert ark['locality']=='' and 'mandal' not in ark
assert all('PRIVATE TEST' not in str(r) and 'operator' not in r for r in rows)
assert next(r for r in rows if r['id']=='osm:W:12')['coordinate_basis']=='mapped feature centre'
for bad_body in ({'elements':[],'remark':'timed out'},{'error':'unavailable'}):
    try: build(bad_body,{})
    except ValueError: pass
    else: raise AssertionError('Incomplete import must be rejected')
print('Landmark import: 9 checks passed (in-memory fixture only).')

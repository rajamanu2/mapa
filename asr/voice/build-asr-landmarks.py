"""Build a public, key-free landmark index from a bounded Overpass JSON snapshot.

Usage: python build-asr-landmarks.py path/to/snapshot.json
The input contains public map data only. No operator queries or patient records
are used. Only names, categories, public addresses and map coordinates survive.
"""
import argparse
from collections import Counter
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import re
import urllib.parse
import urllib.request

BOUNDS = {'south': 17.40, 'north': 18.75, 'west': 81.80, 'east': 83.35}
SOURCE = 'https://overpass-api.de/api/interpreter'
QUERY = '''[out:json][timeout:40];(
nwr["railway"~"^(station|halt)$"](17.40,81.80,18.75,83.35);
nwr["amenity"~"^(hospital|clinic|doctors|bus_station|police|fire_station|school|college|university|marketplace|pharmacy|fuel|bank|post_office)$"]["name"](17.40,81.80,18.75,83.35);
nwr["tourism"~"^(attraction|museum|viewpoint|hotel|guest_house)$"]["name"](17.40,81.80,18.75,83.35);
nwr["office"="government"]["name"](17.40,81.80,18.75,83.35);
nwr["place"~"^(village|hamlet|town|suburb|neighbourhood)$"]["name"](17.40,81.80,18.75,83.35);
);out center tags;'''
KINDS = {
    'hospital': ('hospital', 'hospital hospitals ఆసుపత్రి'),
    'clinic': ('clinic', 'clinic hospital medical health centre ఆసుపత్రి'),
    'doctors': ('clinic', 'clinic doctor medical health centre'),
    'bus_station': ('bus station', 'bus station bus stand busstop బస్ స్టాండ్'),
    'police': ('police station', 'police station పోలీస్ స్టేషన్'),
    'fire_station': ('fire station', 'fire station'),
    'school': ('school', 'school స్కూల్ పాఠశాల'),
    'college': ('college', 'college కళాశాల'),
    'university': ('university', 'university college'),
    'marketplace': ('market', 'market bazaar మార్కెట్'),
    'pharmacy': ('pharmacy', 'pharmacy medical store medicine'),
    'fuel': ('fuel station', 'petrol pump fuel station bunk'),
    'bank': ('bank', 'bank బ్యాంకు'),
    'post_office': ('post office', 'post office పోస్టాఫీసు'),
}

def clean(value, limit=200):
    return re.sub(r'[\x00-\x1f]', ' ', value).strip()[:limit] if isinstance(value, str) else ''

def build(snapshot, directory):
    if not isinstance(snapshot.get('elements'), list) or snapshot.get('remark'):
        raise ValueError('Incomplete Overpass response; keep the last valid index.')
    rows = []
    seen = set()
    for item in snapshot['elements']:
        tags = item.get('tags', {})
        if item.get('type') not in ('node', 'way', 'relation') or not isinstance(tags, dict) or not isinstance(item.get('id'), int) or item['id']<=0:
            continue
        name = clean(tags.get('name:en') or tags.get('name'))
        if not name or tags.get('addr:country', 'IN').upper() != 'IN':
            continue
        point = item if item['type'] == 'node' else item.get('center', {})
        lat, lon = point.get('lat'), point.get('lon')
        if not isinstance(lat, (int, float)) or not isinstance(lon, (int, float)):
            continue
        if not BOUNDS['south'] <= lat <= BOUNDS['north'] or not BOUNDS['west'] <= lon <= BOUNDS['east']:
            continue
        station = tags.get('railway') in ('station', 'halt')
        if station:
            kind, terms = 'railway station', 'railway station train station rail rly stn రైల్వే స్టేషన్ రైల్వే స్టేషను రైలు'
        elif tags.get('amenity') in KINDS:
            kind, terms = KINDS[tags['amenity']]
        elif tags.get('tourism') in ('attraction','museum','viewpoint','hotel','guest_house'):
            kind = clean(tags['tourism']).replace('_', ' ')
            terms = kind + ' tourism tourist place'
        elif tags.get('office') == 'government':
            kind, terms = 'government office', 'government office కార్యాలయం'
        elif tags.get('place') in ('village', 'hamlet', 'town', 'suburb', 'neighbourhood'):
            kind, terms = 'settlement', 'village hamlet town locality గ్రామం'
        else:
            continue
        key = 'osm:' + {'node':'N','way':'W','relation':'R'}[item['type']] + ':' + str(item['id'])
        if key in seen:
            continue
        seen.add(key)
        code = clean(tags.get('ref'), 12) if station else ''
        display = name
        if station and not re.search(r'rail|station', name, re.I):
            display += ' railway station'
        if code:
            display += ' (' + code + ')'
        locality = clean(tags.get('addr:village') or tags.get('addr:city') or tags.get('addr:suburb'), 160)
        if kind == 'settlement':
            locality = name
        address_parts = [display] + [clean(tags.get(k)) for k in ('addr:street','addr:suburb','addr:village','addr:city','addr:district','addr:state','addr:postcode')]
        address = ', '.join(dict.fromkeys(x for x in address_parts if x))[:1000]
        names = [name, display, code]
        for field in ('name', 'name:en', 'name:te', 'alt_name', 'official_name', 'short_name'):
            names.extend(clean(tags.get(field)).split(';'))
        # Use documented mandal spelling aliases only for an identical place name.
        for aliases in directory.get('mandal_aliases', {}).values():
            if name.casefold() in (alias.casefold() for alias in aliases):
                names.extend(aliases)
        names = list(dict.fromkeys(x for x in names if x))
        aliases = list(dict.fromkeys([*names, address, *(f'{alias} {terms} {locality}' for alias in names)]))
        rows.append({'id': key, 'name': display, 'aliases': aliases, 'kind': kind,
                     'address': address, 'locality': locality, 'latitude': lat, 'longitude': lon,
                     'coordinate_basis': 'mapped point' if item['type'] == 'node' else 'mapped feature centre',
                     'source': 'OpenStreetMap', 'source_url': f"https://www.openstreetmap.org/{item['type']}/{item['id']}"})
    return sorted(rows, key=lambda row: (row['name'].casefold(), row['id']))

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('snapshot', type=Path)
    parser.add_argument('--download', action='store_true', help='Fetch the fixed public regional query before building.')
    args = parser.parse_args()
    here = Path(__file__).resolve().parent
    if args.download:
        request = urllib.request.Request(SOURCE, data=urllib.parse.urlencode({'data':QUERY}).encode(), headers={'User-Agent':'MAPA-ASR-place-index/1.0','Content-Type':'application/x-www-form-urlencoded'})
        with urllib.request.urlopen(request, timeout=55) as response:
            fetched = response.read(10_000_001)
        if len(fetched)>10_000_000:
            raise ValueError('Oversized provider response')
        parsed = json.loads(fetched)
        if not isinstance(parsed.get('elements'),list) or parsed.get('remark'):
            raise ValueError('Incomplete provider response; existing files retained.')
        args.snapshot.parent.mkdir(parents=True,exist_ok=True)
        args.snapshot.write_bytes(fetched)
    raw = args.snapshot.read_bytes()
    snapshot = json.loads(raw)
    directory = json.loads((here/'asr-directory.json').read_text(encoding='utf8'))
    rows = build(snapshot, directory)
    if len(rows) < 100 or not any(r['id'] == 'osm:N:2523199054' for r in rows):
        raise ValueError('Snapshot is missing expected regional coverage or Araku station.')
    data = {'schema': 'mapa-osm-local-v1', 'source': 'OpenStreetMap contributors',
            'source_url': SOURCE, 'query': QUERY, 'license': 'ODbL-1.0',
            'license_url': 'https://www.openstreetmap.org/copyright',
            'source_timestamp': snapshot.get('osm3s', {}).get('timestamp_osm_base'),
            'built_at': datetime.now(timezone.utc).isoformat(), 'snapshot_sha256': hashlib.sha256(raw).hexdigest(),
            'bounds': BOUNDS, 'bounds_are_official_district_boundary': False,
            'notice': 'Regional mapped places including neighbouring areas. Not an exhaustive ASR register or verified pickup entrances.',
            'counts_by_kind': dict(Counter(row['kind'] for row in rows)), 'places': rows}
    target = here/'asr-landmarks.json'
    temporary = target.with_suffix('.next.json')
    temporary.write_text(json.dumps(data, ensure_ascii=False, separators=(',', ':'))+'\n', encoding='utf8')
    temporary.replace(target)
    print(json.dumps({'places':len(rows),'counts':data['counts_by_kind'],'bytes':target.stat().st_size}))

if __name__ == '__main__':
    main()

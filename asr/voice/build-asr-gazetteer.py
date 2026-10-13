"""Build a local approximate-place index from the official GeoNames India dump.

Usage: python build-asr-gazetteer.py --download (or use an existing --archive).
No operator search strings are submitted to GeoNames.
"""
import argparse
import csv
from datetime import datetime, timezone
import hashlib
import io
import json
from pathlib import Path
import urllib.request
import zipfile

SOURCE = 'https://download.geonames.org/export/dump/IN.zip'
BOUNDS = {'south': 17.40, 'north': 18.75, 'west': 81.80, 'east': 83.35}
KINDS = {'P': 'settlement', 'S': 'landmark', 'T': 'terrain', 'H': 'water feature', 'A': 'administrative area', 'R': 'road or railway', 'L': 'area', 'V': 'vegetation'}


def main():
    here = Path(__file__).resolve().parent
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--archive', type=Path, default=here.parents[2] / 'tmp' / 'mapa-geonames-IN.zip')
    parser.add_argument('--download', action='store_true')
    args = parser.parse_args()
    archive = args.archive.resolve()
    if args.download:
        archive.parent.mkdir(parents=True, exist_ok=True)
        temporary = archive.with_suffix('.download')
        request = urllib.request.Request(SOURCE, headers={'User-Agent': 'MAPA-gazetteer-builder/1.0'})
        with urllib.request.urlopen(request, timeout=90) as response, temporary.open('wb') as output:
            while block := response.read(1024 * 1024):
                output.write(block)
        if not zipfile.is_zipfile(temporary):
            raise RuntimeError('GeoNames did not return a ZIP archive')
        temporary.replace(archive)
    places = []
    with zipfile.ZipFile(archive) as z:
        with z.open('IN.txt') as raw:
            for row in csv.reader(io.TextIOWrapper(raw, encoding='utf-8'), delimiter='\t'):
                if len(row) < 19 or row[6] not in KINDS or row[8] != 'IN':
                    continue
                lat, lon = float(row[4]), float(row[5])
                if not (BOUNDS['south'] <= lat <= BOUNDS['north'] and BOUNDS['west'] <= lon <= BOUNDS['east']):
                    continue
                names = list(dict.fromkeys(n.strip() for n in [row[1], row[2], *row[3].split(',')] if n.strip()))
                places.append({'id': row[0], 'name': row[1], 'aliases': names, 'latitude': lat, 'longitude': lon,
                               'kind': KINDS[row[6]], 'feature_code': row[7], 'source_modified': row[18]})
    places.sort(key=lambda p: (p['name'].casefold(), p['id']))
    if not places:
        raise RuntimeError('No regional places were extracted')
    with archive.open('rb') as source:
        digest = hashlib.file_digest(source, 'sha256').hexdigest()
    stamp = datetime.fromtimestamp(archive.stat().st_mtime, timezone.utc).isoformat()
    data = {'schema': 'mapa-geonames-local-v1', 'source': 'GeoNames', 'source_url': SOURCE,
            'source_readme': 'https://download.geonames.org/export/dump/readme.txt',
            'license': 'CC BY4.0', 'license_url': 'https://creativecommons.org/licenses/by/4.0/',
            'downloaded_at': stamp, 'archive_sha256': digest,
            'bounds': BOUNDS, 'bounds_are_official_district_boundary': False, 'approximate': True,
            'notice': 'Regional approximate place centres. Not an official ASR district boundary or verified caller locations. Source data can be incomplete or outdated.',
            'places': places}
    target = here / 'asr-places.json'
    target.write_text(json.dumps(data, ensure_ascii=False, separators=(',', ':')) + '\n', encoding='utf-8')
    print(f'Local GeoNames gazetteer: {len(places)} places, {target.stat().st_size} bytes')
    for name in ('paderu', 'araku'):
        matches = [p['name'] for p in places if any(name in alias.casefold() for alias in p['aliases'])]
        print(f'{name}: {len(matches)} candidates')


if __name__ == '__main__':
    main()

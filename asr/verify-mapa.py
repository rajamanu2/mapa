"""Read-only integration checks against the running local MAPA pilot."""
from pathlib import Path
import json
import urllib.request
import urllib.error

environment = {}
for line in Path(__file__).with_name('.env').read_text(encoding='utf-8-sig').splitlines():
    if line.strip() and not line.lstrip().startswith('#') and '=' in line:
        key, value = line.split('=', 1)
        environment[key] = value.strip().strip('"').strip("'")
token = environment.get('MAPA_ADAPTER_TOKEN') or environment.get('ASR_ADAPTER_TOKEN')
if not token or len(token) < 40:
    raise SystemExit('Configure the local adapter credential before verification.')

def get(route, credential=None):
    headers = {'Accept': 'application/json'}
    if credential:
        headers['Authorization'] = 'Bearer ' + credential
    request = urllib.request.Request('http://127.0.0.1:8000' + route, headers=headers)
    try:
        with urllib.request.urlopen(request, timeout=25) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as error:
        return error.code, json.loads(error.read())

status, health = get('/mapa/health')
assert status == 200 and health['contract'] == 'mapa-v1', 'MAPA health contract missing'
assert get('/mapa/v1/fleet')[0] == 401, 'Anonymous fleet request must be denied'
assert get('/mapa/v1/fleet', 'invalid-credential')[0] == 401, 'Invalid fleet credential must be denied'
status, fleet = get('/mapa/v1/fleet', token)
assert status == 200 and fleet['contract'] == 'mapa-v1', 'MAPA fleet contract missing'
demo = [row for row in fleet['vehicles'] if row.get('simulated') and row['id'].startswith('vehicle_mapa_demo_')]
assert len(demo) == 5, 'Expected exactly five marked demo ambulances'
assert all(row['id'].startswith('vehicle_mapa_demo_') for row in demo)
assert all(row.get('simulated') for row in fleet['vehicles'] if row['id'].startswith(('vehicle_mapa_demo_', 'vehicle_mapa_mobile_'))), 'Training vehicles must remain marked as simulated'
assert all(row['position']['time_source'] == 'mapa_position_created_at' for row in demo)
assert all(17.50 <= row['position']['lat'] <= 18.52 and 82.02 <= row['position']['lng'] <= 83.16 for row in demo), 'Demo positions must remain in district overview'
assert get('/mapa/v1/fleet?page=0', token)[0] == 422, 'Invalid pagination must be rejected'
status, legacy = get('/asr/v1/fleet', token)
assert status == 200 and legacy['contract'] == 'asr-fleetbase-v1', 'Compatibility contract missing'
assert not any(row.get('simulated') or row['id'].startswith('vehicle_mapa_demo_') for row in legacy['vehicles']), 'Legacy consumers must not import training GPS'
print(json.dumps({'result': 'passed', 'health': True, 'authentication': True, 'demo_vehicles': len(demo), 'district_positions': True, 'pagination': True, 'legacy_contract': True, 'legacy_demo_exclusion': True}, indent=2))

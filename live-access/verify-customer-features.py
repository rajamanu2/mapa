"""Read-only feature acceptance for the explicitly provisioned customer account."""
import concurrent.futures
import importlib.util
import json
from pathlib import Path
import sys

base = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('mapa_customer', base / 'provision-customer-access.py')
customer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(customer)
if len(sys.argv) != 2:
    raise SystemExit('Pass the Docker executable path.')
saved = dict(line.split(': ',1) for line in (base.parents[1]/'private/live-access/Customer-login.txt').read_text(encoding='utf-8').splitlines() if ': ' in line)
paths = [
    '/api/int/v1/' + path for path in ['auth/organizations','fleets','contacts','vendors','fuel-reports','issues',
    'maintenance-schedules','work-orders','maintenances','equipment','parts','telematics','devices','sensors',
    'device-events','fuel-provider-connections']
] + ['/api/int/v1/fleet-ops/settings/' + path for path in ['routing-settings','tracking-settings','orchestrator-settings',
    'scheduling-settings','notification-settings','notification-registry','notification-notifiables']]
token = None
results = []
try:
    status, body = customer.request('/api/int/v1/auth/login', payload={'identity':customer.EMAIL,'password':saved['Password']})
    if status != 200 or not body.get('token'):
        raise SystemExit('Customer password login failed; private response suppressed.')
    token = body['token']
    def check(path):
        status, _ = customer.request(path, token=token)
        return {'path':path,'status':status}
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        for result in pool.map(check, paths):
            results.append(result)
            print(result['status'], result['path'], flush=True)
    # Empty input cannot reset a password; the edge must reject it before native validation.
    status, body = customer.request('/api/int/v1/users/change-password', token=token, payload={})
    assert status == 403 and 'Owner and account security' in str(body), 'Expected customer account-security boundary'
    print('PASS customer owner/account-security writes blocked with JSON; no credentials changed.', flush=True)
finally:
    if token:
        cleanup = customer.docker_php(sys.argv[1], customer.CLEANUP, {'user':customer.USER_ID,'company':customer.COMPANY,'marker':customer.MARKER,'token':token})
        assert cleanup['revoked']
        print('Exact feature-check token revoked.', flush=True)
(base/'CUSTOMER-FEATURE-RESULTS.json').write_text(json.dumps(results,indent=2)+'\n',encoding='utf-8')
if any(result['status'] != 200 for result in results):
    raise SystemExit('Some features need review; see redacted status results.')

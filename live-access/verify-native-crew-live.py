"""Read-only public native review verification; revoke only this password-login token."""
from pathlib import Path
import argparse,importlib.util
parser=argparse.ArgumentParser();parser.add_argument('--docker',required=True);args=parser.parse_args()
root=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('mapa_customer_verification',Path(__file__).with_name('provision-customer-access.py'))
helper=importlib.util.module_from_spec(spec);spec.loader.exec_module(helper)
creds=dict(line.split(': ',1) for line in (root/'private/live-access/Customer-login.txt').read_text(encoding='utf-8').splitlines() if ': ' in line)
token=None
try:
 for suffix in ['/identity','/enrollments','/enrollments/options','/trip-reports']:
  status,_=helper.request('/api/int/v1/mapa/crew-review'+suffix)
  assert status==401,(suffix,status)
  print('PASS anonymous denied',suffix,flush=True)
 status,result=helper.request('/api/int/v1/auth/login',payload={'identity':creds['Email'],'password':creds['Password']})
 assert status==200 and isinstance(result.get('token'),str),'Native password login failed.'
 token=result['token']
 for suffix,key in [('/identity','user'),('/enrollments','enrollments'),('/enrollments/options','vehicles'),('/trip-reports','reports')]:
  status,result=helper.request('/api/int/v1/mapa/crew-review'+suffix,token=token)
  assert status==200 and result['contract']=='mapa-native-mobile-v1' and key in result,(suffix,status)
  if suffix=='/identity': assert result['user']['company_id']==helper.COMPANY and result['capabilities']['enrollment_review'] is True
  print('PASS authenticated native review',suffix,flush=True)
 status,_=helper.request('/api/int/v1/mapa/crew-review/media/00000000-0000-4000-8000-000000000000',token=token)
 assert status==404,'Unknown private media should be unavailable.'
 print('PASS private media boundary; no operational record changed.',flush=True)
finally:
 if token:
  result=helper.docker_php(args.docker,helper.CLEANUP,{'user':helper.USER_ID,'company':helper.COMPANY,'marker':helper.MARKER,'token':token})
  assert result.get('revoked') is True
  status,_=helper.request('/api/int/v1/mapa/crew-review/identity',token=token)
  assert status==401,'Revoked native token should be rejected.'
  print('PASS exact verification token revoked and rejected.',flush=True)

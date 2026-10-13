import concurrent.futures
import importlib.util
import json
from pathlib import Path
import sqlite3
import subprocess
import tempfile
import types
import unittest

spec=importlib.util.spec_from_file_location('gateway',Path(__file__).parents[1]/'gateway.py')
gateway=importlib.util.module_from_spec(spec);spec.loader.exec_module(gateway)

class GatewayTests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.root=Path(self.tmp.name);self.root.chmod(0o700)
  self.smsd=self.root/'smsd.ini';self.smsd.write_text('[smsd]\nService = FILES\nMaxRetries = 0\nDebugLevel = 0\nLogFile = /dev/null\n')
  self.calls=[]
  self.config={'enabled':True,'tracking_origin':'https://app.itda.asrhealth.in','token':'a'*64,'ledger':str(self.root/'ledger.sqlite'),'smsd_config':str(self.smsd),'inject_binary':str(self.root/'gammu-smsd-inject'),'smsd_binary':str(self.root/'gammu-smsd')}
  self.payload={'id':'b'*64,'phone':'+919999000001','url':'https://app.itda.asrhealth.in/track/#'+'c'*64}
  self.service=gateway.Gateway(self.config,runner=self.run_fake,version_runner=self.version_fake)
 def tearDown(self):self.tmp.cleanup()
 def run_fake(self,args,**kw):self.calls.append((args,kw));return types.SimpleNamespace(returncode=0)
 def version_fake(self,args,**kw):
  name='Gammu-smsd-inject' if args[0].endswith('inject') else 'Gammu-smsd'
  return types.SimpleNamespace(returncode=0,stdout=(name+' version 1.45.0\nCompiled in features:\n').encode())
 def test_success_is_queued_never_delivered(self):
  r=self.service.submit(self.payload);self.assertEqual(r['status'],'queued');self.assertFalse(r['delivered'])
 def test_token_in_stdin_only_shell_disabled_no_output_logging(self):
  self.service.submit(self.payload);args,kw=self.calls[0]
  self.assertNotIn('c'*64,' '.join(args));self.assertIn(('c'*64).encode(),kw['input'])
  self.assertFalse(kw['shell']);self.assertEqual(kw['stdout'],subprocess.DEVNULL);self.assertEqual(kw['stderr'],subprocess.DEVNULL)
  self.assertEqual(args[4:6],['TEXT',self.payload['phone']]);self.assertIn('-L',args)
 def test_multipart_command_preserves_complete_ascii_link(self):
  self.service.submit(self.payload);args,kw=self.calls[0]
  expected=('ASR Health: view your ambulance journey and optionally share pickup location: '+self.payload['url']).encode('ascii')
  self.assertGreater(len(expected),160);self.assertLessEqual(len(expected),306)
  self.assertEqual(kw['input'],expected)
  self.assertEqual(args[-4:],['-len',str(len(expected)),'-maxsms','2'])
  self.assertTrue(kw['input'].endswith(self.payload['url'].encode('ascii')))
  self.assertNotIn(self.payload['url'],' '.join(args))
 def test_old_or_unreviewed_cli_version_never_injects_or_reserves(self):
  for version in ('1.42.0','1.44.1','1.45.1','1.45.0-custom'):
   self.service.version_runner=lambda *a,**k:types.SimpleNamespace(returncode=0,stdout=('Gammu-smsd-inject version '+version+'\n').encode())
   with self.assertRaises(gateway.Reject) as error:self.service.submit(self.payload)
   self.assertEqual(error.exception.status,503)
  self.assertEqual(self.calls,[])
  with self.service.db() as db:self.assertEqual(db.execute('SELECT count(*) FROM attempts').fetchone()[0],0)
 def test_smsd_must_also_match_reviewed_version(self):
  def older_daemon(args,**kw):
   return self.version_fake(args,**kw) if args[0].endswith('inject') else types.SimpleNamespace(returncode=0,stdout=b'Gammu-smsd version 1.44.1\n')
  self.service.version_runner=older_daemon
  with self.assertRaises(gateway.Reject):self.service.submit(self.payload)
  self.assertEqual(self.calls,[])
 def test_version_timeout_and_failure_fail_closed(self):
  def timeout(*a,**k):raise subprocess.TimeoutExpired('DEMO-version',3)
  for runner in (timeout,lambda *a,**k:types.SimpleNamespace(returncode=1,stdout=b'')):
   self.service.version_runner=runner
   with self.assertRaises(gateway.Reject):self.service.submit(self.payload)
  self.assertEqual(self.calls,[])
 def test_ledger_contains_no_phone_or_capability(self):
  self.service.submit(self.payload)
  raw=(self.root/'ledger.sqlite').read_bytes()
  self.assertNotIn(self.payload['phone'].encode(),raw);self.assertNotIn(self.payload['url'].encode(),raw);self.assertNotIn(b'c'*64,raw)
 def test_idempotent_replay_and_restart(self):
  self.service.submit(self.payload)
  again=gateway.Gateway(self.config,runner=self.run_fake,version_runner=self.version_fake)
  self.assertTrue(again.submit(self.payload)['replayed']);self.assertEqual(len(self.calls),1)
 def test_concurrent_replay_injects_once(self):
  with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:results=list(pool.map(lambda _:self.service.submit(self.payload),range(5)))
  self.assertEqual(len(self.calls),1);self.assertEqual(sum(not x['replayed'] for x in results),1)
 def test_same_id_changed_payload_rejected(self):
  self.service.submit(self.payload)
  with self.assertRaises(gateway.Reject) as e:self.service.submit({**self.payload,'phone':'+919999000002'})
  self.assertEqual(e.exception.status,409);self.assertEqual(len(self.calls),1)
 def test_timeout_never_reinjected(self):
  def timeout(*args,**kwargs):self.calls.append(True);raise subprocess.TimeoutExpired('DEMO',10)
  self.service.runner=timeout
  self.assertEqual(self.service.submit(self.payload)['status'],'unknown')
  self.assertEqual(self.service.submit(self.payload)['status'],'unknown');self.assertEqual(len(self.calls),1)
 def test_nonzero_outcome_is_unknown_and_never_retried(self):
  self.service.runner=lambda *a,**k:types.SimpleNamespace(returncode=1)
  self.assertEqual(self.service.submit(self.payload)['status'],'unknown');self.assertTrue(self.service.submit(self.payload)['replayed'])
 def test_crash_reservation_remains_unknown(self):
  def crash(*a,**k):raise KeyboardInterrupt()
  self.service.runner=crash
  with self.assertRaises(KeyboardInterrupt):self.service.submit(self.payload)
  self.service.runner=self.run_fake
  self.assertEqual(self.service.submit(self.payload)['status'],'unknown');self.assertEqual(self.calls,[])
 def test_wrong_origin_and_fragment_injection_denied(self):
  for url in ('https://evil.invalid/track/#'+'c'*64,self.payload['url']+'?x=1',self.payload['url']+'\nsecret','http://app.itda.asrhealth.in/track/#'+'c'*64):
   with self.assertRaises(gateway.Reject):self.service.submit({**self.payload,'url':url})
  self.assertEqual(self.calls,[])
 def test_phone_shell_injection_rejected(self):
  for phone in ('+919999000001; echo secret','--help','+919999000001\n','9999','+0000000000'):
   with self.assertRaises(gateway.Reject):self.service.submit({**self.payload,'phone':phone})
  self.assertEqual(self.calls,[])
 def test_extra_fields_and_invalid_types_rejected(self):
  for data in ([],None,{**self.payload,'body':'secret'}, {**self.payload,'phone':123}):
   with self.assertRaises(gateway.Reject):self.service.submit(data)
 def test_auth_requires_exact_secret(self):
  self.assertTrue(self.service.authenticate('Bearer '+'a'*64))
  for value in ('','Bearer short',None,'Bearer '+'b'*64):self.assertFalse(self.service.authenticate(value))
 def test_disabled_and_bad_secret_startup_fail(self):
  for change in ({'enabled':False},{'enabled':'true'},{'token':'short'},{'tracking_origin':'https://other.invalid'}):
   with self.assertRaises(ValueError):gateway.Gateway({**self.config,**change},runner=self.run_fake,version_runner=self.version_fake)
 def test_retries_or_verbose_logging_blocks_injection(self):
  for text in ('[smsd]\nService=FILES\nMaxRetries=1\n', '[smsd]\nService=FILES\nMaxRetries=0\nDebugLevel=255\n', '[smsd]\nService=FILES\nMaxRetries=0\nLogFile=syslog\n'):
   self.smsd.write_text(text)
   with self.assertRaises(gateway.Reject) as e:self.service.submit(self.payload)
   self.assertEqual(e.exception.status,503)
  self.assertEqual(self.calls,[])
 def test_unconfigured_request_creates_no_reservation(self):
  self.smsd.unlink()
  with self.assertRaises(gateway.Reject):self.service.submit(self.payload)
  with self.service.db() as db:self.assertEqual(db.execute('SELECT count(*) FROM attempts').fetchone()[0],0)

if __name__=='__main__':unittest.main()

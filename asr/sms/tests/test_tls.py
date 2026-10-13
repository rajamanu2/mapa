"""Real TLS handshakes using memory BIOs: no listener, network or modem.

Test-only dependency: cryptography. Gateway itself uses only Python stdlib.
"""
from datetime import datetime, timedelta, timezone
import importlib.util
from pathlib import Path
import ssl
import tempfile
import unittest
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import NameOID, ExtendedKeyUsageOID

spec=importlib.util.spec_from_file_location('gateway',Path(__file__).parents[1]/'gateway.py')
gateway=importlib.util.module_from_spec(spec);spec.loader.exec_module(gateway)

class MutualTLS(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.tmp=tempfile.TemporaryDirectory();cls.root=Path(cls.tmp.name)
  cls.ca_key,cls.ca=cls.cert('DEMO CA',ca=True)
  cls.write('ca',cls.ca_key,cls.ca)
  for name,usage in [('server',ExtendedKeyUsageOID.SERVER_AUTH),('client',ExtendedKeyUsageOID.CLIENT_AUTH)]:
   key,cert=cls.cert(name,issuer=cls.ca,issuer_key=cls.ca_key,usage=usage);cls.write(name,key,cert)
  key,cert=cls.cert('rogue',ca=True);cls.write('rogue',key,cert)
 @classmethod
 def tearDownClass(cls):cls.tmp.cleanup()
 @classmethod
 def cert(cls,name,ca=False,issuer=None,issuer_key=None,usage=None):
  key=rsa.generate_private_key(public_exponent=65537,key_size=2048)
  subject=x509.Name([x509.NameAttribute(NameOID.COMMON_NAME,name)])
  now=datetime.now(timezone.utc)
  builder=(x509.CertificateBuilder().subject_name(subject).issuer_name(issuer.subject if issuer else subject)
   .public_key(key.public_key()).serial_number(x509.random_serial_number()).not_valid_before(now-timedelta(minutes=1))
   .not_valid_after(now+timedelta(days=1)).add_extension(x509.BasicConstraints(ca=ca,path_length=None),critical=True)
   .add_extension(x509.KeyUsage(digital_signature=True,content_commitment=False,key_encipherment=not ca,data_encipherment=False,key_agreement=False,key_cert_sign=ca,crl_sign=ca,encipher_only=None,decipher_only=None),critical=True)
   .add_extension(x509.SubjectKeyIdentifier.from_public_key(key.public_key()),critical=False))
  if issuer:builder=builder.add_extension(x509.AuthorityKeyIdentifier.from_issuer_public_key(issuer_key.public_key()),critical=False)
  if usage:builder=builder.add_extension(x509.ExtendedKeyUsage([usage]),critical=False)
  if name=='server':builder=builder.add_extension(x509.SubjectAlternativeName([x509.DNSName('sms.test')]),critical=False)
  return key,builder.sign(issuer_key or key,hashes.SHA256())
 @classmethod
 def write(cls,name,key,cert):
  (cls.root/(name+'.key')).write_bytes(key.private_bytes(serialization.Encoding.PEM,serialization.PrivateFormat.PKCS8,serialization.NoEncryption()))
  (cls.root/(name+'.crt')).write_bytes(cert.public_bytes(serialization.Encoding.PEM))
 def handshake(self,certificate='client',hostname='sms.test'):
  server=gateway.tls_context({'tls_cert':str(self.root/'server.crt'),'tls_key':str(self.root/'server.key'),'client_ca':str(self.root/'ca.crt')})
  self.assertEqual(server.verify_mode,ssl.CERT_REQUIRED)
  client=ssl.create_default_context(cafile=str(self.root/'ca.crt'))
  if certificate:client.load_cert_chain(self.root/(certificate+'.crt'),self.root/(certificate+'.key'))
  ci,co,si,so=[ssl.MemoryBIO() for _ in range(4)]
  c=client.wrap_bio(ci,co,server_hostname=hostname);s=server.wrap_bio(si,so,server_side=True)
  done=[False,False]
  for _ in range(30):
   for index,obj in enumerate((c,s)):
    if not done[index]:
     try:obj.do_handshake();done[index]=True
     except ssl.SSLWantReadError:pass
   if co.pending:si.write(co.read())
   if so.pending:ci.write(so.read())
   if all(done):return True
  raise AssertionError('Handshake stalled')
 def test_valid_mutual_tls(self):self.assertTrue(self.handshake())
 def test_missing_client_certificate_rejected(self):
  with self.assertRaises(ssl.SSLError):self.handshake(None)
 def test_untrusted_client_certificate_rejected(self):
  with self.assertRaises(ssl.SSLError):self.handshake('rogue')
 def test_wrong_server_hostname_rejected(self):
  with self.assertRaises(ssl.SSLError):self.handshake(hostname='wrong.test')

if __name__=='__main__':unittest.main()

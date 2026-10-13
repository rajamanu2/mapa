import copy
import json
import sys
import tempfile
import threading
import time
import unittest
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from bridge import Reject, translate, load_config, make_handler, BoundedServer

IMEI='123456789012345'  # DEMO only, never the user's physical device.
TOKEN='a'*64
SECRET='b'*64

def sample():
    return {'device': {'id':1,'uniqueId':IMEI,'disabled':False}, 'position': {
        'deviceId':1,'protocol':'gt06','valid':True,'attributes':{'sat':8},
        'latitude':18.33,'longitude':82.87,'accuracy':0,
        'fixTime':datetime.now(timezone.utc).isoformat()}}

class BridgeTests(unittest.TestCase):
    def translate(self, p):
        return translate(p,{IMEI:TOKEN},int(time.time()*1000))

    def test_unknown_accuracy_preserved(self):
        token,fix=self.translate(sample())
        self.assertEqual(token,TOKEN)
        self.assertIsNone(fix['accuracy_m'])
        self.assertEqual(fix['external_id'],IMEI)
        self.assertEqual(fix['source_protocol'],'gt06')

    def test_quality_denials(self):
        changes=[{'valid':False},{'protocol':'osmand'},{'latitude':91},{'longitude':181},
            {'latitude':0,'longitude':0},{'latitude':float('nan')},{'latitude':True},
            {'accuracy':101},{'accuracy':-1},{'attributes':{'sat':3}},
            {'attributes':{'sat':16}},{'attributes':{'sat':8,'outdated':True}},
            {'attributes':{'sat':8,'approximate':True}},{'attributes':None},
            {'fixTime':'2020-01-01T00:00:00Z'},{'fixTime':'2999-01-01T00:00:00Z'},
            {'fixTime':'2026-10-04T10:00:00'},{'deviceId':2}]
        for change in changes:
            with self.subTest(change=change), self.assertRaises(Reject):
                p=sample();p['position'].update(change);self.translate(p)

    def test_unregistered_device_and_envelopes(self):
        for value in [None,[],{}, {'device':None,'position':{} }]:
            with self.assertRaises(Reject): self.translate(value)
        p=sample();p['device']['uniqueId']='999999999999999'
        with self.assertRaises(Reject):self.translate(p)
        p=sample();del p['device']['id'];del p['position']['deviceId']
        with self.assertRaises(Reject):self.translate(p)

    def test_config_fails_closed(self):
        cfg={'forward_secret':SECRET,'devices':{IMEI:TOKEN},'ingest_url':'https://example.invalid/mapa/mobile/v1/tracking/ingest'}
        with tempfile.TemporaryDirectory() as folder:
            path=Path(folder)/'config.json';path.write_text(json.dumps(cfg))
            self.assertEqual(load_config(path),cfg)
            for change in [{'ingest_url':'http://example.invalid/mapa/mobile/v1/tracking/ingest'},
                {'ingest_url':'https://user:pass@example.invalid/mapa/mobile/v1/tracking/ingest'},
                {'ingest_url':'https://example.invalid/wrong'}, {'forward_secret':'short'},
                {'devices':{IMEI:TOKEN,'123456789012346':TOKEN}}]:
                path.write_text(json.dumps({**cfg,**change}))
                with self.assertRaises(ValueError):load_config(path)

    def test_http_auth_retry_rejection(self):
        calls=[];status=[200]
        def sender(token,fix):calls.append(fix);return status[0]
        server=BoundedServer(('127.0.0.1',0),make_handler({'forward_secret':SECRET,'devices':{IMEI:TOKEN}},sender))
        threading.Thread(target=server.serve_forever,daemon=True).start()
        def post(auth, payload=None):
            req=urllib.request.Request('http://127.0.0.1:'+str(server.server_port)+'/positions',
                data=json.dumps(payload or sample()).encode(),headers={'Content-Type':'application/json','Authorization':auth})
            try:r=urllib.request.urlopen(req,timeout=3)
            except urllib.error.HTTPError as e:r=e
            with r:return r.status,json.load(r)
        try:
            self.assertEqual(post('bad')[0],401);self.assertFalse(calls)
            self.assertEqual(post('Bearer '+SECRET),(200,{'ok':True}))
            status[0]=503;self.assertEqual(post('Bearer '+SECRET)[0],503)
            status[0]=429;self.assertEqual(post('Bearer '+SECRET)[0],503)
            status[0]=401;self.assertEqual(post('Bearer '+SECRET)[1]['discarded'],'mapa_rejected')
        finally:server.shutdown();server.server_close()

if __name__=='__main__':unittest.main()

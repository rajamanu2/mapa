import test from 'node:test';
import assert from 'node:assert/strict';
import { parsePath, parseStart, PacketOrder, audioPayload, normalizeLanguage } from '../protocol.mjs';
import { readConfig, secretEqual } from '../config.mjs';
import { start, media } from './fixtures.mjs';

test('sample-rate is explicit and only supported PCM rates pass', () => {
  assert.equal(parsePath('/media'), 8000); assert.equal(parsePath('/media?sample-rate=16000'), 16000);
  for (const path of ['/other', '/media?sample-rate=24000', '/media?sample-rate=bad', '/media?token=secret', '/media?sample-rate=8000&sample-rate=16000'])
    assert.throws(() => parsePath(path));
});
test('snake and camel identities bind account, call, stream, PCM rate', () => {
  const a = parseStart(start(), 'DEMO_ACCOUNT', 8000); assert.equal(a.call_sid, 'DEMO_CALL'); assert.equal(a.dialect, 'snake');
  const b = parseStart({ event: 'start', streamSid: 'STREAM2', start: { accountSid: 'DEMO_ACCOUNT', callSid: 'CALL2', streamSid: 'STREAM2',
    mediaFormat: { sampleRate: '16000', encoding: 'linear16', channels: 1 } } }, 'DEMO_ACCOUNT', 16000);
  assert.equal(b.sample_rate, 16000); assert.equal(b.dialect, 'camel');
  assert.throws(() => parseStart(start(), 'OTHER', 8000), /wrong_account/);
  assert.throws(() => parseStart(start(), 'DEMO_ACCOUNT', 16000), /sample_rate_mismatch/);
  assert.throws(() => parseStart(start({ streamSid: 'OTHER' }), 'DEMO_ACCOUNT', 8000), /conflicting_fields/);
  const wrong = start(); wrong.start.stream_sid = 'OTHER'; assert.throws(() => parseStart(wrong, 'DEMO_ACCOUNT', 8000), /wrong_stream/);
  const mulaw = start(); mulaw.start.media_format.encoding = 'mulaw'; assert.throws(() => parseStart(mulaw, 'DEMO_ACCOUNT', 8000), /unsupported_encoding/);
});
test('control sequence between two audio packets is not an audio gap', () => {
  const call = parseStart(start(), 'DEMO_ACCOUNT', 8000), order = new PacketOrder(start());
  assert.equal(order.accept(media(), call).length, 320);
  order.accept({ event: 'dtmf', stream_sid: 'DEMO_STREAM', sequence_number: 3, dtmf: { digit: '2' } }, call);
  assert.equal(order.accept(media({ sequence_number: 4, media: { ...media().media, chunk: 2, timestamp: '20' } }), call).length, 320);
});
test('replay, gap, reorder, chunk resets and timestamp discontinuities fail closed', () => {
  const call = parseStart(start(), 'DEMO_ACCOUNT', 8000);
  for (const m of [media({ sequence_number: 4 }), media({ sequence_number: 1 }),
    media({ media: { ...media().media, chunk: 2 } }), media({ stream_sid: 'WRONG' })])
    assert.throws(() => new PacketOrder(start()).accept(m, call));
  const order = new PacketOrder(start()); order.accept(media(), call);
  assert.throws(() => order.accept(media({ sequence_number: 3, media: { ...media().media, chunk: 2, timestamp: 100 } }), call), /timestamp_gap/);
});
test('audio validates strict base64, even PCM bytes, size and null payloads', () => {
  for (const payload of ['', '!!!!', 'abc', Buffer.alloc(3).toString('base64'), Buffer.alloc(64002).toString('base64'), null])
    assert.throws(() => audioPayload({ media: { payload } }, 8000));
  assert.equal(audioPayload(media(), 8000).length, 320);
});
test('production starts disabled and requires independent keys, public WSS and human fallback', () => {
  const base = { MAPA_API_ORIGIN: 'https://mapa.example' };
  const disabled = readConfig(base); assert.equal(disabled.ready, false); assert.equal(disabled.live, false);
  const live = readConfig({ ...base, MAPA_VOICE_LIVE_ENABLED: 'true', EXOTEL_ACCOUNT_SID: 'ACCOUNT', STREAM_USER: 'user', STREAM_PASSWORD: 's'.repeat(40),
    MAPA_VOICE_BRIDGE_SECRET: 'b'.repeat(40), SARVAM_API_KEY: 'key', MAPA_VOICE_PUBLIC_WSS: 'wss://voice.example/media', MAPA_VOICE_HUMAN_FALLBACK_CONFIGURED: 'true' });
  assert.equal(live.ready, true);
  assert.throws(() => readConfig({ MAPA_API_ORIGIN: 'http://attacker.example', MAPA_ALLOW_INTERNAL_HTTP: 'true' }));
  assert.throws(() => readConfig({ MAPA_API_ORIGIN: 'https://user:password@mapa.example' }));
  assert.throws(() => readConfig({ ...base, MAX_CALLS: '100000' }));
  assert.equal(readConfig({ MAPA_API_ORIGIN: 'http://application:8000', MAPA_ALLOW_INTERNAL_HTTP: 'true' }).ready, false);
  assert.equal(secretEqual('', ''), false); assert.equal(secretEqual('abc', 'abc'), true); assert.equal(secretEqual('abc', 'abd'), false);
  assert.equal(normalizeLanguage('te-IN'), 'te'); assert.equal(normalizeLanguage('en-IN'), 'en'); assert.equal(normalizeLanguage('auto'), 'unknown');
});

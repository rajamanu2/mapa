import test from 'node:test';
import assert from 'node:assert/strict';
import { setTimeout as delay } from 'node:timers/promises';
import { createBridge } from '../server.mjs';
import { cfg, start, media, FakeSpeech, connect, rejectedConnect, until } from './fixtures.mjs';

async function setup(t, extra = {}, dependencies = {}) {
  const config = { ...cfg(), ...extra }, speeches = [], delivered = [];
  const bridge = createBridge(config, {
    speechFactory: () => { const speech = new FakeSpeech(); speeches.push(speech); return speech; },
    synthesize: async () => Buffer.alloc(3200),
    fetch: async (_url, request) => { delivered.push(JSON.parse(request.body)); return new Response('{"ok":true}'); },
    ...dependencies,
  });
  const address = await bridge.listen(); t.after(() => bridge.close());
  return { bridge, config, speeches, delivered, port: address.port };
}
test('upgrade requires Basic auth, rejects browser origins, bad paths, unsupported rates', async t => {
  const s = await setup(t);
  assert.equal(await rejectedConnect(s.port, '/media', {}), 401);
  assert.equal(await rejectedConnect(s.port, '/media?sample-rate=24000', {}), 400);
  assert.equal(await rejectedConnect(s.port, '/media?token=secret', {}), 400);
  assert.equal(await rejectedConnect(s.port, '/media', { Origin: 'https://browser.example' }), 403);
  const ws = await connect(s.port, s.config); ws.close();
});
test('disabled bridge reports a gate and cannot silently accept a real call', async t => {
  const s = await setup(t, { ready: false, missing: ['SARVAM_API_KEY'] });
  assert.equal(await rejectedConnect(s.port, '/media', {}), 503);
  const health = await fetch(`http://127.0.0.1:${s.port}/health`); assert.equal(health.status, 200); assert.equal((await health.json()).live_enabled, false);
  assert.equal((await fetch(`http://127.0.0.1:${s.port}/ready`)).status, 503);
});
test('actual WebSocket PCM and Telugu transcript reach native MAPA event contract', async t => {
  const s = await setup(t); const ws = await connect(s.port, s.config), received = [];
  ws.on('message', m => received.push(JSON.parse(m)));
  ws.send(JSON.stringify({ event: 'connected' })); ws.send(JSON.stringify(start()));
  await until(() => s.speeches[0]?.ready); ws.send(JSON.stringify(media()));
  await until(() => s.speeches[0].audio.length === 1);
  assert.equal(s.speeches[0].audio[0].length, 320);
  s.speeches[0].emit('transcript', { event: 'transcript.final', text: 'DEMO పాడేరు మార్కెట్ దగ్గర', language: 'te-IN', start_s: 0, end_s: 1 });
  await until(() => s.delivered.some(e => e.event_type === 'transcript.final'));
  const transcript = s.delivered.find(e => e.event_type === 'transcript.final');
  assert.equal(transcript.payload.language, 'te'); assert.equal(transcript.payload.draft.pickup_address, 'DEMO పాడేరు మార్కెట్ దగ్గర');
  assert.equal(transcript.sequence, 1); assert.ok(received.some(e => e.event === 'media'));
  ws.send(JSON.stringify({ event: 'stop', sequence_number: 3, stream_sid: 'DEMO_STREAM', stop: { account_sid: 'DEMO_ACCOUNT', call_sid: 'DEMO_CALL', reason: 'callended' } }));
  await until(() => s.delivered.some(e => e.event_type === 'stream.ended'));
  assert.deepEqual(s.delivered.map(e => e.sequence), [0, 1, 2]);
});
test('DTMF0 creates a human handoff event without a dispatch request', async t => {
  const s = await setup(t); const ws = await connect(s.port, s.config); ws.send(JSON.stringify(start()));
  await until(() => s.speeches[0]?.ready);
  ws.send(JSON.stringify({ event: 'dtmf', sequence_number: 2, stream_sid: 'DEMO_STREAM', dtmf: { digit: '0' } }));
  await until(() => s.delivered.some(e => e.event_type === 'stream.failed'));
  assert.equal(s.delivered.at(-1).payload.reason, 'operator_requested'); assert.equal(s.bridge.status().active_streams, 0);
});
test('audio gap, wrong stream and wrong stop identity are visible failures', async t => {
  for (const [bad, expected] of [
    [media({ sequence_number: 4 }), 'packet_gap'],
    [media({ stream_sid: 'OTHER' }), 'wrong_stream'],
    [{ event: 'stop', sequence_number: 2, stream_sid: 'DEMO_STREAM', stop: { call_sid: 'OTHER' } }, 'wrong_stop_identity'],
  ]) {
    const s = await setup(t); const ws = await connect(s.port, s.config); ws.send(JSON.stringify(start()));
    await until(() => s.speeches[0]?.ready); ws.send(JSON.stringify(bad));
    await until(() => s.delivered.some(e => e.event_type === 'stream.failed'));
    assert.equal(s.delivered.at(-1).payload.reason, expected);
  }
});
test('duplicate active stream cannot hijack or close the first connection', async t => {
  const s = await setup(t); const first = await connect(s.port, s.config); first.send(JSON.stringify(start()));
  await until(() => s.speeches[0]?.ready);
  const duplicate = await connect(s.port, s.config); duplicate.send(JSON.stringify(start()));
  await until(() => duplicate.readyState === 3);
  assert.equal(first.readyState, 1); assert.equal(s.speeches.length, 1);
  first.send(JSON.stringify(media())); await until(() => s.speeches[0].audio.length === 1);
  first.close();
});
test('unexpected close preserves trailing transcript before terminal failure', async t => {
  const s = await setup(t); const ws = await connect(s.port, s.config); ws.send(JSON.stringify(start()));
  await until(() => s.speeches[0]?.ready);
  s.speeches[0].end = function () { this.ending = true; setImmediate(() => {
    this.emit('transcript', { text: 'DEMO final words', language: 'en-IN' }); this.emit('ended'); }); };
  ws.terminate(); await until(() => s.delivered.some(e => e.event_type === 'stream.failed'));
  assert.deepEqual(s.delivered.map(e => e.event_type), ['stream.started', 'transcript.final', 'stream.failed']);
  assert.equal(s.delivered.at(-1).payload.reason, 'stream_disconnected');
});
test('ASR failure and TTS failure cannot fabricate transcript or leave caller stuck', async t => {
  const s = await setup(t); const ws = await connect(s.port, s.config); ws.send(JSON.stringify(start()));
  await until(() => s.speeches[0]?.ready); s.speeches[0].emit('failure', 'asr_unavailable');
  await until(() => s.delivered.some(e => e.event_type === 'stream.failed'));
  assert.equal(s.delivered.some(e => e.event_type === 'transcript.final'), false);
  const f = await setup(t, {}, { synthesize: async () => { throw new Error('provider_key_rejected'); } });
  const caller = await connect(f.port, f.config); caller.send(JSON.stringify(start()));
  await until(() => f.delivered.some(e => e.event_type === 'stream.failed'));
  assert.equal(f.delivered.at(-1).payload.reason, 'tts_unavailable');
});
test('replayed ASR utterance with same time span does not duplicate transcript', async t => {
  const s = await setup(t); const ws = await connect(s.port, s.config); ws.send(JSON.stringify(start()));
  await until(() => s.speeches[0]?.ready);
  const transcript = { text: 'DEMO location', language: 'en-IN', start_s: 0, end_s: 1 };
  s.speeches[0].emit('transcript', transcript); s.speeches[0].emit('transcript', transcript);
  await until(() => s.delivered.some(e => e.event_type === 'transcript.final'));
  await delay(100); assert.equal(s.delivered.filter(e => e.event_type === 'transcript.final').length, 1); ws.close();
});

test('three guided answers end at human review, without declaring an ambulance assigned', async t => {
  const prompts = [];
  const s = await setup(t, {}, { synthesize: async args => { prompts.push(args.text); return Buffer.alloc(3200); } });
  const ws = await connect(s.port, s.config), received = [];
  ws.on('message', data => received.push(JSON.parse(data))); ws.send(JSON.stringify(start()));
  await until(() => s.speeches[0]?.ready && prompts.length === 1);
  s.speeches[0].emit('transcript', { text: 'DEMO village', language: 'en-IN' });
  await until(() => prompts.length === 2);
  s.speeches[0].emit('transcript', { text: 'DEMO person needs transport', language: 'en-IN' });
  await until(() => prompts.length === 3);
  s.speeches[0].emit('transcript', { text: 'DEMO caller name', language: 'en-IN' });
  await until(() => received.some(m => m.event === 'mark' && m.mark.name.endsWith('-3')));
  const mark = received.find(m => m.event === 'mark' && m.mark.name.endsWith('-3'));
  ws.send(JSON.stringify({ event: 'mark', stream_sid: 'DEMO_STREAM', sequence_number: 2, mark: mark.mark }));
  await until(() => s.delivered.some(e => e.event_type === 'stream.ended'));
  assert.match(prompts.at(-1), /not yet been assigned/);
  assert.equal(s.delivered.at(-1).payload.reason, 'intake_complete');
  const texts = s.delivered.filter(e => e.event_type === 'transcript.final');
  assert.equal(texts[0].payload.draft.pickup_address, 'DEMO village');
  assert.equal(texts[1].payload.draft.reported_problem, 'DEMO person needs transport');
  assert.deepEqual(texts[2].payload.draft, {});
});

test('DTMF Telugu choice changes fixed prompt without transmitting keypad digits as patient data', async t => {
  const languages = [];
  const s = await setup(t, {}, { synthesize: async args => { languages.push(args.language); return Buffer.alloc(3200); } });
  const ws = await connect(s.port, s.config); ws.send(JSON.stringify(start()));
  await until(() => languages.length === 1);
  ws.send(JSON.stringify({ event: 'dtmf', stream_sid: 'DEMO_STREAM', sequence_number: 2, dtmf: { digit: '2' } }));
  await until(() => languages.length === 2); assert.deepEqual(languages, ['en', 'te']); ws.close();
});

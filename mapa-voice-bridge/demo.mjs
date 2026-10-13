// A local protocol rehearsal: synthetic PCM, explicitly fictional transcripts,
// in-process provider doubles and a collecting API sink. No telephone service,
// provider account, real credentials, or running MAPA data is touched.
import assert from 'node:assert/strict';
import { createBridge } from './server.mjs';
import { EventEmitter } from 'node:events';
import WebSocket from 'ws';
import { setTimeout as delay } from 'node:timers/promises';

class DemoSpeech extends EventEmitter {
  constructor() { super(); this.ready = false; setImmediate(() => { this.ready = true; this.emit('ready'); }); }
  send(pcm) {
    assert.equal(pcm.length, 320);
    this.emit('transcript', { text: 'DEMO: pickup at Paderu training base near the market.', language: 'en-IN' });
  }
  ping() {}
  end() { setImmediate(() => this.emit('ended')); }
  destroy() {}
}
const config = { ready: true, missing: [], host: '127.0.0.1', port: 0, database: ':memory:', account: 'DEMO_ACCOUNT',
  username: 'demo', password: 'DEMO_LOCAL_STREAM_PASSWORD_00000000000', secret: 'DEMO_LOCAL_API_SECRET_0000000000000000',
  apiOrigin: 'http://127.0.0.1:1', sarvamKey: '', maxCalls: 1, maxDurationMs: 30000, idleMs: 10000,
  startTimeoutMs: 5000, language: 'en' };
const received = [];
const bridge = createBridge(config, { speechFactory: () => new DemoSpeech(), synthesize: async () => Buffer.alloc(3200),
  fetch: async (_url, request) => { received.push(JSON.parse(request.body)); return new Response('{"ok":true}'); } });
try {
  const { port } = await bridge.listen();
  const ws = new WebSocket(`ws://127.0.0.1:${port}/media?sample-rate=8000`, { headers: {
    Authorization: 'Basic ' + Buffer.from(`${config.username}:${config.password}`).toString('base64') } });
  await new Promise((resolve, reject) => { ws.once('open', resolve); ws.once('error', reject); });
  ws.send(JSON.stringify({ event: 'connected' }));
  ws.send(JSON.stringify({ event: 'start', stream_sid: 'DEMO_STREAM', sequence_number: 1, start: {
    account_sid: 'DEMO_ACCOUNT', call_sid: 'DEMO_CALL', stream_sid: 'DEMO_STREAM', media_format: { encoding: 'base64', sample_rate: 8000 } } }));
  await delay(50);
  ws.send(JSON.stringify({ event: 'media', stream_sid: 'DEMO_STREAM', sequence_number: 2,
    media: { chunk: 1, timestamp: 0, payload: Buffer.alloc(320).toString('base64') } }));
  ws.send(JSON.stringify({ event: 'dtmf', stream_sid: 'DEMO_STREAM', sequence_number: 3, dtmf: { digit: '0' } }));
  const deadline = Date.now() + 5000;
  while (received.length < 3 && Date.now() < deadline) await delay(50);
  assert.deepEqual(received.map(e => e.event_type), ['stream.started', 'transcript.final', 'stream.failed']);
  assert.equal(received.at(-1).payload.reason, 'operator_requested');
  console.log(JSON.stringify({ demo: true, real_phone_calls: 0, provider_requests: 0, native_database_changes: 0,
    result: 'passed', events: received.map(e => ({ type: e.event_type, sequence: e.sequence })) }, null, 2));
} finally { await bridge.close(); }

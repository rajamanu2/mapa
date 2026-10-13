import { EventEmitter } from 'node:events';
import { setTimeout as delay } from 'node:timers/promises';
import WebSocket from 'ws';

export const cfg = () => ({ ready: true, live: true, missing: [], host: '127.0.0.1', port: 0,
  database: ':memory:', account: 'DEMO_ACCOUNT', username: 'demo-stream', password: 's'.repeat(40),
  secret: 'b'.repeat(40), apiOrigin: 'http://127.0.0.1:8999', sarvamKey: 'test-only-not-a-provider-key',
  maxCalls: 10, maxDurationMs: 600000, idleMs: 30000, startTimeoutMs: 10000, language: 'en' });
export const start = (overrides = {}) => ({ event: 'start', sequence_number: 1, stream_sid: 'DEMO_STREAM',
  start: { stream_sid: 'DEMO_STREAM', call_sid: 'DEMO_CALL', account_sid: 'DEMO_ACCOUNT',
    from: '+919000000001', to: '+919000000002', media_format: { encoding: 'base64', sample_rate: 8000, bit_rate: 16 } }, ...overrides });
export const media = (overrides = {}) => ({ event: 'media', sequence_number: 2, stream_sid: 'DEMO_STREAM',
  media: { chunk: 1, timestamp: '0', payload: Buffer.alloc(320).toString('base64') }, ...overrides });
export class FakeSpeech extends EventEmitter {
  constructor() { super(); this.audio = []; this.ready = false; this.ending = false;
    setImmediate(() => { if (!this.ending) { this.ready = true; this.emit('ready'); } }); }
  send(pcm) { this.audio.push(pcm); }
  ping() {}
  end() { this.ending = true; setImmediate(() => this.emit('ended')); }
  destroy() { this.ending = true; }
}
export async function until(predicate, timeout = 3000) {
  const limit = Date.now() + timeout;
  while (!predicate()) { if (Date.now() > limit) throw new Error('condition_timeout'); await delay(10); }
}
export function connect(port, config, options = {}) {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(`ws://127.0.0.1:${port}/media?sample-rate=8000`, {
      headers: { Authorization: 'Basic ' + Buffer.from(`${config.username}:${config.password}`).toString('base64'), ...options.headers },
    });
    ws.once('open', () => resolve(ws)); ws.once('error', reject);
  });
}
export function rejectedConnect(port, path, headers) {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(`ws://127.0.0.1:${port}${path}`, { headers });
    ws.on('unexpected-response', (_req, res) => { res.resume(); resolve(res.statusCode); ws.terminate(); });
    ws.on('open', () => { ws.terminate(); reject(new Error('unexpected_accept')); });
    ws.on('error', () => {});
  });
}

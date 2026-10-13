import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { Journal, deliverOne } from '../journal.mjs';
import { parseStart } from '../protocol.mjs';
import { start, cfg } from './fixtures.mjs';
const call = () => parseStart(start(), 'DEMO_ACCOUNT', 8000);

test('durable monotonic events survive restart and interrupted stream is surfaced once', () => {
  const directory = mkdtempSync(join(tmpdir(), 'mapa-voice-test-'));
  try {
    let j = new Journal(join(directory, 'queue.sqlite'));
    const first = j.start(call(), true); const text = j.event({ ...call(), demo: true }, 'transcript.final', { text: 'DEMO Paderu', language: 'en' });
    assert.equal(first.sequence, 0); assert.equal(text.sequence, 1); const exact = j.next().body; j.close();
    j = new Journal(join(directory, 'queue.sqlite')); assert.equal(j.next().body, exact); assert.equal(j.recover(), 1); assert.equal(j.recover(), 0);
    const events = j.db.prepare('SELECT body FROM events ORDER BY id').all().map(r => JSON.parse(r.body));
    assert.deepEqual(events.map(e => e.sequence), [0, 1, 2]); assert.equal(events[2].payload.reason, 'process_restarted'); assert.equal(events[2].demo, true);
    assert.throws(() => j.start(call())); j.close();
  } finally {
    assert.ok(directory.startsWith(join(tmpdir(), 'mapa-voice-test-')));
    rmSync(directory, { recursive: true, force: true });
  }
});
test('only one active stream owns a call, replay cannot terminate another binding', () => {
  const j = new Journal(':memory:'); j.start(call());
  assert.throws(() => j.start(call())); assert.throws(() => j.start({ ...call(), stream_sid: 'OTHER' }));
  assert.equal(j.stats().queued, 1); j.finish(call(), 'stream.ended', 'done');
  assert.equal(j.finish(call(), 'stream.ended', 'done'), null);
  assert.equal(j.start({ ...call(), stream_sid: 'OTHER' }).sequence, 2); j.close();
});
test('retries send exact immutable body and a lost receipt is safely retried after restart', async () => {
  let now = Date.now(); const j = new Journal(':memory:', { now: () => now }); j.start(call());
  const attempts = [];
  await deliverOne(j, cfg(), async (_url, request) => { attempts.push(request.body); throw new Error('connection_reset_after_commit'); });
  assert.equal(j.stats().queued, 1); now += 2000;
  await deliverOne(j, cfg(), async (url, request) => { attempts.push(request.body); assert.ok(url.endsWith('/mapa/voice/v1/bridge/events'));
    assert.equal(request.headers.Authorization, `Bearer ${cfg().secret}`); return new Response(JSON.stringify({ ok: true, replayed: true })); });
  assert.equal(attempts[0], attempts[1]); assert.equal(j.stats().queued, 0); j.close();
});
test('409 is quarantined, not acknowledged, and later same-call events remain blocked', async () => {
  const j = new Journal(':memory:'); j.start(call()); j.event(call(), 'transcript.final', { text: 'untrusted' });
  await deliverOne(j, cfg(), async () => new Response('{}', { status: 409 }));
  assert.equal(j.stats().blocked, 1); assert.equal(j.stats().queued, 2); assert.equal(j.next(), undefined);
  j.start({ ...call(), call_sid: 'CALL_TWO', stream_sid: 'STREAM_TWO' }); assert.equal(JSON.parse(j.next().body).call_sid, 'CALL_TWO'); j.close();
});
test('capacity is bounded; queued events cannot cross API/account scopes', () => {
  const j = new Journal(':memory:', { maxEvents: 1 }); j.bindScope('first'); j.bindScope('empty-new-scope'); j.start(call());
  assert.throws(() => j.event(call(), 'transcript.final', { text: 'overflow' }), /journal_full/);
  assert.throws(() => j.bindScope('other-account'), /journal_scope_changed/); assert.equal(j.stats().queued, 1);
  assert.equal(j.recover(), 1); assert.equal(j.stats().queued, 2); j.close();
});

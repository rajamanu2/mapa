import { DatabaseSync } from 'node:sqlite';
import { mkdirSync, chmodSync } from 'node:fs';
import { dirname } from 'node:path';
import { randomUUID } from 'node:crypto';

export class Journal {
  constructor(path, { maxEvents = 10000, maxBytes = 32 * 1024 * 1024, now = Date.now } = {}) {
    this.now = now; this.maxEvents = maxEvents; this.maxBytes = maxBytes;
    if (path !== ':memory:') mkdirSync(dirname(path), { recursive: true, mode: 0o700 });
    this.db = new DatabaseSync(path);
    if (path !== ':memory:') { try { chmodSync(path, 0o600); } catch { /* Windows volume ACLs apply */ } }
    this.db.exec(`PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; PRAGMA busy_timeout=5000;
      CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY, event_id TEXT UNIQUE NOT NULL,
        call_key TEXT NOT NULL, body TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0,
        next_at INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, blocked INTEGER NOT NULL DEFAULT 0);
      CREATE TABLE IF NOT EXISTS sequences (call_key TEXT PRIMARY KEY, next_sequence INTEGER NOT NULL);
      CREATE TABLE IF NOT EXISTS streams (stream_key TEXT PRIMARY KEY, call_key TEXT NOT NULL, active INTEGER NOT NULL, binding TEXT NOT NULL, created_at INTEGER NOT NULL);
      CREATE UNIQUE INDEX IF NOT EXISTS active_call ON streams(call_key) WHERE active=1;
      CREATE TABLE IF NOT EXISTS journal_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);`);
  }
  transaction(fn) {
    this.db.exec('BEGIN IMMEDIATE');
    try { const result = fn(); this.db.exec('COMMIT'); return result; }
    catch (e) { this.db.exec('ROLLBACK'); throw e; }
  }
  capacity(terminal = false) {
    const row = this.db.prepare('SELECT COUNT(*) n, COALESCE(SUM(length(CAST(body AS BLOB))),0) bytes FROM events').get();
    // Reserve bounded space for ending every accepted socket. A full normal
    // queue must not prevent restart recovery and then deadlock delivery.
    if (row.n >= this.maxEvents + (terminal ? 50 : 0) || row.bytes >= this.maxBytes + (terminal ? 50000 : 0)) throw new Error('journal_full');
  }
  append(call, eventType, payload, demo = false, terminal = false) {
    this.capacity(terminal);
    const key = `${call.account_sid}:${call.call_sid}`;
    this.db.prepare('INSERT INTO sequences VALUES (?,0) ON CONFLICT DO NOTHING').run(key);
    const { n } = this.db.prepare('UPDATE sequences SET next_sequence=next_sequence+1 WHERE call_key=? RETURNING next_sequence-1 n').get(key);
    const event = { event_id: randomUUID(), event_type: eventType, account_sid: call.account_sid,
      call_sid: call.call_sid, stream_sid: call.stream_sid, sequence: n,
      occurred_at: new Date(this.now()).toISOString(), demo, payload };
    const body = JSON.stringify(event);
    if (Buffer.byteLength(body) > 70000) throw new Error('event_too_large');
    this.db.prepare('INSERT INTO events(event_id,call_key,body,created_at) VALUES (?,?,?,?)').run(event.event_id, key, body, this.now());
    return event;
  }
  start(call, demo = false) {
    return this.transaction(() => {
      this.capacity();
      if (this.db.prepare('SELECT COUNT(*) n FROM streams').get().n >= 100000) throw new Error('stream_capacity');
      const key = `${call.account_sid}:${call.call_sid}`;
      this.db.prepare('INSERT INTO streams VALUES (?,?,1,?,?)').run(`${call.account_sid}:${call.stream_sid}`, key, JSON.stringify({ ...call, demo }), this.now());
      return this.append(call, 'stream.started', { caller: call.caller, to: call.to, sample_rate: call.sample_rate }, demo);
    });
  }
  event(call, type, payload) {
    return this.transaction(() => this.append(call, type, payload, !!call.demo));
  }
  finish(call, type, reason) {
    return this.transaction(() => {
      const key = `${call.account_sid}:${call.stream_sid}`;
      const active = this.db.prepare('SELECT active FROM streams WHERE stream_key=?').get(key);
      if (!active?.active) return null;
      const event = this.append(call, type, { reason }, !!call.demo, true);
      this.db.prepare('UPDATE streams SET active=0 WHERE stream_key=?').run(key);
      return event;
    });
  }
  recover() {
    const active = this.db.prepare('SELECT binding FROM streams WHERE active=1').all();
    for (const row of active) this.finish(JSON.parse(row.binding), 'stream.failed', 'process_restarted');
    return active.length;
  }
  next() {
    // A failed event blocks later events for its own call, not every caller.
    return this.db.prepare(`SELECT e.* FROM events e WHERE e.blocked=0 AND e.next_at<=?
      AND NOT EXISTS (SELECT 1 FROM events p WHERE p.call_key=e.call_key AND p.id<e.id)
      ORDER BY e.id LIMIT 1`).get(this.now());
  }
  retry(row, blocked = false) {
    this.db.prepare('UPDATE events SET attempts=attempts+1,next_at=?,blocked=? WHERE id=?').run(
      this.now() + Math.min(60000, 1000 * 2 ** Math.min(row.attempts, 6)), Number(blocked), row.id);
  }
  ack(row) { this.db.prepare('DELETE FROM events WHERE id=?').run(row.id); }
  stats() {
    const r = this.db.prepare('SELECT COUNT(*) queued, SUM(blocked) blocked, MIN(created_at) oldest FROM events').get();
    return { queued: Number(r.queued), blocked: Number(r.blocked || 0), oldest_age_ms: r.oldest ? this.now() - r.oldest : 0 };
  }
  bindScope(scope) {
    const previous = this.db.prepare("SELECT value FROM journal_meta WHERE key='scope'").get();
    if (previous && previous.value !== scope) {
      const used = this.db.prepare('SELECT (SELECT COUNT(*) FROM events)+(SELECT COUNT(*) FROM streams) n').get().n;
      if (used) throw new Error('journal_scope_changed');
      this.db.prepare("UPDATE journal_meta SET value=? WHERE key='scope'").run(scope);
    }
    this.db.prepare("INSERT INTO journal_meta VALUES ('scope',?) ON CONFLICT DO NOTHING").run(scope);
  }
  close() { this.db.close(); }
}

export async function deliverOne(journal, cfg, fetcher = fetch) {
  const row = journal.next();
  if (!row) return false;
  try {
    const response = await fetcher(cfg.apiOrigin + '/mapa/voice/v1/bridge/events', {
      method: 'POST', redirect: 'error', signal: AbortSignal.timeout(10000),
      headers: { Authorization: `Bearer ${cfg.secret}`, 'Content-Type': 'application/json' }, body: row.body,
    });
    if (!response.ok) {
      journal.retry(row, [400, 403, 404, 409, 422].includes(response.status));
      return false;
    }
    const reader = response.body.getReader(); let length = 0; const chunks = [];
    try {
      for (;;) {
        const { done, value } = await reader.read(); if (done) break;
        length += value.byteLength;
        if (length > 20000) throw new Error('oversized_receipt');
        chunks.push(value);
      }
    } finally { await reader.cancel(); }
    const text = Buffer.concat(chunks).toString('utf8');
    const receipt = JSON.parse(text);
    if (receipt.ok !== true) throw new Error('invalid_receipt');
    journal.ack(row);
    return true;
  } catch { journal.retry(row); return false; }
}

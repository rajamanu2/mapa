import http from 'node:http';
import { pathToFileURL } from 'node:url';
import { createHash } from 'node:crypto';
import { setTimeout as delay } from 'node:timers/promises';
import WebSocket, { WebSocketServer } from 'ws';
import { readConfig, secretEqual } from './config.mjs';
import { parsePath, parseStart, PacketOrder, field, outbound, normalizeLanguage } from './protocol.mjs';
import { Journal, deliverOne } from './journal.mjs';
import { SarvamSpeech, synthesize } from './providers.mjs';
import { PROMPTS, answerDraft } from './prompts.mjs';

export function createBridge(cfg, dependencies = {}) {
  const journal = dependencies.journal ?? new Journal(cfg.database);
  journal.bindScope(createHash('sha256').update(`${cfg.apiOrigin}|${cfg.account}`).digest('hex'));
  journal.recover();
  const speechFactory = dependencies.speechFactory ?? (options => new SarvamSpeech(options));
  const tts = dependencies.synthesize ?? synthesize;
  const fetcher = dependencies.fetch ?? fetch;
  const sessions = new Set();
  let stopping = false, storageFault = false, pumping = false;
  const wss = new WebSocketServer({ noServer: true, maxPayload: 150000, perMessageDeflate: false });
  const json = (res, status, value) => {
    res.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
    res.end(JSON.stringify(value));
  };
  const status = () => {
    const queue = journal.stats();
    const impaired = storageFault || queue.blocked > 0 || queue.oldest_age_ms > 60000;
    return { status: impaired ? 'attention' : cfg.ready ? 'ready' : 'disabled',
      live_enabled: cfg.ready, ready: cfg.ready && !impaired && !stopping,
      missing_configuration: cfg.missing, ...queue, active_streams: sessions.size };
  };
  const server = http.createServer((req, res) => {
    if (req.method === 'GET' && req.url === '/health') {
      const result = status(); json(res, result.status === 'attention' ? 503 : 200, result);
    } else if (req.method === 'GET' && req.url === '/ready') {
      const result = status(); json(res, result.ready ? 200 : 503, result);
    } else json(res, 404, { error: 'not_found' });
  });
  server.headersTimeout = 10000; server.requestTimeout = 10000;
  server.on('clientError', (_error, socket) => socket.end('HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n'));
  server.on('upgrade', (req, socket, head) => {
    const reject = status => socket.end(`HTTP/1.1 ${status}\r\nConnection: close\r\nCache-Control: no-store\r\n\r\n`);
    let sampleRate;
    try { sampleRate = parsePath(req.url); } catch { return reject('400 Bad Request'); }
    // Browsers cannot connect even if an operator accidentally pastes a key.
    if (req.headers.origin) return reject('403 Forbidden');
    if (!status().ready) return reject('503 Service Unavailable');
    const expected = 'Basic ' + Buffer.from(`${cfg.username}:${cfg.password}`).toString('base64');
    if (!secretEqual(req.headers.authorization, expected)) return reject('401 Unauthorized');
    if (wss.clients.size >= cfg.maxCalls) return reject('503 Service Unavailable');
    wss.handleUpgrade(req, socket, head, ws => wss.emit('connection', ws, sampleRate));
  });

  wss.on('connection', (ws, sampleRate) => {
    let call, order, speech, connected = false, receiving = true, finalized = false, ending = false;
    let terminalType = 'stream.ended', terminalReason = 'stream_stopped', terminalTimer;
    let lastPacket = Date.now(), utterances = 0;
    const finalUtterances = new Map();
    let language = cfg.language, stage = 0, generation = 0, ttsController;
    let outputSequence = 0, outputChunk = 0, outputTimestamp = 0, pendingMark;
    const born = Date.now();
    const session = { stop: reason => finish('stream.failed', reason, false) };
    sessions.add(session);

    function emit(type, payload) {
      try { return journal.event(call, type, payload); }
      catch { storageFault = true; throw new Error('journal_unavailable'); }
    }
    function finalize() {
      if (finalized) return;
      finalized = true; receiving = false; ending = true;
      clearInterval(watchdog); clearTimeout(terminalTimer); cancelPlayback();
      speech?.destroy();
      if (call) {
        try { journal.finish(call, terminalType, terminalReason); }
        catch { storageFault = true; /* active journal row persists for recovery */ }
      }
      sessions.delete(session);
      if (ws.readyState === WebSocket.OPEN) ws.close(1000, 'handoff');
      const kill = setTimeout(() => ws.terminate(), 1000); kill.unref();
    }
    function finish(type, reason, drain = true) {
      if (finalized) return;
      if (type === 'stream.failed') { terminalType = type; terminalReason = reason; }
      else if (!ending) { terminalType = type; terminalReason = reason; }
      if (ending) return;
      ending = true; receiving = false; cancelPlayback();
      // Closing Voicebot advances to the operator Connect applet configured
      // by the operator. Never claim the software itself transferred a call.
      if (ws.readyState === WebSocket.OPEN) ws.close(1000, 'handoff');
      if (drain && speech?.ready) {
        speech.end(); terminalTimer = setTimeout(finalize, 5000);
      } else finalize();
    }
    function send(message) {
      if (ws.readyState !== WebSocket.OPEN || ws.bufferedAmount > 160000) throw new Error('playback_backpressure');
      ws.send(JSON.stringify(message));
    }
    function cancelPlayback() {
      generation++; ttsController?.abort(); pendingMark = null;
      if (call && ws.readyState === WebSocket.OPEN) {
        try { send(outbound(call, 'clear')); } catch { /* shutdown already bounded */ }
      }
    }
    async function question(index) {
      cancelPlayback(); const current = generation;
      const controller = new AbortController();
      ttsController = controller;
      const timeout = setTimeout(() => controller.abort(), 10000);
      try {
        const pcm = await tts({ text: PROMPTS[language][index], language, sampleRate,
          key: cfg.sarvamKey, signal: controller.signal });
        clearTimeout(timeout);
        if (current !== generation || ending || finalized) return;
        const frameSize = 3200, started = Date.now(); let bytesSent = 0;
        for (let offset = 0; offset < pcm.length; offset += frameSize) {
          if (current !== generation || ending || finalized) return;
          const part = pcm.subarray(offset, offset + frameSize);
          const frame = Buffer.alloc(frameSize); part.copy(frame);
          outputSequence++; outputChunk++;
          const media = { chunk: outputChunk, timestamp: String(Math.round(outputTimestamp)), payload: frame.toString('base64') };
          if (call.dialect === 'camel') media.sequenceNumber = String(outputSequence);
          send(outbound(call, 'media', { [call.dialect === 'camel' ? 'sequenceNumber' : 'sequence_number']: outputSequence, media }));
          bytesSent += frame.length; outputTimestamp += frame.length / (sampleRate * 2) * 1000;
          await delay(Math.max(0, bytesSent / (sampleRate * 2) * 1000 - (Date.now() - started)));
        }
        if (current !== generation || ending || finalized) return;
        pendingMark = `mapa-${current}-${index}`;
        send(outbound(call, 'mark', { [call.dialect === 'camel' ? 'sequenceNumber' : 'sequence_number']: ++outputSequence,
          mark: { name: pendingMark } }));
        if (index === 3) {
          // Do not leave a caller stuck if the provider does not acknowledge
          // playback; the configured human applet remains the destination.
          terminalTimer = setTimeout(() => finish('stream.ended', 'intake_complete'), 4000);
        }
      } catch {
        if (current === generation && !ending && !finalized) finish('stream.failed', 'tts_unavailable');
      } finally { clearTimeout(timeout); }
    }
    function transcript(message) {
      if (finalized || !call) return;
      try {
        if (++utterances > 200) throw new Error('transcript_limit');
        // Timestamp identity, when provided, catches duplicate ASR deliveries
        // while preserving genuinely repeated words in a later utterance.
        const hasTimes = Number.isFinite(message.start_s) && Number.isFinite(message.end_s);
        const key = hasTimes ? `${message.start_s}:${message.end_s}` : null;
        if (hasTimes && (message.start_s < 0 || message.end_s < message.start_s)) throw new Error('invalid_transcript_time');
        if (key && finalUtterances.has(key)) {
          if (finalUtterances.get(key) !== message.text) throw new Error('conflicting_final_transcript');
          return;
        }
        if (key) finalUtterances.set(key, message.text);
        const text = message.text.trim();
        const detected = normalizeLanguage(message.language);
        emit('transcript.final', { text, language: detected, draft: answerDraft(stage, text) });
        if (ending) return;
        if (detected !== 'unknown') language = detected;
        stage = Math.min(3, stage + 1);
        void question(stage);
      } catch { finish('stream.failed', 'transcript_delivery_failed', false); }
    }
    const watchdog = setInterval(() => {
      if (finalized) return;
      if (!call && Date.now() - born > cfg.startTimeoutMs) finish('stream.failed', 'start_timeout', false);
      else if (Date.now() - born > cfg.maxDurationMs) finish('stream.failed', 'intake_timeout');
      else if (Date.now() - lastPacket > cfg.idleMs) finish('stream.failed', 'audio_timeout');
      else speech?.ping();
    }, 1000);
    ws.on('message', (raw, isBinary) => {
      if (!receiving || finalized) return;
      try {
        if (isBinary) throw new Error('binary_message');
        const message = JSON.parse(raw.toString('utf8'));
        if (!message || typeof message !== 'object') throw new Error('invalid_message');
        lastPacket = Date.now();
        if (message.event === 'connected') {
          if (connected || call) throw new Error('duplicate_connected');
          connected = true; return;
        }
        if (message.event === 'start') {
          if (call) throw new Error('duplicate_start');
          const candidate = parseStart(message, cfg.account, sampleRate);
          // Only claim this socket after the UNIQUE durable binding succeeds;
          // a replay cannot terminate the existing legitimate stream.
          journal.start(candidate); call = candidate; order = new PacketOrder(message);
          speech = speechFactory({ key: cfg.sarvamKey, sampleRate });
          speech.on('ready', () => { if (!ending) void question(stage); });
          speech.on('transcript', transcript);
          speech.on('speech', () => { if (!ending && stage < 3) cancelPlayback(); });
          speech.on('failure', reason => finish('stream.failed', reason, false));
          speech.on('ended', () => { if (ending) finalize(); else finish('stream.failed', 'asr_ended', false); });
          return;
        }
        if (!call) throw new Error('start_required');
        if (!['media', 'dtmf', 'mark', 'stop'].includes(message.event)) throw new Error('unknown_event');
        const pcm = order.accept(message, call);
        if (message.event === 'media') speech.send(pcm);
        else if (message.event === 'dtmf') {
          const digit = String(message.dtmf?.digit ?? '');
          if (!/^[0-9*#]$/.test(digit)) throw new Error('invalid_dtmf');
          if (digit === '0') finish('stream.failed', 'operator_requested');
          else if (['1', '2'].includes(digit)) { language = digit === '2' ? 'te' : 'en'; void question(stage); }
        } else if (message.event === 'mark') {
          if (pendingMark && message.mark?.name === pendingMark && stage === 3) {
            clearTimeout(terminalTimer); finish('stream.ended', 'intake_complete');
          }
        } else if (message.event === 'stop') {
          const stop = message.stop;
          if (stop && ((field(stop, 'call_sid', 'callSid') !== undefined && field(stop, 'call_sid', 'callSid') !== call.call_sid) ||
              (field(stop, 'account_sid', 'accountSid') !== undefined && field(stop, 'account_sid', 'accountSid') !== call.account_sid)))
            throw new Error('wrong_stop_identity');
          finish('stream.ended', stop?.reason === 'callended' ? 'caller_disconnected' : 'stream_stopped');
        }
      } catch (error) {
        const allowed = ['packet_gap', 'packet_reordered', 'audio_gap', 'audio_reordered', 'audio_timestamp_gap',
          'wrong_stream', 'wrong_account', 'duplicate_start', 'wrong_stop_identity', 'unsupported_encoding'];
        finish('stream.failed', allowed.includes(error.message) ? error.message : 'protocol_error', false);
      }
    });
    ws.on('error', () => finish('stream.failed', 'stream_connection_error', false));
    ws.on('close', () => { if (!ending && !finalized) finish('stream.failed', 'stream_disconnected'); });
  });

  const pumpTimer = setInterval(async () => {
    if (pumping || stopping || cfg.secret.length < 32) return;
    pumping = true;
    try { for (let i = 0; i < 20 && !stopping && await deliverOne(journal, cfg, fetcher); i++); }
    finally { pumping = false; }
  }, 250);
  return {
    server, journal, status,
    listen: () => new Promise((resolve, reject) => {
      server.once('error', reject);
      server.listen(cfg.port, cfg.host, () => { server.off('error', reject); resolve(server.address()); });
    }),
    close: async () => {
      stopping = true; clearInterval(pumpTimer);
      for (const session of [...sessions]) session.stop('bridge_shutdown');
      for (const ws of wss.clients) ws.terminate();
      await new Promise(resolve => server.close(resolve));
      while (pumping) await delay(10);
      journal.close();
    },
  };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    const cfg = readConfig();
    const bridge = createBridge(cfg);
    await bridge.listen();
    console.log(`MAPA voice bridge listening on port ${cfg.port}; live intake ${cfg.ready ? 'enabled' : 'disabled'}. No call content is logged.`);
    for (const event of ['SIGTERM', 'SIGINT']) process.once(event, async () => { await bridge.close(); process.exit(0); });
  } catch (error) {
    // Error identifiers contain no provider response, URL, caller or secrets.
    console.error('MAPA voice bridge could not start. Check configuration and private journal access.');
    process.exitCode = 1;
  }
}

import WebSocket from 'ws';
import { EventEmitter } from 'node:events';

export class SarvamSpeech extends EventEmitter {
  constructor({ key, sampleRate, language = 'auto', createSocket = (url, options) => new WebSocket(url, options) }) {
    super();
    this.ready = false; this.ending = false; this.pending = []; this.pendingBytes = 0;
    const url = new URL('wss://api.sarvam.ai/speech-to-text-realtime/ws');
    url.search = new URLSearchParams({ language_code: language, model: 'saaras:v3-realtime',
      encoding: 'linear16', sample_rate: String(sampleRate), stream_type: 'balanced',
      mode: 'transcribe', endpointing: 'vad', return_timestamps: 'true' }).toString();
    this.socket = createSocket(url, { headers: { 'API-SUBSCRIPTION-KEY': key },
      handshakeTimeout: 10000, maxPayload: 100000, perMessageDeflate: false, followRedirects: false });
    this.timer = setTimeout(() => this.fail('asr_start_timeout'), 10000);
    this.socket.on('message', bytes => {
      try {
        const message = JSON.parse(bytes.toString());
        if (message.event === 'session.begin') {
          if (this.ready) return this.fail('asr_protocol_error');
          this.ready = true; clearTimeout(this.timer);
          for (const pcm of this.pending) this.send(pcm);
          this.pending = []; this.pendingBytes = 0;
          this.emit('ready');
          if (this.ending) this.socket.send(JSON.stringify({ event: 'end' }));
        } else if (message.event === 'transcript.final') {
          if (!this.ready || typeof message.text !== 'string' || message.text.length > 16000) return this.fail('asr_protocol_error');
          if (message.text.trim()) this.emit('transcript', message);
        } else if (message.event === 'vad.speech_start') this.emit('speech');
        else if (message.event === 'session.end') { this.emit('ended'); this.destroy(); }
        else if (message.event === 'error') this.fail('asr_unavailable');
      } catch { this.fail('asr_protocol_error'); }
    });
    this.socket.on('error', () => this.fail('asr_unavailable'));
    this.socket.on('close', () => {
      clearTimeout(this.timer);
      if (!this.ending) this.fail('asr_disconnected');
    });
  }
  send(pcm) {
    if (this.ending) return;
    if (!this.ready) {
      this.pendingBytes += pcm.length;
      if (this.pendingBytes > 160000) return this.fail('asr_buffer_full');
      this.pending.push(pcm); return;
    }
    if (this.socket.readyState !== WebSocket.OPEN || this.socket.bufferedAmount > 160000) return this.fail('asr_backpressure');
    this.socket.send(JSON.stringify({ event: 'audio_input', audio: pcm.toString('base64') }));
  }
  ping() {
    if (this.ready && !this.ending && this.socket.readyState === WebSocket.OPEN) this.socket.send('{"event":"ping"}');
  }
  end() {
    if (this.ending) return;
    this.ending = true;
    if (this.ready && this.socket.readyState === WebSocket.OPEN) this.socket.send('{"event":"end"}');
  }
  fail(reason) {
    if (this.failed) return;
    this.failed = true;
    this.emit('failure', reason);
    this.destroy();
  }
  destroy() {
    this.ending = true; clearTimeout(this.timer); this.pending = []; this.pendingBytes = 0;
    this.socket.terminate();
  }
}

export function wavPcm(wav, sampleRate) {
  if (!Buffer.isBuffer(wav) || wav.length < 44 || wav.length > 2000000 ||
      wav.toString('ascii', 0, 4) !== 'RIFF' || wav.toString('ascii', 8, 12) !== 'WAVE') throw new Error('invalid_tts_audio');
  let format, data;
  for (let offset = 12; offset + 8 <= wav.length;) {
    const name = wav.toString('ascii', offset, offset + 4), size = wav.readUInt32LE(offset + 4);
    const start = offset + 8, end = start + size;
    if (end > wav.length) throw new Error('invalid_tts_audio');
    if (name === 'fmt ') {
      if (size < 16) throw new Error('invalid_tts_audio');
      format = wav.subarray(start, end);
    } else if (name === 'data') data = wav.subarray(start, end);
    offset = end + (size % 2);
  }
  if (!format || !data?.length || data.length % 2 || format.readUInt16LE(0) !== 1 ||
      format.readUInt16LE(2) !== 1 || format.readUInt32LE(4) !== sampleRate || format.readUInt16LE(14) !== 16 ||
      data.length > sampleRate * 2 * 30) throw new Error('unsupported_tts_audio');
  return data;
}

export async function synthesize({ text, language, sampleRate, key, fetcher = fetch, signal }) {
  const response = await fetcher('https://api.sarvam.ai/text-to-speech', {
    method: 'POST', redirect: 'error', signal: signal ?? AbortSignal.timeout(10000),
    headers: { 'api-subscription-key': key, 'Content-Type': 'application/json' },
    // The current REST contract requires language_code (not the older
    // target_language_code shown in some integration examples).
    body: JSON.stringify({ text, language_code: language === 'te' ? 'te-IN' : 'en-IN',
      model: 'bulbul:v3', speaker: 'shubh', speech_sample_rate: sampleRate, output_audio_codec: 'wav' }),
  });
  if (!response.ok) throw new Error('tts_unavailable');
  // Read a bounded body rather than trusting Content-Length from the provider.
  const reader = response.body.getReader(); let bytes = 0; const parts = [];
  try {
    for (;;) {
      const { done, value } = await reader.read(); if (done) break;
      bytes += value.byteLength;
      if (bytes > 2800000) throw new Error('tts_response_too_large');
      parts.push(value);
    }
  } finally { await reader.cancel(); }
  const result = JSON.parse(Buffer.concat(parts).toString('utf8'));
  if (!Array.isArray(result.audios) || result.audios.length !== 1 || typeof result.audios[0] !== 'string') throw new Error('invalid_tts_audio');
  return wavPcm(Buffer.from(result.audios[0], 'base64'), sampleRate);
}

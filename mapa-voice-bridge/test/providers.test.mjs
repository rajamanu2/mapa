import test from 'node:test';
import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { SarvamSpeech, wavPcm, synthesize } from '../providers.mjs';

function wav(rate = 8000) {
  const b = Buffer.alloc(364); b.write('RIFF'); b.writeUInt32LE(356, 4); b.write('WAVE', 8); b.write('fmt ', 12);
  b.writeUInt32LE(16, 16); b.writeUInt16LE(1, 20); b.writeUInt16LE(1, 22); b.writeUInt32LE(rate, 24);
  b.writeUInt32LE(rate * 2, 28); b.writeUInt16LE(2, 32); b.writeUInt16LE(16, 34); b.write('data', 36); b.writeUInt32LE(320, 40); return b;
}
test('WAV extraction validates PCM16 mono and exact sample rate, no header passed to Exotel', () => {
  assert.equal(wavPcm(wav(), 8000).length, 320);
  assert.throws(() => wavPcm(wav(), 16000));
  const stereo = wav(); stereo.writeUInt16LE(2, 22); assert.throws(() => wavPcm(stereo, 8000));
  const short = wav().subarray(0, 100); assert.throws(() => wavPcm(short, 8000));
});
test('Sarvam speech handshake, buffering, final transcript and graceful end use real protocol', () => {
  const socket = new EventEmitter(); socket.readyState = 1; socket.bufferedAmount = 0;
  const sent = []; socket.send = data => sent.push(JSON.parse(data)); socket.terminate = () => {};
  const speech = new SarvamSpeech({ key: 'mock-key', sampleRate: 16000, createSocket: (url, options) => {
    assert.equal(url.protocol, 'wss:'); assert.equal(url.hostname, 'api.sarvam.ai'); assert.equal(url.searchParams.get('sample_rate'), '16000');
    assert.equal(url.searchParams.get('encoding'), 'linear16'); assert.equal(options.headers['API-SUBSCRIPTION-KEY'], 'mock-key'); return socket;
  } });
  const transcripts = []; speech.on('transcript', data => transcripts.push(data));
  speech.send(Buffer.alloc(640)); assert.equal(sent.length, 0);
  socket.emit('message', Buffer.from('{"event":"session.begin"}')); assert.equal(sent[0].event, 'audio_input');
  socket.emit('message', Buffer.from('{"event":"transcript.partial","text":"part"}')); assert.equal(transcripts.length, 0);
  socket.emit('message', Buffer.from('{"event":"transcript.final","text":"DEMO Telugu","language":"te-IN"}'));
  assert.equal(transcripts.length, 1); speech.end(); assert.equal(sent.at(-1).event, 'end'); speech.destroy();
});
test('TTS uses server header, fixed endpoint and correct Telugu codec contract', async () => {
  const pcm = await synthesize({ text: 'DEMO prompt', language: 'te', sampleRate: 8000, key: 'secret-test', fetcher: async (url, request) => {
    assert.equal(url, 'https://api.sarvam.ai/text-to-speech'); assert.equal(request.headers['api-subscription-key'], 'secret-test');
    const body = JSON.parse(request.body); assert.equal(body.language_code, 'te-IN'); assert.equal(body.target_language_code, undefined); assert.equal(body.speech_sample_rate, 8000);
    assert.equal(body.output_audio_codec, 'wav'); return new Response(JSON.stringify({ audios: [wav().toString('base64')] }));
  } }); assert.equal(pcm.length, 320);
});
test('English prompt uses the current REST language field and never retries rejected provider audio', async () => {
  let calls = 0;
  await assert.rejects(() => synthesize({ text: 'MAPA connection check', language: 'en', sampleRate: 16000, key: 'test-only', fetcher: async (_url, request) => {
    calls++;
    const body = JSON.parse(request.body);
    assert.equal(body.language_code, 'en-IN'); assert.equal(body.target_language_code, undefined);
    assert.equal(body.model, 'bulbul:v3'); assert.equal(body.speech_sample_rate, 16000);
    return new Response('{"error":"invalid request"}', { status: 422 });
  } }), /tts_unavailable/);
  assert.equal(calls, 1);
});

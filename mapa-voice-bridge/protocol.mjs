// Exotel Voicebot uses snake_case; its official AgentStream samples also use
// camelCase. Accept either documented spelling, but never conflicting values.
export function field(object, snake, camel) {
  const a = object?.[snake], b = object?.[camel];
  if (a !== undefined && b !== undefined && String(a) !== String(b)) throw new Error('conflicting_fields');
  return a ?? b;
}
export function integer(value, name, minimum = 0) {
  if (!(typeof value === 'number' || typeof value === 'string' && /^\d+$/.test(value))) throw new Error(name);
  const n = Number(value);
  if (!Number.isSafeInteger(n) || n < minimum) throw new Error(name);
  return n;
}
function id(value) {
  if (typeof value !== 'string' || !/^[A-Za-z0-9_-]{1,128}$/.test(value)) throw new Error('invalid_identity');
  return value;
}
export function parsePath(raw) {
  const u = new URL(raw, 'http://bridge');
  if (u.pathname !== '/media' || [...u.searchParams.keys()].some(k => k !== 'sample-rate') ||
      u.searchParams.getAll('sample-rate').length > 1) throw new Error('invalid_path');
  const rate = integer(u.searchParams.get('sample-rate') ?? 8000, 'unsupported_sample_rate');
  if (![8000, 16000].includes(rate)) throw new Error('unsupported_sample_rate');
  return rate;
}
export function parseStart(message, account, sampleRate) {
  const s = message.start;
  if (message.event !== 'start' || !s || typeof s !== 'object') throw new Error('invalid_start');
  const accountSid = id(field(s, 'account_sid', 'accountSid'));
  if (accountSid !== account) throw new Error('wrong_account');
  const streamSid = id(field(message, 'stream_sid', 'streamSid') ?? field(s, 'stream_sid', 'streamSid'));
  const nestedSid = field(s, 'stream_sid', 'streamSid');
  if (nestedSid !== undefined && nestedSid !== streamSid) throw new Error('wrong_stream');
  const format = s.media_format ?? s.mediaFormat;
  if (s.media_format && s.mediaFormat) throw new Error('conflicting_fields');
  if (format) {
    const rate = field(format, 'sample_rate', 'sampleRate');
    if (rate !== undefined && integer(rate, 'unsupported_sample_rate') !== sampleRate) throw new Error('sample_rate_mismatch');
    // "base64" is the Voicebot wrapper, not a codec. All accepted formats
    // mean the documented little-endian PCM16 mono, never mu-law decoding.
    if (format.encoding !== undefined && !['base64', 'raw/slin', 'audio/x-raw', 'audio/x-l16', 'pcm_s16le', 'linear16'].includes(format.encoding))
      throw new Error('unsupported_encoding');
    if (format.channels !== undefined && integer(format.channels, 'unsupported_channels') !== 1) throw new Error('unsupported_channels');
    const bits = field(format, 'bit_rate', 'bitRate');
    if (bits !== undefined && integer(bits, 'unsupported_bit_depth') !== 16) throw new Error('unsupported_bit_depth');
  }
  const phone = p => typeof p === 'string' && /^\+?[0-9]{7,15}$/.test(p) ? p : '';
  return { account_sid: accountSid, call_sid: id(field(s, 'call_sid', 'callSid')), stream_sid: streamSid,
    caller: phone(s.from), to: phone(s.to), sample_rate: sampleRate,
    dialect: message.streamSid !== undefined || s.streamSid !== undefined ? 'camel' : 'snake' };
}
export function audioPayload(message, sampleRate) {
  const payload = message.media?.payload;
  if (typeof payload !== 'string' || payload.length > 136536 || !/^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/.test(payload))
    throw new Error('invalid_audio');
  const bytes = Buffer.from(payload, 'base64');
  if (!bytes.length || bytes.length % 2 || bytes.length > 100000 || bytes.length > sampleRate * 2 * 2)
    throw new Error('invalid_audio_size');
  return bytes;
}
export class PacketOrder {
  constructor(start) {
    this.lastSequence = null; this.lastChunk = null; this.nextTimestamp = null;
    const n = field(start, 'sequence_number', 'sequenceNumber');
    if (n !== undefined) this.lastSequence = integer(n, 'invalid_sequence');
  }
  accept(message, call) {
    if (field(message, 'stream_sid', 'streamSid') !== call.stream_sid) throw new Error('wrong_stream');
    const rawSeq = field(message, 'sequence_number', 'sequenceNumber') ?? field(message.media, 'sequence_number', 'sequenceNumber');
    // Some Connect paths carry only media.chunk; Voicebot carries a global
    // sequence on all events. Track both when available so DTMF is not a gap.
    if (rawSeq !== undefined) {
      const seq = integer(rawSeq, 'invalid_sequence');
      if (this.lastSequence !== null && seq !== this.lastSequence + 1) throw new Error(seq <= this.lastSequence ? 'packet_reordered' : 'packet_gap');
      this.lastSequence = seq;
    }
    if (message.event !== 'media') return null;
    const chunk = integer(message.media?.chunk, 'invalid_chunk', 1);
    if (this.lastChunk !== null && chunk !== this.lastChunk + 1) throw new Error(chunk <= this.lastChunk ? 'audio_reordered' : 'audio_gap');
    if (this.lastChunk === null && chunk !== 1) throw new Error('audio_gap');
    const timestamp = integer(message.media?.timestamp, 'invalid_timestamp');
    const bytes = audioPayload(message, call.sample_rate);
    if (this.nextTimestamp !== null && Math.abs(timestamp - this.nextTimestamp) > 2) throw new Error('audio_timestamp_gap');
    this.lastChunk = chunk;
    this.nextTimestamp = timestamp + bytes.length / (call.sample_rate * 2) * 1000;
    return bytes;
  }
}
export function outbound(call, event, body = {}) {
  return { event, [call.dialect === 'camel' ? 'streamSid' : 'stream_sid']: call.stream_sid, ...body };
}
export function normalizeLanguage(raw) {
  return typeof raw === 'string' && /^te(?:-|$)/i.test(raw) ? 'te' : typeof raw === 'string' && /^en(?:-|$)/i.test(raw) ? 'en' : 'unknown';
}

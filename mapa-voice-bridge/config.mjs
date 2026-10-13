import { timingSafeEqual } from 'node:crypto';

export function secretEqual(left, right) {
  const a = Buffer.from(String(left || '')), b = Buffer.from(String(right || ''));
  return a.length > 0 && a.length === b.length && timingSafeEqual(a, b);
}

export function readConfig(env = process.env) {
  const live = env.MAPA_VOICE_LIVE_ENABLED === 'true';
  const cfg = {
    live, host: env.HOST || '127.0.0.1', port: Number(env.PORT || 8789),
    database: env.QUEUE_DB || './data/voice-journal.sqlite',
    account: env.EXOTEL_ACCOUNT_SID || '',
    username: env.STREAM_USER || '', password: env.STREAM_PASSWORD || '',
    secret: env.MAPA_VOICE_BRIDGE_SECRET || '',
    apiOrigin: env.MAPA_API_ORIGIN || 'http://application:8000',
    sarvamKey: env.SARVAM_API_KEY || '',
    maxCalls: Number(env.MAX_CALLS || 10),
    maxDurationMs: Number(env.MAX_CALL_SECONDS || 600) * 1000,
    idleMs: 30000, startTimeoutMs: 10000,
    fallbackConfirmed: env.MAPA_VOICE_HUMAN_FALLBACK_CONFIGURED === 'true',
    language: env.MAPA_VOICE_DEFAULT_LANGUAGE === 'te' ? 'te' : 'en',
    publicWss: env.MAPA_VOICE_PUBLIC_WSS || '',
    allowInternal: env.MAPA_ALLOW_INTERNAL_HTTP === 'true',
  };
  if (!Number.isSafeInteger(cfg.port) || cfg.port < 0 || cfg.port > 65535 ||
      !Number.isSafeInteger(cfg.maxCalls) || cfg.maxCalls < 1 || cfg.maxCalls > 50 ||
      !Number.isSafeInteger(cfg.maxDurationMs) || cfg.maxDurationMs < 30000 || cfg.maxDurationMs > 1800000)
    throw new Error('Invalid bridge limits');
  const origin = new URL(cfg.apiOrigin);
  if (origin.username || origin.password || origin.pathname !== '/' || origin.search || origin.hash)
    throw new Error('MAPA_API_ORIGIN must be an origin without credentials or path');
  const privateHost = ['localhost', '127.0.0.1', '[::1]', 'application'].includes(origin.hostname);
  if (origin.protocol !== 'https:' && !(origin.protocol === 'http:' && cfg.allowInternal && privateHost))
    throw new Error('API requires HTTPS or explicitly permitted local Docker HTTP');
  cfg.apiOrigin = origin.origin;
  cfg.missing = [];
  for (const [name, value] of [
    ['EXOTEL_ACCOUNT_SID', cfg.account], ['STREAM_USER', cfg.username],
    ['STREAM_PASSWORD', cfg.password.length >= 32],
    ['MAPA_VOICE_BRIDGE_SECRET', cfg.secret.length >= 32],
    ['SARVAM_API_KEY', cfg.sarvamKey],
    ['MAPA_VOICE_HUMAN_FALLBACK_CONFIGURED', cfg.fallbackConfirmed],
  ]) if (!value) cfg.missing.push(name);
  if (cfg.username.includes(':') || /[\r\n]/.test(cfg.username + cfg.password + cfg.secret + cfg.sarvamKey))
    throw new Error('Invalid credential encoding');
  let validWss = false;
  try {
    const u = new URL(cfg.publicWss);
    validWss = u.protocol === 'wss:' && !u.username && !u.password && !u.hash &&
      !u.search && u.pathname === '/media' && !['localhost', '127.0.0.1', '[::1]'].includes(u.hostname);
  } catch { /* missing URL is a readiness gate, never an invented endpoint */ }
  if (!validWss) cfg.missing.push('MAPA_VOICE_PUBLIC_WSS');
  cfg.ready = cfg.live && cfg.missing.length === 0;
  return cfg;
}

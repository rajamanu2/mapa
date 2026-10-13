import test from 'node:test';
import assert from 'node:assert/strict';
import { configurationReport, probeLocal, run } from '../commissioning.mjs';

const native = () => ({ MAPA_VOICE_ENABLED: 'true', MAPA_VOICE_LIVE_ENABLED: 'true',
  MAPA_EXOTEL_ACCOUNT_SID: 'ACCOUNT_PRIVATE', MAPA_VOICE_STREAM_USER: 'PRIVATE_USER',
  MAPA_VOICE_STREAM_PASSWORD: 'p'.repeat(40), MAPA_VOICE_BRIDGE_SECRET: 's'.repeat(40),
  SARVAM_API_KEY: 'PRIVATE_SPEECH_KEY', MAPA_VOICE_PUBLIC_WSS: 'wss://voice.private.example/media',
  MAPA_VOICE_HUMAN_FALLBACK_CONFIGURED: 'true' });

test('commissioning never treats complete settings as proof of live provider calls', () => {
  const report = configurationReport(native(), true);
  assert.equal(report.configuration_complete, true);
  assert.equal(report.status, 'awaiting_external_verification');
  assert.equal(report.ready_for_supervised_call, false);
  assert.equal(report.live_call_verified, false);
  assert.ok(report.external_verification_required.includes('staffed_connect_applet_and_failure_route_verified'));
  const output = JSON.stringify(report);
  for (const value of ['ACCOUNT_PRIVATE', 'PRIVATE_USER', 'p'.repeat(40), 's'.repeat(40), 'PRIVATE_SPEECH_KEY', 'voice.private.example']) assert.ok(!output.includes(value));
});

test('missing flags, WSS and account remain blocked without creating defaults', () => {
  const report = configurationReport({ MAPA_API_ORIGIN: 'https://native.example' }, true);
  assert.equal(report.configuration_complete, false);
  for (const field of ['EXOTEL_ACCOUNT_SID', 'MAPA_VOICE_PUBLIC_WSS', 'MAPA_VOICE_HUMAN_FALLBACK_CONFIGURED', 'MAPA_VOICE_LIVE_ENABLED', 'MAPA_VOICE_ENABLED']) assert.ok(report.blockers.includes(field));
});

test('Samvaad key and temporary ingress do not masquerade as commissioned speech hosting', () => {
  const report = configurationReport({ ...native(), SARVAM_API_KEY: 'sk_samvaad_private', MAPA_VOICE_PUBLIC_WSS: 'wss://temporary.trycloudflare.com/media' }, true);
  assert.equal(report.temporary_ingress, true);
  assert.equal(report.configuration_complete, false);
  assert.ok(report.blockers.includes('sarvam_model_api_credential_required'));
});

test('local probes only GET two loopback routes, reject redirects and redact unexpected fields', async () => {
  const requests = [];
  const result = await probeLocal(8789, async (url, options) => {
    requests.push(url); assert.equal(options.method, 'GET'); assert.equal(options.redirect, 'error');
    assert.equal(options.headers.Authorization, undefined);
    return new Response(JSON.stringify({ status: 'ready', ready: true, queued: 0, blocked: 0, secret: 'DO_NOT_PRINT' }));
  });
  assert.deepEqual(requests, ['http://127.0.0.1:8789/health', 'http://127.0.0.1:8789/ready']);
  assert.equal(result['/ready'].ready, true); assert.ok(!JSON.stringify(result).includes('DO_NOT_PRINT'));
});

test('a stopped bridge, oversized reply and HTML cannot pass local readiness', async () => {
  const stopped = await probeLocal(8789, async () => { throw new Error('network contains private URL'); });
  assert.equal(stopped['/ready'].ready, false); assert.ok(!JSON.stringify(stopped).includes('private'));
  for (const response of ['<html>not bridge</html>', 'x'.repeat(16385), JSON.stringify({ ok: true, ready: true })]) {
    const result = await probeLocal(8789, async () => new Response(response));
    assert.equal(result['/ready'].ready, false);
  }
});

test('default audit is offline and rejects extra paths or unsafe options', async () => {
  let network = 0;
  const dependencies = { read: () => Object.entries(native()).map(([key, value]) => `${key}=${value}`).join('\n'), fetcher: async () => { network++; throw new Error('not expected'); } };
  const report = await run(['--native-env', 'private.env'], dependencies);
  assert.equal(network, 0); assert.equal(report.provider_requests, 0); assert.equal(report.operational_writes, 0);
  for (const args of [[], ['--native-env'], ['--native-env', 'private.env', '--enable'], ['--native-env', 'private.env', '--env-file', 'other.env']]) await assert.rejects(() => run(args, dependencies));
});

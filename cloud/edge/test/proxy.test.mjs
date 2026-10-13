import test from 'node:test';
import assert from 'node:assert/strict';
import { proxy } from '../src/worker.mjs';

const ORIGIN = 'https://mapa.rajamanu2.workers.dev';
const env = fetch => ({ PUBLIC_ORIGIN: ORIGIN, MAPA_ORIGIN: { fetch } });

test('native login preserves streamed body/auth/cookies; replaces spoofed proxy identity', async () => {
  let received;
  const input = new Request(`${ORIGIN}/api/int/v1/auth/login?locale=te`, {
    method: 'POST', body: '{"demo":true}',
    headers: {
      authorization: 'Bearer synthetic-test-token', cookie: 'demo=test',
      'content-type': 'application/json', 'x-forwarded-host': 'attacker.invalid',
      'x-forwarded-proto': 'http', 'x-forwarded-for': '192.0.2.200',
      forwarded: 'host=attacker.invalid', 'cf-connecting-ip': '192.0.2.9',
    },
  });
  const output = await proxy(input, env(async request => {
    received = request;
    assert.equal(await request.text(), '{"demo":true}');
    return new Response('{"error":"unauthorized"}', { status: 401, headers: { 'content-type': 'application/json', 'set-cookie': 'demo=valid; Secure; HttpOnly', 'cache-control': 'public' } });
  }));
  assert.equal(received.url, 'http://mapa.rajamanu2.workers.dev/api/int/v1/auth/login?locale=te');
  assert.equal(received.headers.get('authorization'), 'Bearer synthetic-test-token');
  assert.equal(received.headers.get('cookie'), 'demo=test');
  assert.equal(received.headers.get('host'), 'mapa.rajamanu2.workers.dev');
  assert.equal(received.headers.get('x-forwarded-for'), '192.0.2.9');
  assert.equal(received.headers.get('cf-connecting-ip'), '192.0.2.9');
  assert.equal(received.headers.get('x-forwarded-proto'), 'https');
  assert.equal(received.headers.get('x-forwarded-host'), 'mapa.rajamanu2.workers.dev');
  assert.equal(received.headers.has('forwarded'), false);
  assert.equal(received.redirect, 'manual');
  assert.equal(output.status, 401);
  assert.equal(output.headers.get('set-cookie'), 'demo=valid; Secure; HttpOnly');
  assert.equal(output.headers.get('cache-control'), 'no-store');
  assert.equal(await output.text(), '{"error":"unauthorized"}');
});

test('invalid client-IP metadata is not forwarded to the gateway', async () => {
  for (const value of ['192.0.2.9, 192.0.2.10', 'not-an-address', '999.999.999.999', 'beef', '1:2']) {
    await proxy(new Request(ORIGIN, { headers: { 'cf-connecting-ip': value, 'x-forwarded-for': '192.0.2.200' } }), env(request => {
      assert.equal(request.headers.has('cf-connecting-ip'), false);
      assert.equal(request.headers.has('x-forwarded-for'), false);
      return new Response('ok');
    }));
  }
});

test('bridge, media, diagnostic and encoded aliases never reach private gateway', async () => {
  for (const path of [
    '/mapa/voice/v1/bridge/events', '/api/mapa/voice/v1/bridge/events',
    '/api/m%61pa/voice/v1/bridge/events', '/api/mapa/voice/v1/bridge/events/',
    '/api/%2fmapa/voice/v1/bridge/events', '/api/%256dapa/voice/v1/bridge/events',
    '/api//mapa/voice/v1/bridge/events', '/mapa/voice/v1/bridge;events',
    '/media', '/media/anything', '/api/media', '/health', '/api/ready',
    '/.env', '/assets/.git/config', '/assets/%zz', '/assets/%0a',
    '/mapa/voice/v1/bridge%3bevents',
  ]) {
    const response = await proxy(new Request(ORIGIN + path), env(() => assert.fail(`forwarded ${path}`)));
    assert.equal(response.status, 404, path);
  }
});

test('native console, API, mobile, operator intake and files retain exact paths', async () => {
  for (const path of ['/fleet-ops/connectivity/telematics', '/api/int/v1/auth/login', '/mapa/mobile/v1/me', '/mapa/voice/v1/calls', '/mapa-source.zip', '/assets/demo.js']) {
    const response = await proxy(new Request(ORIGIN + path), env(request => {
      assert.equal(new URL(request.url).pathname, path);
      return new Response('native response');
    }));
    assert.equal(await response.text(), 'native response');
  }
});

test('response is streamed with no body pre-read', async () => {
  let pulls = 0;
  const stream = new ReadableStream({ pull(controller) { pulls++; controller.enqueue(new TextEncoder().encode('chunk')); controller.close(); } }, { highWaterMark: 0 });
  const result = await proxy(new Request(ORIGIN), env(() => new Response(stream)));
  assert.equal(pulls, 0);
  assert.equal(await result.text(), 'chunk');
  assert.equal(pulls, 1);
});

test('fixed host rejects other domains and configuration mistakes', async () => {
  const noFetch = env(() => assert.fail('unexpected origin request'));
  assert.equal((await proxy(new Request('https://attacker.invalid/'), noFetch)).status, 421);
  assert.equal((await proxy(new Request(ORIGIN), { ...noFetch, PUBLIC_ORIGIN: 'http://unsafe.invalid' })).status, 503);
  assert.equal((await proxy(new Request(ORIGIN), { ...noFetch, PUBLIC_ORIGIN: 'https://safe.invalid/path' })).status, 503);
  const redirect = await proxy(new Request('http://mapa.rajamanu2.workers.dev/fleet-ops'), noFetch);
  assert.equal(redirect.status, 307);
  assert.equal(redirect.headers.get('location'), ORIGIN + '/fleet-ops');
});

test('private origin exceptions never disclose request or exception details', async () => {
  const result = await proxy(new Request(`${ORIGIN}/api/int/v1/me`), env(() => { throw new Error('private gateway:8080 password=never-print'); }));
  assert.equal(result.status, 502);
  assert.equal(await result.text(), 'MAPA is temporarily unavailable. Please try again.');
});

test('redirects stay manual and only public-host HTTP locations get HTTPS correction', async () => {
  for (const [location, expected] of [
    ['http://mapa.rajamanu2.workers.dev/auth/login', ORIGIN + '/auth/login'],
    ['https://example.invalid/third-party', 'https://example.invalid/third-party'],
    ['/auth/login', '/auth/login'],
  ]) {
    const result = await proxy(new Request(ORIGIN), env(request => {
      assert.equal(request.redirect, 'manual');
      return new Response(null, { status: 302, headers: { location } });
    }));
    assert.equal(result.headers.get('location'), expected);
  }
});

test('SocketCluster upgrade stays intact; foreign origins and other upgrade paths are blocked', async () => {
  // Node cannot construct a 101 Response. This checks pass-through identity only;
  // live Workers VPC + SocketCluster handshake remains a deployment acceptance gate.
  const upgraded = { status: 101, webSocket: { synthetic: true } };
  const result = await proxy(new Request(ORIGIN + '/socketcluster/', { headers: { upgrade: 'websocket', origin: ORIGIN, 'sec-websocket-protocol': 'test' } }), env(request => {
    assert.equal(request.headers.get('upgrade'), 'websocket');
    assert.equal(request.headers.get('connection'), 'Upgrade');
    assert.equal(request.headers.get('sec-websocket-protocol'), 'test');
    return upgraded;
  }));
  assert.equal(result, upgraded);
  const forbidden = await proxy(new Request(ORIGIN + '/socketcluster/', { headers: { upgrade: 'websocket', origin: 'https://attacker.invalid' } }), env(() => assert.fail('foreign origin forwarded')));
  assert.equal(forbidden.status, 403);
  const wrongPath = await proxy(new Request(ORIGIN + '/media', { headers: { upgrade: 'websocket' } }), env(() => assert.fail('voice forwarded')));
  assert.equal(wrongPath.status, 404);
});

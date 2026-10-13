import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('./relay.mjs', import.meta.url), 'utf8');
const permanent = 'https://app.itda.asrhealth.in';
const publicOrigin = 'https://mapa-asr-health.pages.dev';
async function relay(origin) {
  const generated = source.replace('__MAPA_UPSTREAM__', origin);
  return (await import('data:text/javascript;base64,' + Buffer.from(generated).toString('base64'))).default;
}
const production = await relay(permanent);
const temporary = await relay('https://reviewed-connector.trycloudflare.com');
// Node requires duplex for forwarded request streams; the edge Request does not.
const NodeRequest = globalThis.Request;
globalThis.Request = class extends NodeRequest {
  constructor(input, init) { super(input, init?.body ? {...init, duplex:'half'} : init); }
};
const navigation = {'Sec-Fetch-Mode':'navigate', 'Sec-Fetch-Dest':'document', Accept:'text/html'};

async function run(worker, path, init = {}, upstream = () => new Response('{"ok":true}', {headers:{'Content-Type':'application/json'}})) {
  const requests = [];
  const previous = globalThis.fetch;
  globalThis.fetch = async (request, options) => { requests.push({request, options}); return upstream(request); };
  try {
    return {response:await worker.fetch(new Request(publicOrigin + path, init)), requests};
  } finally { globalThis.fetch = previous; }
}

test('permanent origin redirects only known browser screens, retaining path and query', async () => {
  for (const path of ['/', '/index.html', '/auth', '/auth/forgot-password', '/fleet-ops/connectivity/telematics?view=map',
    '/iam', '/call-intake/', '/notifications', '/account/auth', '/settings/two-fa', '/admin/config/socket']) {
    const {response, requests} = await run(production, path, {headers:navigation});
    assert.equal(response.status, 302, path);
    assert.equal(response.headers.get('Location'), permanent + path);
    assert.equal(response.headers.get('Cache-Control'), 'no-store');
    assert.equal(response.headers.get('X-Robots-Tag'), 'noindex, nofollow, noarchive');
    assert.equal(requests.length, 0);
  }
});

test('legacy HTML navigation and HEAD are supported without permanent caching', async () => {
  for (const method of ['GET', 'HEAD']) {
    const {response} = await run(production, '/fleet-ops', {method, headers:{Accept:'text/html,application/xhtml+xml'}});
    assert.equal(response.status, 302);
    assert.equal(await response.text(), '');
    assert.equal(response.headers.get('Cache-Control'), 'no-store');
  }
});

test('JSON clients and explicit fetches never receive a new UI redirect', async () => {
  for (const headers of [{}, {Accept:'application/json'}, {Accept:'*/*'},
    {Accept:'text/html', 'Sec-Fetch-Mode':'cors', 'Sec-Fetch-Dest':'empty'},
    {Accept:'text/html', 'Sec-Fetch-Mode':'navigate', 'Sec-Fetch-Dest':'iframe'}]) {
    const {response, requests} = await run(production, '/fleet-ops', {headers});
    assert.equal(response.status, 200);
    assert.equal(requests.length, 1);
  }
});

test('all API, mobile, websocket, health, download and asset paths stay proxied even with navigation headers', async () => {
  for (const path of ['/mapa/mobile/v1/snapshot', '/mapa/mobile/v1/enrollment', '/api/int/v1/users/me',
    '/mapa/voice/v1/calls', '/socketcluster/', '/healthz', '/fleetbase.config.json', '/assets/app.js',
    '/downloads/MAPA-Crew.apk', '/private/config', '/fleet-ops-extra', '/auth-api', '/unknown-screen']) {
    const {response, requests} = await run(production, path, {headers:navigation});
    assert.equal(response.status, 200, path);
    assert.equal(requests[0].request.url, permanent + path);
    assert.equal(response.headers.get('Location'), null);
  }
});

test('temporary tunnel behavior stays a proxy for browser screens', async () => {
  const {response, requests} = await run(temporary, '/auth', {headers:navigation});
  assert.equal(response.status, 200);
  assert.equal(requests[0].request.url, 'https://reviewed-connector.trycloudflare.com/auth');
});

test('native POST preserves bearer, JSON body, path and query with no redirect', async () => {
  const body = JSON.stringify({action_id:'test-action', online:false});
  const {response, requests} = await run(production, '/mapa/mobile/v1/duty?mode=normal',
    {method:'POST', headers:{Authorization:'Bearer test-token', 'Content-Type':'application/json'}, body});
  assert.equal(response.status, 200);
  assert.equal(requests[0].request.url, permanent + '/mapa/mobile/v1/duty?mode=normal');
  assert.equal(requests[0].request.method, 'POST');
  assert.equal(requests[0].request.headers.get('Authorization'), 'Bearer test-token');
  assert.equal(await requests[0].request.text(), body);
  assert.equal(requests[0].request.redirect, 'manual');
  assert.equal(requests[0].options.cache, 'no-store');
});

test('UI POST and OPTIONS stay proxied rather than redirecting', async () => {
  for (const method of ['POST', 'OPTIONS']) {
    const {response, requests} = await run(production, '/auth', {method, headers:{...navigation, Origin:publicOrigin}});
    assert.equal(response.status, 200);
    assert.equal(requests[0].request.method, method);
  }
});

test('foreign-origin writes still fail before any upstream call', async () => {
  const {response, requests} = await run(production, '/mapa/mobile/v1/duty',
    {method:'POST', headers:{Origin:'https://foreign.example'}});
  assert.equal(response.status, 403);
  assert.equal(requests.length, 0);
});

test('caller-supplied forwarding and method override headers remain stripped', async () => {
  const names = ['host','forwarded','x-forwarded-host','x-forwarded-for','x-http-method-override'];
  const {requests} = await run(production, '/mapa/mobile/v1/snapshot',
    {headers:Object.fromEntries(names.map(name => [name, 'untrusted']))});
  for (const name of names) assert.equal(requests[0].request.headers.get(name), null, name);
});

test('authentication denials and cache boundary are preserved', async () => {
  for (const status of [401, 403, 429]) {
    const {response} = await run(production, '/mapa/mobile/v1/snapshot', {},
      () => new Response('{"error":"denied"}', {status, headers:{'Cache-Control':'public'}}));
    assert.equal(response.status, status);
    assert.equal(await response.text(), '{"error":"denied"}');
    assert.equal(response.headers.get('Cache-Control'), 'no-store');
  }
});

test('upgraded websocket is passed through unchanged and never UI-redirected', async () => {
  const upgraded = {status:101};
  const {response, requests} = await run(production, '/fleet-ops',
    {headers:{...navigation, Upgrade:'websocket'}}, () => upgraded);
  assert.equal(response, upgraded);
  assert.equal(requests.length, 1);
});

test('existing upstream redirect rewriting remains unchanged', async () => {
  const {response} = await run(production, '/api/int/v1/auth/example', {},
    () => new Response(null, {status:302, headers:{Location:permanent + '/auth'}}));
  assert.equal(response.status, 302);
  assert.equal(response.headers.get('Location'), publicOrigin + '/auth');
});

test('upstream failure remains bounded unavailable response', async () => {
  const {response} = await run(production, '/mapa/mobile/v1/snapshot', {}, () => {throw new Error('offline');});
  assert.equal(response.status, 503);
  assert.equal(response.headers.get('Cache-Control'), 'no-store');
  assert.equal(response.headers.get('Retry-After'), '30');
});

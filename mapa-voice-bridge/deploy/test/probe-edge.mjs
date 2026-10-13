// Runs inside the test-only internal network; stdin contains disposable test
// credentials only. It never addresses the host or any external endpoint.
import assert from 'node:assert/strict';
import http from 'node:http';
import { createHash } from 'node:crypto';
import { createRequire } from 'node:module';
const WebSocket = createRequire('/app/package.json')('ws');
let input = '';
for await (const chunk of process.stdin) input += chunk;
const { host, user, password } = JSON.parse(input), port = 8080;
assert.match(host, /^mapa-edge-test-[a-f0-9]{8}-nginx$/);
const authorization = 'Basic ' + Buffer.from(`${user}:${password}`).toString('base64');
let checks = 0;
function checked(condition, message) { assert.ok(condition, message); checks++; }
function request(path, { method = 'GET', headers = {} } = {}) {
  return new Promise((resolve, reject) => {
    const req = http.request({ host, port, path, method, headers, timeout: 3000 }, res => {
      let body = ''; res.on('data', c => body += c); res.on('end', () => resolve({ status: res.statusCode, body }));
    }); req.on('error', reject); req.on('timeout', () => req.destroy(new Error('timeout'))); req.end();
  });
}
function rejected(path, headers = {}) {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(`ws://${host}:${port}${path}`, { headers, handshakeTimeout: 3000 });
    ws.on('unexpected-response', (_req, res) => { res.resume(); resolve(res.statusCode); ws.terminate(); });
    ws.on('open', () => { ws.terminate(); reject(new Error('Unexpected acceptance')); }); ws.on('error', () => {});
  });
}
function echo(path) {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(`ws://${host}:${port}${path}`, { headers: { Authorization: authorization, Cookie: 'SHOULD_BE_STRIPPED' }, handshakeTimeout: 3000 });
    ws.on('open', () => ws.send('synthetic local probe'));
    ws.on('message', bytes => { resolve(JSON.parse(bytes)); ws.terminate(); }); ws.on('error', reject);
  });
}
for (const path of ['/', '/health', '/ready', '/mapa/voice/v1/bridge/events', '/api', '/fleet-ops', '/mapa-source.zip', '/.env',
  '/media/', '/%6dedia', '/x/../media', '/media?token=secret', '/media?sample-rate=24000', '/media?sample-rate=8000&sample-rate=16000']) {
  const result = await request(path, { headers: { Authorization: authorization } });
  checked(result.status === 404 && !result.body.includes('PRIVATE-UPSTREAM-FIXTURE'), `path blocked ${path}`);
}
checked((await request('/media')).status === 426, 'non-WebSocket rejected');
checked((await request('/media', { method: 'POST' })).status === 405, 'POST rejected');
checked(await rejected('/media') === 401, 'missing Basic rejected');
checked(await rejected('/media', { Authorization: 'Basic ' + Buffer.from(`${user}:wrong`).toString('base64') }) === 401, 'wrong Basic rejected');
checked(await rejected('/media', { Authorization: authorization, Origin: 'https://browser.example' }) === 403, 'browser origin rejected');
for (const path of ['/media', '/media?sample-rate=8000', '/media?sample-rate=16000']) {
  const data = await echo(path);
  checked(data.uri === path, 'exact path/query preserved');
  checked(data.auth_digest === createHash('sha256').update(authorization).digest('hex'), 'Basic preserved for existing bridge check');
  checked(data.cookie === null && data.origin === null && data.host === 'voice-bridge', 'unneeded headers stripped');
}
console.log(JSON.stringify({ checks, result: 'passed' }));

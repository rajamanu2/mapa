// Only local containers on a newly-created internal Docker network. It never
// starts cloudflared, joins the MAPA network or contacts an external service.
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { randomBytes, randomUUID } from 'node:crypto';
import { mkdirSync, writeFileSync, rmSync, readFileSync } from 'node:fs';
import { resolve, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { prepare, readStreamCredentials } from '../prepare-auth.mjs';

const deploy = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const docker = process.env.MAPA_DOCKER_EXE || 'docker';
const image = process.env.MAPA_VOICE_EDGE_TEST_IMAGE || 'asr-fleetbase-pilot-console:latest';
const suffix = randomUUID().slice(0, 8), prefix = `mapa-edge-test-${suffix}`;
const network = prefix, nginx = `${prefix}-nginx`, upstream = `${prefix}-mock`;
const artifactsRoot = join(deploy, 'test-artifacts'), artifacts = join(artifactsRoot, suffix);
let checks = 0;
function checked(condition, message) { assert.ok(condition, message); checks++; }
function run(args, extra = {}) {
  const result = spawnSync(docker, args, { encoding: 'utf8', timeout: 30000, maxBuffer: 1024 * 1024, windowsHide: true, ...extra });
  if (result.status !== 0 || result.error) throw new Error(`Local Docker validation failed: ${args[0]} (${result.status ?? 'unavailable'})`);
  return result.stdout.trim();
}
mkdirSync(artifacts, { recursive: true, mode: 0o700 });
const privateEnv = join(artifacts, 'test-input.env'), authFile = join(artifacts, 'test.htpasswd');
const user = 'DEMO_MAPA_EDGE_TEST', password = randomBytes(32).toString('hex');
const authorization = 'Basic ' + Buffer.from(`${user}:${password}`).toString('base64');
writeFileSync(privateEnv, `MAPA_VOICE_STREAM_USER=${user}\nMAPA_VOICE_STREAM_PASSWORD=${password}\n`, { mode: 0o600 });
try {
  checked(readStreamCredentials(readFileSync(privateEnv, 'utf8')).user === user, 'credential parser');
  assert.throws(() => readStreamCredentials('MAPA_VOICE_STREAM_USER=a\nMAPA_VOICE_STREAM_PASSWORD=short')); checks++;
  prepare(['--env-file', privateEnv, '--output', authFile, '--docker', docker, '--image', image]);
  checked(!readFileSync(authFile, 'utf8').includes(password), 'password must not enter htpasswd in plaintext');

  const config = JSON.parse(run(['compose', '-f', join(deploy, 'compose.demo.yml'), '--profile', 'public-demo', 'config', '--format', 'json'],
    { env: { ...process.env, MAPA_VOICE_HTPASSWD_FILE: authFile } }));
  checked(config.name === 'mapa-voice-demo-edge', 'independent project');
  checked(config.services['voice-edge'].ports[0].host_ip === '127.0.0.1', 'only loopback host port');
  checked(config.services['quick-tunnel'].restart === 'no', 'tunnel never auto restarts');
  checked(!('mapa_native' in config.services['quick-tunnel'].networks), 'connector cannot join native network');
  checked(config.services['quick-tunnel'].command.includes('http://voice-edge:8080'), 'tunnel reaches filtered edge only');
  checked(config.services['voice-edge'].profiles.includes('local-edge') && config.services['quick-tunnel'].profiles.length === 1,
    'public tunnel requires separate profile');
  checked(config.services['voice-edge'].volumes.every(v => v.read_only), 'config and auth mounted read only');

  run(['network', 'create', '--internal', '--label', 'mapa.test=voice-edge', network]);
  run(['run', '-d', '--name', upstream, '--pull=never', '--network', network, '--network-alias', 'voice-bridge', '--label', 'mapa.test=voice-edge',
    '--read-only', '--cap-drop=ALL', '--security-opt', 'no-new-privileges:true', '--mount', `type=bind,source=${join(deploy, 'test', 'mock-upstream.mjs')},target=/tmp/mock-upstream.mjs,readonly`,
    '--entrypoint', 'node', 'mapa-voice-bridge:pilot', '/tmp/mock-upstream.mjs']);
  const base = ['--pull=never', '--network', network, '--user', '101:101', '--read-only', '--cap-drop=ALL', '--security-opt', 'no-new-privileges:true',
    '--tmpfs', '/tmp:rw,noexec,nosuid,size=8m,mode=1777',
    '--mount', `type=bind,source=${join(deploy, 'nginx.conf')},target=/etc/nginx/mapa-edge.conf,readonly`,
    '--mount', `type=bind,source=${authFile},target=/run/mapa-auth/voice.htpasswd,readonly`, '--entrypoint', 'nginx'];
  run(['run', '--rm', ...base, image, '-t', '-c', '/etc/nginx/mapa-edge.conf']); checks++;
  run(['run', '-d', '--name', nginx, '--label', 'mapa.test=voice-edge', ...base, image,
    '-c', '/etc/nginx/mapa-edge.conf', '-g', 'daemon off;']);
  const probe = JSON.parse(run(['run', '--rm', '-i', '--pull=never', '--network', network, '--read-only', '--cap-drop=ALL',
    '--security-opt', 'no-new-privileges:true', '--mount', `type=bind,source=${join(deploy, 'test', 'probe-edge.mjs')},target=/tmp/probe-edge.mjs,readonly`,
    '--entrypoint', 'node', 'mapa-voice-bridge:pilot', '/tmp/probe-edge.mjs'], { input: JSON.stringify({ host: nginx, user, password }) }));
  checked(probe.result === 'passed', 'isolated internal-network probes passed'); checks += probe.checks;
  const logs = run(['logs', nginx]);
  checked(!logs.includes(password) && !logs.includes(authorization) && !logs.includes('/media'), 'no access URL or credential logging');
  console.log(JSON.stringify({ result: 'passed', checks, public_tunnels_started: 0, provider_requests: 0, native_services_changed: 0,
    nginx_image: image, cloudflared_started: false }, null, 2));
} catch (error) {
  const diagnostic = spawnSync(docker, ['inspect', '--format', '{{json .State}} {{json .NetworkSettings.Ports}}', nginx],
    { encoding: 'utf8', windowsHide: true, timeout: 10000 });
  if (diagnostic.status === 0) console.error(diagnostic.stdout.trim());
  throw error;
} finally {
  // Generated names are fixed to this test's UUID; no existing MAPA container
  // or network name is used by cleanup.
  for (const name of [nginx, upstream]) {
    assert.ok(name.startsWith(prefix + '-'));
    spawnSync(docker, ['rm', '-f', name], { stdio: 'ignore', windowsHide: true, timeout: 15000 });
  }
  spawnSync(docker, ['network', 'rm', network], { stdio: 'ignore', windowsHide: true, timeout: 15000 });
  checked(resolve(artifacts).startsWith(resolve(artifactsRoot) + (process.platform === 'win32' ? '\\' : '/')), 'cleanup stays in test artifacts');
  rmSync(artifacts, { recursive: true, force: true });
}

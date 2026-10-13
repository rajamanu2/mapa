// Read tunnel startup logs locally and print only the credential-free endpoint.
import { spawnSync } from 'node:child_process';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
const docker = process.env.MAPA_DOCKER_EXE || 'docker';
const compose = resolve(dirname(fileURLToPath(import.meta.url)), 'compose.demo.yml');
const result = spawnSync(docker, ['compose', '-f', compose, '--profile', 'public-demo', 'logs', '--no-color', '--tail', '100', 'quick-tunnel'],
  { encoding: 'utf8', windowsHide: true, timeout: 15000, maxBuffer: 262144 });
if (result.status !== 0) {
  console.error('Could not read the optional tunnel logs. No tunnel was started.'); process.exitCode = 1;
} else {
  const found = [...(result.stdout + '\n' + result.stderr).matchAll(/https:\/\/([a-z0-9-]+\.trycloudflare\.com)(?=[\s/|]|$)/gi)];
  if (!found.length) { console.error('No temporary hostname was found. The tunnel may be stopped or still connecting.'); process.exitCode = 1; }
  else console.log(`wss://${found.at(-1)[1].toLowerCase()}/media`);
}

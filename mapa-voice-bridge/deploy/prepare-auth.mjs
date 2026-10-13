// Read existing private stream credentials, hash through stdin in a network-
// disabled local container, and write only the salted hash to a private file.
// No credential is put in Docker argv, environment, image layers or stdout.
import { readFileSync, mkdirSync, writeFileSync, statSync } from 'node:fs';
import { dirname, resolve, isAbsolute } from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

export function parseArgs(args) {
  const values = {};
  for (let i = 0; i < args.length; i += 2) {
    if (!['--env-file', '--output', '--docker', '--image'].includes(args[i]) || !args[i + 1]) throw new Error('Invalid arguments');
    values[args[i]] = args[i + 1];
  }
  if (!values['--env-file'] || !values['--output']) throw new Error('Both --env-file and --output are required');
  return values;
}
export function readStreamCredentials(text) {
  const selected = {};
  for (const line of text.split(/\r?\n/)) {
    const match = line.match(/^(MAPA_VOICE_STREAM_USER|MAPA_VOICE_STREAM_PASSWORD)=(.*)$/);
    if (!match) continue;
    if (selected[match[1]] !== undefined) throw new Error('Duplicate stream setting');
    let value = match[2].trim();
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) value = value.slice(1, -1);
    selected[match[1]] = value;
  }
  const user = selected.MAPA_VOICE_STREAM_USER, password = selected.MAPA_VOICE_STREAM_PASSWORD;
  if (!user || !/^[A-Za-z0-9_.-]{1,80}$/.test(user)) throw new Error('Invalid or missing stream username');
  if (!password || password.length < 32 || password.length > 256 || /[\r\n\0]/.test(password)) throw new Error('Invalid or missing stream password');
  return { user, password };
}
export function prepare(args) {
  const options = parseArgs(args);
  const source = resolve(options['--env-file']), output = resolve(options['--output']);
  if (!isAbsolute(options['--output']) || source === output || !output.endsWith('.htpasswd')) throw new Error('Use a separate absolute private .htpasswd output path');
  const { user, password } = readStreamCredentials(readFileSync(source, 'utf8'));
  const docker = options['--docker'] || 'docker';
  const image = options['--image'] || 'nginx:1.31.6-alpine';
  const run = spawnSync(docker, ['run', '--rm', '-i', '--pull=never', '--network', 'none', '--entrypoint', 'mkpasswd', image, '-m', 'sha512', '-P', '0'],
    { input: password + '\n', encoding: 'utf8', windowsHide: true, timeout: 90000, maxBuffer: 4096 });
  if (run.error || run.status !== 0) {
    // Report only process metadata. stdout/stderr can contain sensitive input
    // from an unexpected tool failure and are deliberately never echoed.
    const code = /^[A-Z0-9_]+$/.test(run.error?.code || '') ? run.error.code : 'none';
    const signal = /^SIG[A-Z0-9]+$/.test(run.signal || '') ? run.signal : 'none';
    throw new Error(`Local password hashing failed (code=${code}, status=${Number.isInteger(run.status) ? run.status : 'none'}, signal=${signal}); inspect Docker availability`);
  }
  const hash = run.stdout.trim();
  if (!/^\$6\$[./A-Za-z0-9]{1,16}\$[./A-Za-z0-9]{86}$/.test(hash)) throw new Error('Unexpected password hash format');
  mkdirSync(dirname(output), { recursive: true, mode: 0o700 });
  // The private parent directory prevents host-user traversal. Read permission
  // lets the unprivileged nginx user read its single read-only file bind mount.
  writeFileSync(output, `${user}:${hash}\n`, { encoding: 'utf8', mode: 0o644 });
  if (statSync(output).size > 512) throw new Error('Unexpected auth file size');
  return output;
}
if (process.argv[1] && fileURLToPath(import.meta.url) === resolve(process.argv[1])) {
  try { console.log(`Private MAPA ingress authentication file prepared: ${prepare(process.argv.slice(2))}`); }
  catch (error) { console.error(error.message); process.exitCode = 1; }
}

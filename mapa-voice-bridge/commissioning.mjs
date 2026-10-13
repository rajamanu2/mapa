// Read-only commissioning report. Never opens a media socket, sends audio,
// enables intake, starts a service, or prints environment values.
import { readFileSync } from 'node:fs';
import { parseEnv } from 'node:util';
import { pathToFileURL } from 'node:url';
import { readConfig } from './config.mjs';

const externalChecks = [
  'provider_account_approved_and_incoming_number_assigned',
  'agentstream_voicebot_enabled_for_that_number',
  'staffed_connect_applet_and_failure_route_verified',
  'english_and_telugu_real_speech_verified',
  'operator_review_and_real_crew_assignment_verified',
  'stable_wss_host_and_always_on_server_verified',
];

export function bridgeEnvironment(env, native = false) {
  if (!native) return { ...env };
  return { ...env,
    EXOTEL_ACCOUNT_SID: env.MAPA_EXOTEL_ACCOUNT_SID || '',
    STREAM_USER: env.MAPA_VOICE_STREAM_USER || '',
    STREAM_PASSWORD: env.MAPA_VOICE_STREAM_PASSWORD || '',
    MAPA_API_ORIGIN: env.MAPA_API_ORIGIN || 'http://application:8000',
    MAPA_ALLOW_INTERNAL_HTTP: env.MAPA_ALLOW_INTERNAL_HTTP || 'true',
  };
}

export function configurationReport(env, native = false) {
  const mapped = bridgeEnvironment(env, native);
  let cfg;
  try { cfg = readConfig(mapped); }
  catch { return { status: 'blocked', configuration_valid: false,
    blockers: ['invalid_bridge_configuration'], ready_for_supervised_call: false,
    live_call_verified: false, external_verification_required: externalChecks }; }
  const blockers = [...cfg.missing];
  if (!cfg.live) blockers.push('MAPA_VOICE_LIVE_ENABLED');
  if (native && env.MAPA_VOICE_ENABLED !== 'true') blockers.push('MAPA_VOICE_ENABLED');
  // These are different products. A Samvaad agent key cannot establish that
  // the speech Model API used by providers.mjs is provisioned.
  if (mapped.SARVAM_API_KEY?.startsWith('sk_samvaad_')) blockers.push('sarvam_model_api_credential_required');
  if (!/^[A-Za-z0-9_-]{1,128}$/.test(cfg.account)) blockers.push('invalid_exotel_account_format');
  let temporaryIngress = false;
  try { temporaryIngress = new URL(cfg.publicWss).hostname.endsWith('.trycloudflare.com'); } catch { /* already reported */ }
  return { status: blockers.length ? 'blocked' : 'awaiting_external_verification',
    configuration_valid: true, configuration_complete: blockers.length === 0,
    blockers: [...new Set(blockers)], bridge_live_flag: cfg.live,
    native_training_calls_flag: native ? env.MAPA_VOICE_DEMO_ENABLED === 'true' : null,
    temporary_ingress: temporaryIngress,
    configured_max_simultaneous_calls: cfg.maxCalls,
    ready_for_supervised_call: false, live_call_verified: false,
    external_verification_required: externalChecks };
}

export async function probeLocal(port = 8789, fetcher = fetch) {
  if (!Number.isInteger(port) || port < 1 || port > 65535) throw new Error('invalid_port');
  const results = {};
  for (const path of ['/health', '/ready']) {
    try {
      const response = await fetcher(`http://127.0.0.1:${port}${path}`, {
        method: 'GET', redirect: 'error', signal: AbortSignal.timeout(3000),
        headers: { Accept: 'application/json' },
      });
      // Bound the response and whitelist output; an unexpected upstream body
      // must not turn this command into a transcript or credential dump.
      let size = 0; const parts = [];
      const reader = response.body.getReader();
      try {
        for (;;) {
          const { done, value } = await reader.read(); if (done) break;
          size += value.length; if (size > 16384) throw new Error('response_too_large');
          parts.push(value);
        }
      } finally { await reader.cancel(); }
      const body = JSON.parse(Buffer.concat(parts).toString('utf8'));
      const recognized = ['ready', 'disabled', 'attention'].includes(body.status) && typeof body.ready === 'boolean';
      results[path] = { reachable: true, http_status: response.status, recognized_bridge_response: recognized,
        ready: recognized && body.ready === true && response.status === 200,
        status: recognized ? body.status : 'unrecognized',
        queued: Number.isSafeInteger(body.queued) && body.queued >= 0 ? body.queued : null,
        blocked: Number.isSafeInteger(body.blocked) && body.blocked >= 0 ? body.blocked : null,
      };
    } catch { results[path] = { reachable: false, ready: false }; }
  }
  return results;
}

export async function run(args, { read = readFileSync, fetcher = fetch } = {}) {
  let envPath, native = false, probe = false;
  for (let i = 0; i < args.length; i++) {
    if ((args[i] === '--native-env' || args[i] === '--env-file') && !envPath && args[i + 1] && !args[i + 1].startsWith('--')) {
      native = args[i] === '--native-env'; envPath = args[++i];
    } else if (args[i] === '--probe-local') probe = true;
    else throw new Error('usage');
  }
  if (!envPath) throw new Error('usage');
  const env = parseEnv(read(envPath, 'utf8'));
  const report = configurationReport(env, native);
  if (probe) report.local_bridge = await probeLocal(native ? 8789 : Number(env.PORT || 8789), fetcher);
  // All provider/fallback/physical acceptance remains pending even if a
  // running bridge declares its own component /ready endpoint healthy.
  return { checked_at: new Date().toISOString(), ...report, provider_requests: 0, calls_placed: 0,
    configuration_changes: 0, operational_writes: 0 };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    const report = await run(process.argv.slice(2));
    console.log(JSON.stringify(report, null, 2));
    process.exitCode = report.configuration_complete ? 0 : 2;
  } catch {
    console.error('Could not read valid configuration. Usage: node commissioning.mjs --native-env <private native .env> [--probe-local] OR --env-file <private bridge .env> [--probe-local]. No configuration values were printed.');
    process.exitCode = 1;
  }
}

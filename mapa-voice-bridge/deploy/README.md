# Optional MAPA voice demo ingress

This adds a protected temporary route to the existing voice bridge. It is a separate Docker Compose project, **not started by the main MAPA stack**. Both services require an explicit profile. Creating these files does not create a tunnel, hostname, DNS record, provider connection or phone call.

```text
Exotel WSS + Basic authentication
        ↓ Cloudflare temporary hostname / encrypted tunnel
cloudflared → nginx exact /media allowlist + Basic authentication
        ↓ private local Docker network
existing voice-bridge → existing native MAPA API
```

The external route accepts only a WebSocket GET to `/media`, `/media?sample-rate=8000`, or `/media?sample-rate=16000`. Every other path is rejected, including `/health`, `/ready`, API routes, the console, files, encoded path aliases, and query tokens. Browser Origin headers, wrong passwords and unsupported methods are rejected. The original Basic header reaches the bridge for a second check against its existing live-readiness gates. Nginx access logs are disabled; request URLs and Authorization headers are not written to edge logs.

The connector joins only its own edge network. Nginx alone also joins the existing native Docker network. Nginx publishes a **loopback-only** port, `127.0.0.1:8793`, for local validation. No API, console, database or bridge port is published by this project. The connector has no Docker socket, no native MAPA credentials, and no host mounts.

## Configuration contract

| Setting | Purpose |
| --- | --- |
| `MAPA_VOICE_HTPASSWD_FILE` | Required absolute path to the private hash file generated below. Never a source/public-artifact path. |
| `MAPA_VOICE_EDGE_IMAGE` | Optional nginx-compatible image. Default `nginx:1.31.6-alpine`. The local pilot's cached console image contains the same nginx version and was used for offline validation with its default entrypoint/config completely replaced. |
| `MAPA_DOCKER_NETWORK` | Existing network name; defaults to `asr-fleetbase-pilot_default`. |
| `MAPA_VOICE_EDGE_PORT` | Optional localhost validation port; defaults to `8793`. |
| `MAPA_DOCKER_EXE` | Optional full Docker executable path used by the URL reader/test scripts. |

The existing bridge must already be reachable as `voice-bridge:8789` on the native network. Its runtime configuration remains unchanged by this package. The stream username/password are read from `MAPA_VOICE_STREAM_USER` and `MAPA_VOICE_STREAM_PASSWORD` in the existing private native `.env` **only when the operator runs `prepare-auth.mjs`**. The script does not modify that file. It hashes the password with SHA-512-crypt using the local container's `mkpasswd`; plaintext travels over process stdin, never a command-line argument or Docker environment setting. The generated file contains a salted hash, and is mounted read-only for nginx. Protect its parent directory with the current user's private filesystem permissions.

Use a single trusted Docker host for these containers. Public traffic uses WSS with Cloudflare TLS; cloudflared establishes the encrypted Cloudflare tunnel. Only the final hop between local containers is HTTP. No TLS verification bypass is configured. Cloudflare is an intermediary for this traffic; this is not end-to-end encryption that hides caller audio or authorization headers from every intermediary.

## Prepare and validate locally — no public tunnel

Run these commands from the MAPA workspace root in PowerShell. They do not start cloudflared. The provided paths match this Windows pilot; change runtime executable paths if installing elsewhere.

```powershell
$docker = Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop\resources\bin\docker.exe'
$node = Join-Path $env:USERPROFILE '.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe'
$deploy = Join-Path (Get-Location).Path 'mapa-voice-bridge\deploy'
$compose = Join-Path $deploy 'compose.demo.yml'
$privateNativeEnv = Join-Path (Get-Location).Path 'fleetbase\asr\.env'
$env:MAPA_DOCKER_EXE = $docker
$env:MAPA_VOICE_EDGE_IMAGE = 'asr-fleetbase-pilot-console:latest'
$env:MAPA_VOICE_HTPASSWD_FILE = Join-Path $env:LOCALAPPDATA 'MAPA\VoiceIngress\voice.htpasswd'

& $node (Join-Path $deploy 'prepare-auth.mjs') --env-file $privateNativeEnv --output $env:MAPA_VOICE_HTPASSWD_FILE --docker $docker --image $env:MAPA_VOICE_EDGE_IMAGE
& $docker compose -f $compose --profile local-edge config --quiet
& $docker compose -f $compose --profile local-edge up -d --pull never voice-edge
```

Do not paste a password into a URL to test locally. With no WebSocket upgrade, `http://127.0.0.1:8793/media` returns 426; `/health`, `/ready`, `/`, console and API paths return 404. Authenticated upgrades still fail safely until the existing bridge is live-ready.

The cached pilot console image is used solely as a locally available nginx binary. The mounted full nginx configuration has no static file root, no console handlers, and no inclusion of the image's original configuration. For another installation, use the official nginx image after explicitly acquiring/verifying it; rerun the local tests for that image before activation. Nothing here pulls an image automatically during local validation.

Optional comprehensive local test (creates and removes uniquely named test containers and an isolated mock upstream; never runs cloudflared or joins the native MAPA network):

```powershell
$env:MAPA_VOICE_EDGE_TEST_IMAGE = 'asr-fleetbase-pilot-console:latest'
& $node (Join-Path $deploy 'test\local-edge.mjs')
```

## Explicit activation — creates a public temporary hostname

**This step is deliberately separate.** The operator/root task should run it only when ready for a supervised trial. The official `cloudflare/cloudflared:2026.9.1` image is declared but was not started by package preparation or local tests.

```powershell
& $docker compose -f $compose --profile public-demo up -d quick-tunnel
& $node (Join-Path $deploy 'show-url.mjs')
```

The second command reads local startup logs and prints only the credential-free endpoint:

```text
wss://GENERATED-NAME.trycloudflare.com/media
```

If startup is still in progress, rerun only `show-url.mjs`; it never starts a tunnel. Set this credential-free value as the existing bridge's private `MAPA_VOICE_PUBLIC_WSS`. Complete the remaining bridge gates (provider/account keys, correct account SID, staffed human fallback) and restart only the bridge through the main project. Do not change the live flag or fabricate readiness merely to bypass a missing prerequisite.

Privately configure the Exotel Voicebot applet using:

```text
wss://STREAM_USER:STREAM_PASSWORD@GENERATED-NAME.trycloudflare.com/media?sample-rate=8000
```

Exotel documents that its credential-bearing applet URL becomes an `Authorization: Basic …` WebSocket header. Enter actual values only in the restricted provider configuration. Keep credentials out of browser address bars, source files, screenshots, command-line arguments, ordinary URL query parameters and messages. The next Exotel applet must route to a staffed human dispatcher on completion/failure. This package does not attach a number, modify the provider flow, enable live intake, synthesize speech or place calls.

Before a real call, inspect the local bridge `/ready` on its original private/local port and verify the edge denies unrelated paths. For the supervised trial, use fictional details and verify the human fallback, native dispatcher review and Android crew assignment separately.

## Stop and rollback

Stop the connector first to close the public route, then remove this independent project's containers/network:

```powershell
& $docker compose -f $compose --profile public-demo stop quick-tunnel
& $docker compose -f $compose --profile public-demo down
```

This does not remove the external native network, MAPA services, MySQL volumes or voice journal. The private hash file remains for controlled reuse/rotation. Disable the existing bridge's live flag and restore the provider's intended call route after the trial if no longer commissioning. These actions are owned by the operator/root task; no script here silently rewrites the main `.env` or provider settings.

`restart: "no"` prevents both optional services from automatically returning after a host restart. To resume, explicitly start the intended profile again. A new Quick Tunnel can receive a different hostname: update the bridge setting and provider applet privately each time. Rotating the stream password also requires regenerating this hash file and restarting the edge after updating Exotel and the existing bridge.

## Demo limits and evidence

Cloudflare describes Quick Tunnels as testing/development facilities with random hostnames and no uptime SLA; the documented concurrent-request limit is 200 and SSE is unsupported. This voice edge is stricter: 5 handshakes/second with a short burst allowance and at most 12 connections. It is not a production emergency-number endpoint. [Cloudflare Quick Tunnels](https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/do-more-with-tunnels/trycloudflare/)

Cloudflare supports WebSockets, but connection restarts and idle closures can occur. The bridge's existing interrupted-stream handling and the provider's staffed fallback remain necessary. [Cloudflare WebSockets](https://developers.cloudflare.com/network/websockets/)

The configuration uses nginx's documented Basic authentication and explicit WebSocket Upgrade proxying. [nginx Basic authentication](https://nginx.org/en/docs/http/ngx_http_auth_basic_module.html) · [nginx WebSocket proxying](https://nginx.org/en/docs/http/websocket.html)

Provider authentication behavior and current connector release were checked against primary sources: [Exotel Voicebot](https://docs.exotel.com/exotel-agentstream/voicebot-applet) · [cloudflared 2026.9.1 release](https://github.com/cloudflare/cloudflared/releases/tag/2026.9.1).

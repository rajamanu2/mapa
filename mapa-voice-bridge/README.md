# MAPA incoming-call bridge

This service connects Exotel Voicebot audio to **the existing MAPA native console and Laravel API**. It does not use the stopped interface on port 8791. Telephone intake is disabled by default. A real provider call has not yet been commissioned.

## What it does

1. Exotel opens an authenticated WebSocket to `/media?sample-rate=8000` or `16000`.
2. The bridge binds the configured Exotel account, call and stream. It verifies PCM16 little-endian mono audio and detects packet gaps, replay, reordering and timestamp discontinuity.
3. Sarvam realtime speech recognition transcribes the caller. Fixed English/Telugu prompts ask for pickup location/landmark, the reported problem, and additional identifying information. Press **1** for English, **2** for Telugu, or **0** for a human operator.
4. Final transcripts and untrusted answer drafts enter a private SQLite retry journal and are delivered to `/mapa/voice/v1/bridge/events` using a dedicated server credential. The native console handles extraction, dispatcher review and assignment. This bridge never creates or dispatches an ambulance order.
5. Completion, provider failure, an audio gap or an operator request closes the Voicebot socket. **Exotel must have a human Connect applet configured next.** The bridge cannot verify that phone routing and does not claim that closing a socket has connected a human.

No diagnosis, medical advice, treatment, or ambulance arrival promise is generated. Speech prompts are fixed first-party text. Telugu wording and voice quality still need review with Telugu-speaking dispatchers in a supervised real-call trial.

## Run locally

Node 24 or newer is required (`node:sqlite` is used). Install the pinned dependency with `npm ci --ignore-scripts`, then copy `.env.example` to private `.env` and adjust the native API origin. For a Windows localhost API use `http://127.0.0.1:8000` and `MAPA_ALLOW_INTERNAL_HTTP=true`. For the existing Docker network use `http://application:8000`.

```text
npm test
npm run demo
npm start
```

The demo uses real local WebSocket framing with synthetic PCM, a fake ASR provider, and an in-memory mock API. It places **zero calls**, changes **zero native records**, and makes **zero provider requests**. It is a protocol rehearsal, not evidence that real speech recognition works. Tests also exercise durable restart/retry behavior, exact event replay, call isolation, auth, failure handling, PCM rates and provider request formats.

`GET /health` is process/journal health; it returns HTTP 200 with `status: disabled` while safely awaiting setup. `GET /ready` returns 503 until all live gates are present. Neither endpoint exposes caller data, transcript contents, keys or URLs. There is no public transcript-injection or call-placement route.

Run `node commissioning.mjs --native-env ../fleetbase/asr/.env --probe-local` from this directory for a credential-free, read-only configuration report. It never enables intake or contacts a provider. A passing configuration check is not proof of a working hotline. Follow the [live commissioning procedure](deploy/LIVE-COMMISSIONING.md) for provider-number, staffed fallback, real speech and crew acceptance.

## Exotel setup

Create/enable the Exotel account and AgentStream access, complete its onboarding/KYC where required, and configure a provider telephone/trial number. No account, number, purchase, outbound call or provider configuration is made by this code.

In the Exotel call flow:

1. Use a greeting/notice approved for the intended pilot and language.
2. Add the **Voicebot** applet with this static URL pattern (replace placeholders privately):

   `wss://STREAM_USER:STREAM_PASSWORD@voice.your-domain.example/media?sample-rate=8000`

   Exotel documents that credentials in this applet configuration are transmitted to the WebSocket service as the HTTP `Authorization: Basic …` header. Use credentials generated only for this bridge, **not** an Exotel account API token. Percent-encode the username/password if needed, or generate URL-safe random secrets. Do not put credentials in ordinary query parameters, tickets, browser history, screenshots, access logs or client configuration. The bridge does not log request URLs or authorization headers. Restrict Exotel dashboard access because its stored applet configuration contains the stream credential.

3. Configure the **next applet as Connect to the staffed dispatcher line**, and test both successful completion and forced bridge/provider failure. Pressing 0 must reach that same staffed line. Configure the provider's timeout/failure routing as well; a failed WebSocket upgrade must not leave a caller disconnected without a fallback route.
4. Keep recording disabled for the initial test unless its purpose, notice, access and retention have been explicitly approved. This bridge does not download or retain call recordings.
5. Publish the WebSocket service behind a valid TLS certificate and a reverse proxy supporting WebSocket Upgrade. Bind the container to local/internal networking; never expose its plain HTTP listener directly to the Internet. Disable URL/Authorization logging at the proxy. Forward `/media` unchanged and set an adequate read timeout. Exotel IP allowlisting may be added using current IPs from Exotel support.

Set private values `EXOTEL_ACCOUNT_SID`, `STREAM_USER`, `STREAM_PASSWORD` (at least 32 characters), `SARVAM_API_KEY`, and `MAPA_VOICE_BRIDGE_SECRET` (at least 32 characters; same value on the native API). `MAPA_VOICE_PUBLIC_WSS` is the credential-free public endpoint, such as `wss://voice.your-domain.example/media`. Set `MAPA_VOICE_HUMAN_FALLBACK_CONFIGURED=true` only after the applet route exists. Finally set `MAPA_VOICE_LIVE_ENABLED=true` and verify `/ready`.

An ordinary mobile can be the **test caller** to the configured Exotel number. An ordinary SIM alone does not send call audio or webhooks to this software. Existing business numbers require a provider-supported connection/forwarding arrangement.

## Reliability and security

- Basic authentication is checked before WebSocket upgrade; a browser Origin is rejected. Account, call and stream are immutable for a connection. Conflicting snake_case/camelCase identity fields are rejected.
- Support is intentionally limited to 8 kHz and 16 kHz because the configured Sarvam realtime endpoint supports those rates. 24 kHz requests fail explicitly. Raw audio stays in bounded RAM buffers; it is not journaled. Compressed codecs are not silently interpreted as PCM.
- The delivery contract is **at least once with idempotent native API receipts**, not an impossible transport-level exactly-once promise. UUID, timestamp, sequence and serialized body remain unchanged on retry. A lost response replays the same event. The native API returns HTTP 200 with `ok: true` for a verified replay. HTTP 409 is quarantined and never treated as success. A blocked event also blocks later events for that call; unrelated calls can continue.
- Per-call sequence allocation and event insertion are transactional. The SQLite WAL uses FULL synchronization. Stream IDs cannot be reused; one active stream per call prevents hijacking. A process restart appends `stream.failed / process_restarted` for interrupted audio sockets and preserves pending transcripts. Audio itself cannot resume after a process failure.
- The journal is bound to one API origin and Exotel account. A nonempty journal cannot be redirected into another account. Credential rotation does not change this binding.
- Defaults: 10 concurrent calls, 10 minutes per call, 30-second audio inactivity, 10-second start/ASR/TTS timeouts, 5-second final transcript drain, 200 final utterances, 160 KB ASR buffer, 10,000 pending events / roughly 32 MiB payload, and 100,000 remembered stream IDs. Capacity faults stop intake and surface health attention instead of dropping events.
- Journal rows contain caller details/transcripts. Keep `/data` on a private persistent volume with server-only access and encrypted disk/backup protection. SQLite is **not encrypted by this application**. Acknowledged event bodies are deleted; active/ended stream bindings remain until an operator-approved retention/maintenance operation. The stream cap prevents unbounded growth. Do not erase a journal with pending or quarantined events.
- Never copy `.env`, SQLite/WAL files, provider keys or bridge credentials into the Android APK, public ZIP, source download or browser. There are no keys in `.env.example`.

If `/health` shows `attention`, inspect the native API status, configuration and private journal locally. A 400/403/404/409/422 event is quarantined for operator investigation; no automatic discarding or replay-under-a-new-ID is performed. Back up the private journal before maintenance. Rotate a compromised stream credential in Exotel and the bridge; rotate the separate bridge/API credential on both servers.

## Native API event contract

`POST /mapa/voice/v1/bridge/events`, header `Authorization: Bearer MAPA_VOICE_BRIDGE_SECRET`, JSON:

```json
{
  "event_id": "a generated immutable UUID",
  "event_type": "transcript.final",
  "account_sid": "configured-account",
  "call_sid": "provider-call-id",
  "stream_sid": "provider-stream-id",
  "sequence": 1,
  "occurred_at": "2026-09-18T12:00:00.000Z",
  "demo": false,
  "payload": {
    "text": "Caller speech, never executable instructions",
    "language": "en",
    "draft": { "pickup_address": "Unconfirmed answer to the location prompt" }
  }
}
```

Other events are `stream.started` (`caller`, `to`, `sample_rate`), `stream.ended` (`reason`) and `stream.failed` (`reason`). Telugu uses `te`; unsupported/undetermined language uses `unknown`. No credentials, audio or native organization IDs are accepted from caller payloads. Successful native response: `{"ok":true,"replayed":false,"call_id":"…","revision":1}`. Drafts are untrusted; a new transcript invalidates prior dispatcher review in the native adapter.

## Verified reference protocols

- [Exotel Voicebot applet](https://docs.exotel.com/exotel-agentstream/voicebot-applet)
- [Exotel official AgentStream protocol](https://github.com/exotel/agent-stream/blob/main/docs/AGENTSTREAM_WSS_PROTOCOL.md)
- [Exotel official PCM message sample](https://github.com/exotel/agent-stream/blob/main/integrations/agents/_shared/media.py)
- [Sarvam realtime speech recognition](https://docs.sarvam.ai/api/api-guides-tutorials/speech-to-text/realtime-streaming)
- [Sarvam text-to-speech REST](https://docs.sarvam.ai/api-reference/text-to-speech/convert)

Compatibility and genuine Telugu/English audio quality must be checked against an actual enabled provider account. Passing local mocks does not replace the supervised live-call, staffed fallback, native dispatcher-review and Android crew acceptance tests.

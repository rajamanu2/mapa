# MAPA hotline commissioning

The code can receive an authenticated Exotel Voicebot stream, collect English/Telugu speech, preserve final transcripts through native API outages, and hand the request to an operator for review. It does not assign a telephone number, prove that a human answered a fallback call, or dispatch an ambulance without operator confirmation.

## Read-only setup check

From the workspace root, run with Node 24 or newer:

```powershell
node mapa-voice-bridge/commissioning.mjs --native-env fleetbase/asr/.env --probe-local
```

For a standalone bridge use `--env-file` with its private `.env` instead. Omit `--probe-local` for an entirely offline audit. The optional probe only requests `GET /health` and `GET /ready` from `127.0.0.1`; it never opens a media socket. No values, account identifiers, telephone numbers, URLs or credentials are printed. No provider request, call, record, environment edit or service start occurs.

Exit 2 means required bridge configuration is missing; exit 1 means input could not be validated. Exit 0 means the settings passed static checks, **not that the hotline is commissioned**. `live_call_verified` stays false because this tool cannot observe provider approval, a staffed telephone fallback, real speech or crew acceptance. A running bridge's `/ready` response is also component health only. The native `.env` report is not a read of effective Docker/secret-store overrides; inspect the intended deployment separately.

## Required real-world connection

1. Confirm the Exotel account is approved, has a working incoming number, and has Voicebot/AgentStream available. Use the assigned number exactly as displayed in the provider account. A key or account ID in a file does not prove these conditions. No substitute number should be invented.
2. Provide a staffed dispatcher number for the following Connect applet. Set its no-answer, busy, timeout and failure handling with the control-room supervisor. Keep the existing staffed incoming route operational until the new route has been tested.
3. Provide a stable WSS endpoint with valid TLS and the strict `/media` proxy described in the deployment README. The prepared temporary ingress is for supervised connection testing only. The current PC-backed MAPA share link and an expiring Quick Tunnel do not supply always-on hotline hosting.
4. Keep distinct stream Basic credentials, bridge-to-native credentials and the Sarvam Model API credential in private server configuration. Match the native API's Exotel account and bridge secret. The stream endpoint must not be placed in the APK or public client settings. Never put secrets in a command argument or readiness report.
5. Set `MAPA_VOICE_PUBLIC_WSS` to the credential-free stable endpoint. Configure the private credential-bearing version only in Exotel's Voicebot applet, with `sample-rate=8000` or `16000`. Exotel sends its Basic authentication header to the bridge. The same sample rate must be preserved through ASR and playback.
6. Confirm the following Connect applet and failure route before setting `MAPA_VOICE_HUMAN_FALLBACK_CONFIGURED=true`. Enable `MAPA_VOICE_LIVE_ENABLED` on the intended bridge and native API only when the dependency checks pass. Rebuild the bridge image to include current provider changes before starting it. Keep `MAPA_VOICE_DEMO_ENABLED=false` in the real operation environment. Do not reset, migrate away or erase a journal that contains pending events.

Do not automatically increase `MAX_CALLS` to 25 for 25 crew phones: concurrent incoming telephone streams and Android users are separate capacities. The bridge defaults to 10 simultaneous streams; actual provider quotas, dispatcher staffing and failure routing must be measured before increasing it.

## Supervised acceptance record

The operator makes these checks on an agreed non-emergency test call and records actual results. No automated script places these calls. Use an agreed test scenario and mark resulting test records accurately; do not represent them as real patient incidents.

| Check | Required evidence |
| --- | --- |
| Incoming number | The assigned Exotel number reaches the expected staffed flow. |
| English and Telugu | Both prompts are understandable and both transcripts preserve the caller's location and reported problem. A Telugu-speaking dispatcher reviews Telugu wording and pronunciation. |
| Caller identity | The displayed callback number matches the originating phone; the operator corrects a different callback number when needed. |
| Pickup | The operator selects/confirms the map point with the caller; speech must never invent coordinates. |
| Normal handoff | Completing the intake reaches a human on the following Connect applet. |
| Immediate handoff | Pressing 0 reaches that human promptly. |
| Failure handoff | A supervised bridge/provider failure, rejected stream and full capacity each reach the staffed fallback; no caller is silently abandoned. |
| Native delivery | One call appears in MAPA with the correct account, final transcript and visible failure state if applicable; retrying a lost API response does not create another call. |
| Reviewed dispatch | The operator confirms destination/capability and assigns an eligible real crew account with a fresh genuine GPS fix. |
| Crew | The intended phone receives the correct assignment and callback, acknowledges it, and reports each real trip stage. |
| Recovery | A queued transcript survives a controlled bridge restart and arrives once service is restored; do not conduct this on active emergency calls. |
| Operations | Staff can detect a stopped bridge, blocked/old queue, lost server connectivity and unanswered assignments. |

The final acceptance record needs a date, supervisor, provider call reference, expected/observed outcome and any failure. Store it privately without duplicating patient details or credentials. Failed fallback, wrong GPS/crew mapping, missing transcripts or uncertain dispatch block operational release.

## Evidence from 30 September 2026

- Existing private native settings contained the Exotel account, stream credentials, bridge secret and a speech key. Their contents were not printed or transmitted.
- Live voice capture was disabled; the configured public WSS endpoint and staffed-fallback confirmation were absent.
- The existing bridge was stopped, and its optional temporary voice edge was stopped. Main MAPA API/database services were healthy. No voice service was started or restarted by this audit.
- Corrected the TTS request to the current REST reference's required `language_code` field for English/Telugu. Older provider integration examples still use `target_language_code`; the current endpoint reference is the contract used here. Actual subscribed-account speech acceptance remains unverified.
- All 32 bridge checks passed, including durable retries, call isolation, stream authentication, protocol failures, Telugu/English request formatting and the new redacted readiness report. These are isolated software checks with mocked speech/native delivery; zero provider calls or production records were created.
- The actual read-only local audit at 15:44 UTC returned the missing WSS/fallback/live configuration gates and confirmed neither local health nor readiness was reachable. Container inspection independently showed the voice bridge stopped. The audit did not change that state.
- Provider credentials, approved incoming number, billing/quotas, real audio quality, live fallback and the full physical crew chain have **not** been proven by local tests.

Reference protocols checked: [Exotel Voicebot](https://docs.exotel.com/exotel-agentstream/voicebot-applet), [Sarvam realtime recognition](https://docs.sarvam.ai/api/api-guides-tutorials/speech-to-text/realtime-streaming), [Sarvam TTS REST request](https://docs.sarvam.ai/api-reference/text-to-speech/convert).

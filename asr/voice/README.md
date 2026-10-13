# MAPA operator call intake and reviewed dispatch

This module adds telephone-intake records to the existing MAPA Laravel server and creates the same native orders used by the console and Android crew app. Provider messages and extracted drafts cannot dispatch an ambulance. A native administrator or explicitly authorized superadministrator must confirm the pickup coordinates, reported problem, callback number, priority, required ambulance capability, receiving place and selected eligible crew.

The selected ASR Health workflow is manual: the operator answers the existing phone number outside MAPA and enters New Call details, confirms the pickup pin and receiving destination, then explicitly dispatches an eligible crew/ambulance. Manual intake uses `manual_enabled` independently of Exotel's `live_enabled` flag. No phone-number activation, voice streaming or speech API is required for this workflow. New training calls are disabled in the current reviewed configuration; historical training records retain their labels. There is no unattended dispatch or automated clinical triage.

## Installation and configuration

The root MAPA image copies this directory to `app/MapaVoice`; the existing MAPA provider registers `VoiceServiceProvider`. Run the additive `2026_09_18_000002_create_mapa_voice_tables` migration through the reviewed deployment procedure. Booting the provider does not migrate or provision accounts.

| Environment variable | Purpose |
| --- | --- |
| `MAPA_VOICE_ENABLED` | Expose the reviewed module routes. Default false. |
| `MAPA_VOICE_MANUAL_ENABLED` | Permit operator-entered real calls and their reviewed dispatch, independently of Exotel. Default false; enabled by the existing live-access Compose setup. |
| `MAPA_VOICE_DEMO_ENABLED` | Legacy training configuration name; current reviewed `config.php` disables new training calls regardless of this environment value. |
| `MAPA_VOICE_LIVE_ENABLED` | Permit real provider-ingested calls and their reviewed dispatch. Default false; not required for manual calls. |
| `MAPA_VOICE_BRIDGE_SECRET` | Dedicated server-to-server bearer secret, at least 32 bytes. Never put it in the browser or APK. |
| `MAPA_EXOTEL_ACCOUNT_SID` | Required exact provider account for live bridge events. |
| `OPENAI_API_KEY` | Optional server-only draft-extraction credential. |
| `MAPA_VOICE_EXTRACTION_MODEL` | Explicit extraction model identifier. Empty disables extraction. |

Use the existing `MAPA_COMPANY_UUID` tenant and mobile role bindings. No browser-supplied company or organization API key is accepted. `max_gps_age_seconds` defaults to 180 in server config. Protect the bridge endpoint with TLS at the deployment gateway; the compose-network HTTP hop is private. Store secrets outside distributed source and rotate them through the operator's deployment process.

## Staff API contract

Base path `/mapa/voice/v1`, contract `mapa-native-voice-v1`. The browser signs in through `/mapa/mobile/v1/login` and keeps the returned opaque token only in memory. Every staff endpoint revalidates current membership, session revocation, password version, role binding, native account verification, MFA and reset restrictions. Crew and hospital roles cannot read telephone records or dispatch.

- `GET /locations?q=...&online=1`: authenticated dispatcher place search; omit `online` for local-only typeahead.
- `POST /locations/nearby`: `{latitude,longitude}` returns local approximate settlement context within five kilometres without changing or externally geocoding the selected point. Invalid coordinates return 422; crew/hospital accounts cannot access it. This endpoint is read-only and creates no call or dispatch receipt.
- `GET /calls`: up to 100 most recently updated tenant calls and capabilities. `demo`, `live_intake` and `extraction` are explicit flags. `dispatch_requires_confirmation=true`; `push_notifications=false`; `road_eta=false`.
- `GET /calls/{id}`: `call`, `candidates`, `unavailable`, `places`. Candidates are returned only after operator review. The receiving-place list stays in the call's DEMO or real environment.
- `POST /calls/manual`: operator-entered draft with `{action_id,demo:false,...intake fields}`. No provider event or audio is required. The draft starts in `needs_review`, and submitted coordinates cannot confirm themselves. Manual review additionally requires `coordinates_confirmed:true` after the operator has checked the pin with the caller.
- `POST /demo`: `{action_id}` creates an unmistakably fictional transcript. It refreshes only the dedicated, unassigned `driver_mapa_voice_demo_01` / `vehicle_mapa_voice_demo_01` fixture carrying the `mapa-voice-demo-v1` dataset metadata. Its location and duty are explicitly simulated. Existing assignments are preserved.
- `POST /calls/{id}/review`: `{action_id,expected_revision,confirmed:true,caller_name,callback_number,reported_problem,pickup_address,landmark,latitude,longitude,priority,vehicle_requirement,dropoff_id}`. Priority is the dispatcher's assessment: `routine`, `urgent`, `emergency`. Capability is `any`, `bls`, `als`. A callback can be blank/unknown; Indian ten-digit mobile numbers normalize to `+91`, and explicit international numbers use a leading `+`. Spaces, parentheses and hyphens are accepted as formatting. Invalid numbers return a field error instead of disappearing from the crew's coordination details. All coordinates and destinations require human review.
- `POST /calls/{id}/dispatch`: `{action_id,expected_revision,confirmed:true,driver_id}`. The selected candidate is rechecked while native crew/vehicle rows are locked. Creates one native order, pickup, payload, tracking number and initial tracking status atomically.
- `POST /calls/{id}/dismiss`: `{action_id,expected_revision,reason}` closes an intake without an order.
- `POST /calls/{id}/extract`: `{action_id,expected_revision}` optionally asks the configured extractor for suggestions. It runs outside database locks, checks the unchanged revision before saving and requires a new operator review. It never changes an order or supplies a priority, capability or map point.

Mutation success is `{ok:true,replayed,call,...}`. A UUID action identifier can be retried with exactly the same body; it returns the recorded result without another write. Reusing an identifier with different details or using an old revision returns 409. Authentication is rechecked even for a retry. Errors are `{error,fields?}` and use no-store responses. Refresh after a conflict; never silently regenerate an action identifier to repeat an uncertain dispatch.

Call fields include `id`, integer `revision`, `state`, `demo`, `caller`, `called`, `provider_status`, plain `transcript`, untrusted `draft`, confirmed `review`, `order_id`, `order_status`, `acknowledged`, `created_at`, `updated_at`. Times on calls are UTC ISO 8601; candidate fix times are epoch milliseconds. States are `needs_review`, `ready`, `dispatched`, `dismissed`. The last two are terminal for the intake workflow. The native order can continue independently in the console.

## Provider event contract

`POST /bridge/events`, JSON and `Authorization: Bearer <MAPA_VOICE_BRIDGE_SECRET>`:

```json
{
  "event_id": "unique-UUID",
  "event_type": "transcript.final",
  "account_sid": "configured-account",
  "call_sid": "provider-call-id",
  "stream_sid": "provider-stream-id",
  "sequence": 2,
  "occurred_at": "2026-09-18T00:00:00Z",
  "demo": false,
  "payload": {
    "text": "Caller-reported transcript",
    "language": "en",
    "draft": { "pickup_address": "Caller-reported address" }
  }
}
```

Supported types: `stream.started`, `transcript.final`, `stream.ended`, `stream.failed`. Payload may contain caller/to, final text, en/te/unknown language, safe reason and permitted draft fields. The bridge's exact immutable body is hashed. An exact event retry returns 200 with `replayed:true`; a reused event ID with changed content or a different event ID at an already-used call sequence returns 409. A new lower sequence is recorded as not applied, so delayed messages cannot overwrite newer state. The bridge journal must preserve event order and sequence across reconnects; quarantine conflicts for operator review. `failed` remains failed after late transcripts or stream-end events.

Transcripts append up to 32,000 UTF-8 bytes per call, with 500 events maximum. Provider draft fragments merge only allowed text fields. Latitude, priority, role, company, vehicle assignment and dispatch directives are ignored. A final transcript arriving after review invalidates that review. After dispatch it may update the intake transcript, but never changes the already assigned native order.

## Candidate and native-order behavior

Candidates require an enabled crew binding and native mobile eligibility, active tenant membership, an on-duty driver and vehicle, consistent unique driver-to-vehicle assignment, matching DEMO/real metadata, the requested `vehicle.meta.mapa_ambulance_level` for BLS/ALS, no unfinished native order already assigned to either resource, valid coordinates and a recent mobile driver-state fix. Live candidates exclude both DEMO and simulated resources. DEMO candidates remain plainly labelled simulated.

Ranking uses great-circle distance to the confirmed pickup and reports `distance_basis=straight_line_not_road_eta`. It is not a driving route, arrival estimate or clinical suitability recommendation. A real routing integration and commissioned resource-capability records are still required for production.

Native writes preserve the reviewed transport configuration. The tracking number is inserted with a null status pointer, its tracking status is inserted next, then the pointer is linked, before creating the order. This respects the native circular foreign keys. All voice writers serialize on the native company row, lock selected driver/vehicle rows and recheck booking/eligibility inside one transaction. The call has a unique order reference and action receipts prevent duplicate submissions.

This module does not change upstream native console write paths. An independently executing native manual assignment path that does not honor the same booking locks can bypass this module's resource-reservation check. Do not describe this adapter as enforcing global dispatch concurrency until all assignment entry points share the reservation protocol.

Native saved/dispatch events are deliberately not emitted: installed upstream event listeners may send external notifications before transaction commit. After native reference validation, the dedicated MAPA assignment observer schedules a minimal crew alert only after the entire dispatch transaction commits. An alert scheduling failure cannot roll back dispatch, and an exact dispatch retry does not enqueue another alert. The observer excludes training records and requires enabled, configured push delivery; unconfigured installations still depend on an active crew refresh. The Android app reads and acknowledges the same native order through its existing API. Timeout escalation, automatic reassignment, road routing and clinical workflows remain outside this pilot. An acknowledgement is shown only when its stored native assignment hash matches the current order.

## Verification and operational limits

Pickup search includes 11,074 regional OpenStreetMap landmark and settlement records alongside 7,457 GeoNames records, a small address-only district directory, 11-mandal spelling aliases, and explicit online OpenStreetMap/Photon searches without an API key. These overlapping regional sources include neighbouring areas and are not an exhaustive ASR register. Categories and station codes make queries such as Araku railway station or ARK searchable locally. Typing stays local; Search map or Enter requests additional mapped candidates. Provider failures retain local results and manual pin entry. An optional Google Maps/Places adapter is available for a future Maps browser key. See [pickup maps and setup](PICKUP-MAPS.md) for provenance, rebuilding the index, privacy, rate limits and validation limits.

`tests/native.php` uses disposable connection-local TEMPORARY shadows of native schemas and no production rows. The actual voice migration is transformed only to create temporary tables. Because MySQL temporary tables cannot enforce foreign keys, the harness reads the real native FK definitions and verifies affected references after every native write, including the circular tracking relation. Provider retries/reordering, authorization, tenant isolation, DEMO separation, stale GPS, stale reviews, busy resources, one-order idempotency, native crew visibility and acknowledgement are exercised with network, events, mail and queue faked. `tests/extractor.php` tests the optional extractor with mocked HTTP.

Example inside a review container: `php /tmp/mapa-voice-test-source/tests/native.php`. Do not execute an edited test harness without reviewing its isolation guarantees.

Manual-call rollout requires phone-reachable HTTPS, reviewed real crew/ambulance/receiving-place identities, physical phone testing, verified active-duty assignment alerts, operator escalation, shared assignment reservations and retention/access policies for caller records. The module persists records until an operator-managed retention process is added. Firebase remote push is an optional separately commissioned delivery path; the current signed APK uses active-duty polling.

Automatic provider capture is outside the selected launch scope. If requested later, it additionally needs authenticated TLS/WSS audio ingress, approved Exotel account/number, enabled media streaming, tested English/Telugu speech and a staffed human fallback. Those provider requirements must not be presented as prerequisites for operator-entered New Call operation.


### Assignment availability

Online / Available in the native driver list is a duty/status flag, not a reservation check. Reviewed calls now include an `unavailable` list with a reason for each excluded ambulance in the same DEMO/live environment: unfinished assignment (with order reference), off-duty state, inconsistent crew/vehicle link, crew access, required capability or missing/stale GPS. Candidate inclusion and dispatch revalidation share the same eligibility implementation. Refresh eligibility after resolving a blocker; the server still rechecks under dispatch locks.

The exact older unstarted `order_mapa_mobile_demo_01` training fixture was canceled on 2026-09-19 to release DEMO Mobile Ambulance for the user's new reviewed DEMO call. Native cancellation history and its original assignment were retained. No trip completion, GPS update or external notification was fabricated. This was a one-time guarded training reset, not an automatic cancellation policy.
# Automatic dispatch

Reviewing a MAPA-owned call with a confirmed pickup, destination and capability saves
a durable `searching` dispatch run. The scheduler, rather than the review request,
performs routing and sends the first offer. Eligible on-duty crews need fresh driver
phone GPS and a valid mobile assignment; a Way4Track vehicle dot alone is insufficient.
When a fresh mapped ambulance device fix exists, road distance uses the ambulance's
position while retaining the crew phone as a second, separately timestamped source.
If they are over 500 m apart, automatic dispatch excludes that pairing for control
review. A person without a verified native driver/vehicle assignment can share a
location in the broader product, but cannot receive a native automatic dispatch yet.
The engine searches 0–500 m from pickup, then successive 1 km distance bands, and
offers the closest eligible crew in the first occupied band. Private road travel time
breaks ties within that band. Each offer has a 120-second response window. Decline or
expiry advances to the next eligible crew, up to five distinct crews. First valid
acceptance creates the existing native MAPA order atomically; stale or duplicate
responses cannot take the call. All attempts and responses are persisted and visible
on the command desk. If five attempts fail, no crew qualifies, or a worker stalls,
the desk shows an operator exception for manual rescue; the engine does not silently
keep offering or pretend a crew accepted. The scheduler runs the dispatch tick every
ten seconds. A reviewed call with a disabled feature flag still uses manual dispatch.

Each committed offer queues a privacy-safe crew phone push hint when FCM is configured;
the phone fetches the current offer and its countdown. Foreground duty polling remains
the fallback. Crew acceptance queues the normal assignment hint and, for a verified
MAPA-native receiving hospital, an early hospital hint. The hospital app reads the
assigned trip without an invented patient observation; its later handover report uses
the existing separate alert. Provider acceptance of a push is not proof a human saw it.

Acceptance starts the normal native crew trip. Its stages and closure require crew
actions and hospital handover evidence through MAPA mobile; GPS movement alone never
closes a trip. Command-desk reminders and stale-worker alerts require a staffed desk.

ASR-created calls and ASR-linked/imported drivers or ambulances are excluded. This
module never reserves, starts, ends or changes an ASR trip. Shared fleet automation
requires an agreed dispatch owner; source write coordination must remain disabled.

The operator control is **Settings → Automatic Dispatch**. It uses a per-company
database setting, initially off, plus the `MAPA_AUTOMATIC_DISPATCH_ENABLED` VPS
master switch. When both are on, newly reviewed MAPA-owned, non-demo live calls
start a durable server run without the browser staying open. The operator must
explicitly review the pickup, receiving place and capability. ASR-owned calls and
supervised no-patient drills do not enter this all-live scope. An older reviewed
call needs an explicit operator start. Turning the setting off cancels outstanding
offers and stops active runs under the company transaction lock; it does not
cancel already accepted orders. The page shows the background worker heartbeat.
Enable the master only after the settings migration and server checks. The old
exact-call and crew UUID environment variables are used only on installations
without the new settings table; the table setting supersedes them.

Local verification: `tests/automatic-dispatch.php` uses Laravel with an isolated
in-memory SQLite database and synthetic crews. It exercises the real offer engine
and private route validation, but stubs native authentication and order creation;
it does not prove production MySQL locking or live Android delivery. The Android
offer tests and native phone regression suite run through `mapa-android/test-native.ps1`.

### New Call workbench comparison — 7 October 2026 (local)

The reference project in `D:\` provides a compact caller form followed by a dispatch desk. MAPA now groups the existing intake and review controls into Caller & patient, Pickup & destination, and Dispatch requirements. Section buttons scroll within the form; the layout fills the MAPA tab and stacks on narrower screens. Its right rail contains automatic offers, the manual ambulance directory and the existing private caller tracker. All ASR PHC/secretariat/village/destination controls retain their original validation and event handlers.

Automatic offers are emphasized when the server advertises `automatic_dispatch`. Manual dispatch remains available before offers start or after they stop. While offers are active, the intake is read-only and manual assignment is hidden. Start/stop intents now pass the bounded browser API allowlist. The directory no longer clears the automatic controls after rendering them.

ASR-owned calls continue to display source details and caller tracking only. This work does not reserve ASR vehicles, change ASR trip status, copy reference accounts/data or enable live dispatch. Reference-only neonatal/4x4 capability and driver analytics need explicit fleet metadata and separate integration; the existing supported capability choices remain unchanged.

Validation: 585 local frontend assertions across intake, New Call workbench, automatic start/stop intents, ambulance directory, caller pickup, SMS, source-call UI, phone, map search and call tabs. Fixtures use DEMO data and make no network requests or operational writes. JavaScript syntax check passed. Actual browser visual verification, VPS migrations/feature activation and the updated crew APK remain separate release checks; this entry is not a production deployment report.

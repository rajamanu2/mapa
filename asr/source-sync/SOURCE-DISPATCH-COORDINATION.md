# Gated source fleet ownership and native transition outbox

This is a local implementation. All feature/configuration gates remain disabled by default. There is no production activation, no running timer and no delivery or emergency-readiness claim.

## Ownership protocol

`SourceDispatchCommands` uses `mapa-asr-dispatch-v1`. The exact request envelope is `contract`, `company_id`, `request_id`, `actor_id`, `action`, `journey_id`, `ambulance_id`, `driver_id`, `expected_version`, `next_status`. Request, actor and journey identities are lowercase UUIDs; source vehicle/driver IDs are positive decimal strings. The native call UUID is the durable journey identity. Supported actions are reserve (version 0, null next status), lookup (null version/status), and transition (positive version and explicit next status).

Private source actor grants specify the exact company, actions, ambulance IDs, driver IDs, and permitted `transition_states`. Wildcard resource grants are not accepted. Lookup and transition authorize the stored reservation's resource IDs as well as the submitted IDs. They cannot reveal or move another actor's reservation by supplying different resource IDs. A terminal reservation cannot be used as a new reservation grant. Completed/cancelled history remains available through authorized lookup.

Requests are bounded JSON POST without query parameters. HMAC binds the exact body, timestamp and random nonce to the dispatch protocol domain. Responses bind nonce, HTTP status and exact body. Success echoes every request field and returns only a reservation's status/version/creation/terminal timestamps. No patient data is present. The MAPA client verifies the exact envelope and schema; malformed, stale-scope, unsigned, mismatched, HTML, timeout and unknown responses retain an uncertain outcome. It never retries automatically, changes the journey identity or releases ownership.

The public PHP boundary requires a private `MAPA_SOURCE_DISPATCH_CONFIG`, both `enabled=true` and `original_writers_coordinated=true`, the shared coordination policy enabled outside maintenance, and its reviewed `dispatch_schema_sha256` for the actual restricted SQL account. It uses the explicitly configured original PHP timezone for source daily-session eligibility. A missing schema, changed account visibility, disabled policy or missing original-writer attestation fails closed. The source-side shared mutex and durable reservation enforce vehicle/driver ownership against participating original writers.

## Native transaction boundaries

`MAPA_SOURCE_DISPATCH_COORDINATION_ENABLED` defaults to false. With it enabled, reviewed native dispatch first commits a row in `mapa_source_journeys` outside `VoiceService::mutate`'s native transaction. The intent fixes the actor/binding revision, reviewed mapping revisions, native/source resource IDs, original request hash and reserve command. Only then can reserve HTTP run. A source reservation response is persisted before native order creation. The native order link, call update and native idempotency receipt commit together inside the existing native transaction.

A native rollback leaves the source intent/reservation intact and the call retryable with unchanged details. An unknown response, rejected request, mapping change, actor revocation or native assignment change does not automatically release the resource. Another native journey cannot reuse a held driver/vehicle. Changing a mapping with active or uncertain ownership is rejected. Explicit operator reconciliation for irrecoverable intents is a separate required workflow; there is no automatic cancellation or expiry.

Actual native crew activity creates durable outbox records in the same transaction as its status/audit:

| Native confirmed activity | Ordered source transition |
|---|---|
| started | en_route |
| enroute (pickup confirmed) | arrived_scene, then transporting |
| completed with native handover confirmation | completed |

`mapa:source-dispatch-drain --limit=20` processes bounded outbox work. Every attempt rechecks current native account/membership, binding revision, resource mappings, assigned driver/vehicle and persisted native crew/action evidence. A later phase waits until the preceding source acknowledgement is durable. Unknown responses retry the same stored envelope on a later worker invocation. The worker does not infer progress from cached source snapshots or native status alone. Native completion does not free source ownership until ordered source completion is acknowledged. The handover evidence is the existing authenticated crew/GPS confirmation audit; it does not claim independent clinical verification.

## Commissioning limits

- Deploy additive native/source migrations only through reviewed deployment steps. Keep both source and native gates false until the original writer patch and metadata attestations are verified against the actual live source.
- The source dispatch SQL account needs only the reviewed reservation INSERT/UPDATE and availability/schema SELECT privileges. It needs no patient fields, original fleet update, DELETE or DDL privileges. Store secrets/configurations outside public roots; do not reuse an original application writer credential.
- Configure source actor grants separately from native roles. Crew phase delivery needs each applicable authenticated crew actor's explicit source transition grant; reserve permissions do not automatically grant crew transitions.
- Native legacy REST/model guards are a fail-closed boundary for the known routes/ORM writes. Quiet/query-builder/background writers still require an inventory and verified coordination. In particular, review enrollment assignment changes, native FleetOps import/jobs/controller mutations and any direct SQL writer before enabling coordination. Guards alone do not establish complete writer coverage.
- Source CAD incidents remain source-owned, read-only records in MAPA. Fleet ownership is not an incident claim: do not import/start an ASR incident as a native call without a separate incident ownership/CAS protocol, which is not implemented here.
- Install an independent worker schedule only after end-to-end commissioning. Backups, explicit stuck-intent reconciliation, live source patch verification and real-device/provider testing remain separate gates.

## Local verification

`run-dispatch-tests.py` uses a new internal Docker network, tmpfs source MySQL and loopback PHP server. It verifies source ACLs, signed reserve/lookup/transition receipts, terminal and unknown outcomes, no patient SQL privileges, and private policy/schema gates. `run-source-dispatch-saga-native.py` uses a separate fresh Laravel/MySQL fixture and fake remote transport to verify intent-before-HTTP, rollback recovery, exact retry identity, mutex contention, ordered phases, changed mapping/access holds and persisted handover checks. Voice hook tests exercise the actual mutate/prepare/dispatch transaction boundary with authentication/eligibility/order construction fixtures. The dedicated native suite separately exercises actual TripWorkflow GPS/acknowledgement/start/pickup/handover and outbox creation. These are local synthetic tests, not production commissioning receipts.

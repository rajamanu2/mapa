# MAPA in-app AI maintenance backend

Implemented 19 September 2026 for the native MAPA/Fleetbase console and Laravel API. This is an additive source module, not a replacement application. It uses the existing AI extension (fleetbase/ai 0.0.4) and its OpenAI/Claude settings, task history and generic preview/apply cards.

## Implemented

- Provider model discovery from the saved account's canonical OpenAI or Claude endpoint. No fixed new flagship model is assumed. Discovery adds account-visible model IDs to the existing settings metadata. The selected ID survives catalog cache expiry. A listed model still needs a generation test; discovery does not establish suitability, endpoint compatibility or relative recency.
- Shared provider manager for existing native AI chat/tasks and settings. No duplicate credential store, browser key, provider purchase, automatic model upgrade or silent provider switch.
- Administrator-only database, namespaced cache, required-table, AI-configuration, stuck-task and expired-session diagnostics. Output is bounded service metadata and counts, not caller data or raw logs.
- Two concrete repairs: mark stuck running AI tasks older than 15 minutes as failed, preserving their history; revoke already-expired mobile sessions. The latter is session housekeeping: expiration was already enforced by authentication.
- Persisted preview, five-minute expiry, company/actor binding, at most 100 targets, per-record freshness checks, transactional changes and receipt, verification and idempotent replay. Changed records are skipped. Cancellation of an AI task prevents its capability apply.
- Existing chat examples: **Run a MAPA health check**, **Repair stuck AI tasks**, **Revoke expired sessions**. The repair is shown in the existing action card and only applied after its Confirm maintenance action.

## Activation

The included installer copies source into `asr/maintenance`, registers MaintenanceServiceProvider and adds it to the existing API Docker build. It does not start/restart containers or migrate a live database.

1. Build and test the API image in staging, preserving the previous image for rollback.
2. Apply the additive migration `2026_09_19_000001_create_mapa_maintenance_runs.php` once using the normal migration process. Do not drop this table on rollback: retain audit receipts.
3. Add `MAPA_MAINTENANCE_ENABLED=true` to the API environment in the deployment manifest and restart its application workers. A `.env` value alone will not enter a container unless Compose passes it. Default is disabled.
4. Use an active native console account with active company membership and an enabled native MAPA admin/superadmin binding. Model discovery additionally requires native user type `admin` and inclusion in `mapa_mobile.superadmin_user_uuids`, because provider configuration is platform-wide.
5. In the existing AI Provider Settings, save the provider/key. Never put a key in chat. POST an empty JSON object to `models/discover` using the same authenticated native console client. Reload Provider Settings, select an appropriate discovered text model, test it, and save it. No UI discovery button is added in this backend release.
6. Run the diagnostic prompt and test preview/cancel/apply/history in staging. Disabled or unconfigured providers are not represented as a connected model. The independent diagnostics HTTP endpoint remains usable without purchasing or connecting a model.

The module shares the **native AI extension's** provider selection. The separate voice-draft extractor is intentionally not migrated: its strict reviewed-intake contract needs its own model evaluation before changing providers. The legacy port-8791 application is not modified.

## HTTP contract

Native `fleetbase.protected` authentication, current company session, active membership and MAPA admin binding are required. Never expose these routes outside the existing authentication layer. Requests are throttled to 20/minute; POST bodies must be JSON up to 4 KiB and browser Origin must match. Use the same native console client/CSRF and authorization setup as existing AI endpoints.

| Method | Path under `/mapa/maintenance/v1` | Body/result |
|---|---|---|
| GET | `/diagnostics` | Service checks; unavailable probes do not abort other checks |
| GET | `/history` | Last 50 company repair receipts; no persisted target snapshots |
| POST | `/models/discover` | `{}`; platform administrator only; cache TTL one hour |
| POST | `/repairs/preview` | `{"action":"recover_stuck_ai_tasks"}` or `{"action":"revoke_expired_sessions"}` |
| POST | `/repairs/{run_id}/apply` | `{"confirm":true}`; same actor and company as preview |

An expired preview returns 409. Repeating a completed run returns its saved receipt with `replayed:true`. Unknown actions and arbitrary SQL/code are rejected. The AI receives no facility to run shell commands, install packages, change source files or bypass the action allowlist.

## Full product scope still needed

This is a tested first maintenance module, not autonomous repair of every software defect. Database/cache reachability does not certify queues, dispatch, mobile GPS, telephony, provider quality, backups or internet hosting.

For complete in-app engineering support, add a sanitized error/event collector with correlation IDs and retention controls; queue/scheduler/voice/mobile health adapters; issue deduplication and reproduction attachments; a code-repair job service with immutable source revision, isolated checkout, no production credentials, bounded tools, test/build runner and reviewable diff; staged release deployment with health gates, rollback artifact and release history; model evaluations and per-role cost/rate budgets. The in-app workflow can own reporting, review and deployment status, while the code runner remains isolated from production. A model connection alone cannot safely provide these capabilities.

Do not grant an LLM arbitrary database or filesystem access to claim universal self-repair. Ambulance assignments, clinical records, real notifications, access grants and provider credentials are outside these repair actions.

## Validation and limitations

`tests/run.php` runs in the existing API image with networking disabled, an isolated in-memory SQLite database and fake provider HTTP. It exercises actual Laravel/native AI classes. It is not a live-provider or MySQL-concurrency test. No real credentials, patients, calls or live database changes are needed.

Before enabling in production, test MySQL row-lock contention and rollback, native authenticated browser preview/apply, provider credentials/model generation, configured reverse-proxy Origin behavior, migrations and image rollback. Existing native upstream AI endpoints retain their existing authorization and behavior; this module is not an audit of every upstream action.

API sources: https://developers.openai.com/api/reference/resources/models/methods/list and https://platform.claude.com/docs/en/api/models/list. Framework contract: https://github.com/fleetbase/ai/tree/v0.0.4.

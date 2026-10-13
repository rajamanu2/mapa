8 October 2026 bottom GPS fleet update: all 32 Way4Track devices are now in the native Ambulances drawer with search/scroll, exact captured provider labels and permanent map labels. Native MAPA vehicle controls remain available via the records toggle. 62 UI and 17 publisher checks passed; live 32-row/32-label parity and scrolling verified. See private/all-devices-20261007/BOTTOM-LIST-RELEASE-REPORT.md. Provider display names are captured metadata; telemetry continues polling automatically.

8 October 2026 all-device GPS map: the authoritative VPS now displays all 32 Way4Track provider locations, including six explicitly unmapped devices and stale/offline last-known reports. Browser verification confirmed all 32 markers within the visible map area, plus explicit IST timestamps. Native fresh GPS ingestion and ASR operational independence remain unchanged. Final frontend hash b599184d879e5ba752aa6c3275f3b3974670301e4e2d97120b21c5ceafd2999c. See private/all-devices-20261007/RELEASE-REPORT.md for checks and evidence. This is not a claim that all devices are online.

# MAPA availability and recovery

## Way4Track backend ingestion verified — 7 October 2026

The VPS poller is active after a private runtime-credential repair. At 15:47:16 UTC, the service was active/running with zero restarts; five provider positions, four fresh fixes, 48 history records and five native vehicle projections were verified. Browser vehicle-list and map-marker updates also passed. These counts do not imply all 26 configured devices are online. A main-map vehicle detail showed advancing coordinates, and table-coordinate selection focused its real marker (`private/way4track-connect-20261007/mapa-live-gps-verified.png`). The embedded New Call desk remained at its operator-session loading message; its quality-popup check was not verified, and this GPS check does not establish overall New Call readiness. Systemd credential copies retain mode 0600 inside a service-owned mode 0700 runtime directory; the original root credentials, database and authoritative volumes were preserved. The poller runs independently of the PC, but an expired provider session requires authorized renewal. ASR source dispatch remains independent, and provider-reported fixes do not qualify as verified dispatch GPS. Preserve the existing service and checkpoint; see `private/way4track-connect-20261007/RECOVERY.txt` for recovery. The monitor automation was not changed.

## Private road routing enabled — 5 October 2026, 01:11 UTC

Application road routing is enabled on the authoritative VPS. The guarded v3
enable phase passed its full Compose/configuration comparisons, persisted the
API's private network and routing environment in `compose.override.yml`, and
installed the verified runtime configuration cache. Only Octane workers were
gracefully reloaded; the API container identity stayed unchanged and healthy.
The authoritative `mapa-final-20261004t003110z-{mysql,redis,uploads}` volumes were
preserved. Earlier statements below that routing was disabled describe their
deployment phase and are superseded by this activation record.

The private OSRM engine uses the verified Geofabrik India Southern Zone extract;
coverage is regional, not all India. Nineteen checks exercised the actual API
adapter and tracking projection using synthetic in-memory fixtures, including
pickup and hospital road geometry. A separate out-of-coverage Delhi query returned null.
No real trip records or patient positions were written. A controlled real-trip
road-routing acceptance test remains pending; these checks do not establish
traffic-aware arrival predictions or complete operational readiness.

The standalone `mapa-road-router-20261005` container has a read-only dataset,
private internal network, no published ports, 6 GiB memory, no additional swap,
2 CPUs and `unless-stopped` restart policy. It was running with zero restarts and
no OOM at activation. The policy supports ordinary reboot/crash recovery, but
Compose does not recreate a deleted standalone router. Preserve the pinned image,
recovery dataset and private activation records. Do not substitute a public road
provider or expose the engine to the internet.

Always include `compose.override.yml` in explicit Compose commands. Future API
recreation depends on its persisted routing environment and private network.
The current container's original environment predates activation: blindly running
`config:clear` or rebuilding its cache can turn routing off. Treat the installed
cache as deployment configuration and preserve it or deliberately regenerate it
from the intended settings through a reviewed change.

Eight public checks passed at 01:11:35 UTC: both tracking assets matched their
published hashes, health/auth/anonymous branding returned 200, and anonymous
current-user, saved-intake and caller-tracking reads returned JSON 401. Root's
reloaded Chrome Operations tab remained signed in with ASR Health branding,
MAPA.DEV credit and Saved calls. The actual ASR Health loading screen was also
captured in `asr-health-loading-live.png`. No real trip was created for these
checks, and the compatibility Pages host was not re-requested.

Private evidence: `private/caller-confirm-route-deploy-20261005/private-road-engine-verification.json`
and `road-enabled-public-verification.json`. The successful enable state is
`/opt/mapa-staging/private-road-enable-20261005-v3`; the v3 helper SHA256 is
`4f0b59ad3751954d78c176b692f63f2b992f2466f0bc8b173cbbfea12f9d2777`.

## Caller GPS receipt precision fix — 5 October 2026, 00:53 UTC

The two-file caller-page patch is live. A real browser's high-precision GPS fix
could be saved correctly by the backend but shown as uncertain by the caller page
because the response normalizes coordinates to seven decimal places and accuracy
to three. Receipt validation now accepts only that bounded normalization while
retaining exact timestamps and the frozen original retry request. Shifted or
mismatched receipts still fail validation. No database or service restart was
needed for this static patch.

The local suites passed **223 caller behavior checks**, **16 focused Chrome
checks**, and **4 deployment recovery checks**. Public HTTPS verification matched
the exact deployed `track/tracking.js` and `track/index.html` bytes. Canonical
health, auth and anonymous branding returned 200; anonymous current-user,
saved-intake and caller-tracking reads returned JSON 401. Pages compatibility
was not re-requested during this check; its known client-signature filtering is
tracked in the earlier entry.

Road routing was a separate pending activation step at this precision-patch
checkpoint; see the later activation record above. Private receipt:
`private/caller-confirm-route-deploy-20261005/precision-live-receipt.json`.

## Caller-confirmed pickup correction — 5 October 2026

The caller-link and operator changes were deployed to the authoritative VPS after
a private database backup and the exact additive `000014` migration. The nullable
`mapa_caller_locations.pickup_confirmation` column was installed before the code
and static assets. API workers were gracefully reloaded; no container or database
was restarted. The durable image is `mapa-vps-api:caller-confirm-20261005`; the
existing running `caller-20261005` containers retain their identities with the
verified source patches applied. File rollback retains the additive column.

Callers can explicitly confirm the patient pickup in their private link. That
fixed candidate remains separate from subsequent phone GPS updates. The operator
must review and accept a fresh candidate before an assigned trip's native pickup
changes; crew review is then required. Before dispatch, applying the candidate
only changes the unsaved review draft and clears its previous read-back check.
Caller-described address, landmark and entrance text remain available for review.
The caller page can show a bounded trail of reported ambulance fixes. Planned road
routing remained **disabled** at this release checkpoint while private route data
and service activation were prepared; see the later activation record above.

Separate isolated suites passed: **327 connected backend checks**, **212 caller
tracking behavior checks**, **35 operator pickup DOM checks**, and **69 operator
SMS/pickup checks**. The native run also reported **35 hardware-history/projection
checks** and **25 GT06 checks**. These are suite results, not a summed count of
unique scenarios. Twelve local deployment-guard tests and independent script
review passed. No real calls, orders, messages or GPS were created for these tests.

After deployment, canonical health, auth and anonymous branding returned 200;
anonymous current-user, saved-intake and caller-tracking reads returned JSON 401.
All five published caller/operator static assets matched the release manifest
byte-for-byte. Python GET checks from both the owner PC and VPS received Cloudflare
403 / Error 1010 (`browser_signature_banned`) from the compatibility Pages host;
a VPS HEAD check returned 200. The bounded public JSON error explicitly identified
browser-signature filtering. A normal in-app browser then opened the Pages
`/fleet-ops` URL, followed its redirect to canonical `/auth`, and rendered the login
screen successfully. Browser compatibility redirection therefore passed; these
checks do not establish Android/API compatibility for every client signature.
Use `https://app.itda.asrhealth.in/fleet-ops` as the preferred URL. No user-agent
workaround, Pages configuration or access-control change was attempted. Branding
deployment remains a separate phase.

Private receipts: `private/caller-confirm-route-deploy-20261005/`; payload SHA256
`ba27330bc59d87d4dbb115b4757b511e9bd80730820eca575500423b6566f6b9`.

## Separate call tabs and Operations saved calls — 5 October 2026

Both native call-create buttons now open `/call-intake/` in a separate tab,
without navigating or signing Operations out. Operations has a **Saved calls**
panel backed by a new bounded, company-scoped native-auth read endpoint. A
confirmed operator save sends a metadata-only cross-tab refresh signal; visible
Operations also checks every 10 seconds and refreshes on focus. Credentials and
caller data are never carried in the cross-tab message. Permission failures clear
the private panel without invalidating the native session.

Intake records remain distinct from dispatched native orders. The new saved-call
panel synchronizes those records; native map/table refresh remains an explicit
action where a route reload could otherwise discard an in-progress native edit.

Validation: 380 native integration checks; 77 native sync checks; 22 navigation
and signal checks; 138 voice, 52 directory DOM and 38 Google pickup checks passed.
An isolated two-tab browser save preserved the Operations URL and unsaved note.
Live reads showed the current company's saved calls. Both native NEW CALL controls
preserved Operations and opened a separate tab. Four public asset hashes, native
initializer ordering, anonymous intake denial and all nine public health/auth/
compatibility checks passed. No operational records were created for verification.

Private stage `/opt/mapa-staging/intake-sync-20261005-2358` contains file backups,
actual-container snapshots and the guarded recovery journal. Targeted mobile and
static files were replaced atomically; Octane workers were gracefully reloaded.
No containers or databases restarted. `compose.override.yml` now selects immutable
image `mapa-vps-api:intake-20261005`; running containers still have the earlier
image ID with the reviewed file patches applied. Authoritative volumes and feature
flags are unchanged. Always retain the override in explicit Compose commands.

## Pickup search repair and browser Maps option — 5 October 2026 (India)

The VPS was missing all three ASR public place catalogs: the runtime packager
previously copied only PHP files. The exact public catalogs and their provenance
notice are now installed; the packager now includes them through an explicit
allowlist. A missing catalog no longer prevents an explicitly requested online
search. Live operator search for Araku railway station returned local stations
and online mapped candidates after deployment.

The existing Search map and interactive pin remain available. Open Google Maps
opens the requested browser URL with authuser=0, and validated place text can be
searched without the district filter. Operators can paste an exact decimal
coordinate pair back into the form; Enter applies the candidate without saving
the call. Google passwords and browser sessions are not stored on the VPS.

The NEW CALL new-tab behavior, reviewed caller network recovery and operator GPS
comparison updates are also published. Static version: 20261005-google-pin.
Four public assets matched their reviewed hashes, the console references the
new version, the source ZIP remains blocked, and all nine public health/auth/
compatibility checks passed. Six UI suites passed 430 checks; local location
search passed 153 assertions and VPS packaging passed 15 tests.

Private file and actual-container recovery snapshots are retained in
`/opt/mapa-staging/google-pin-20261005-2318`. Targeted files were replaced
atomically in the existing API/queue/scheduler containers; the API's Octane
workers were gracefully reloaded. Containers and databases were not restarted.
Running container image IDs still identify the previous base, with this reviewed
file patch applied. The immutable image `mapa-vps-api:maps-20261005` includes the
patch and is selected by compose.override.yml for future recreation. Always
include that override: its authoritative final volumes and feature flags remain
unchanged. No operational call, assignment, caller location or provider message
was created for this verification. Independent backups and commissioning remain
separate pending work.

## Caller journey release - 5 October 2026 (India)

The owner authorized deploying the real caller-link flow after dry-run checks.
At approximately 22:28 UTC on 4 October, image `mapa-vps-api:caller-20261005`
was deployed to API, queue and scheduler. The authoritative final-volume
`compose.override.yml` remains included and now also selects that image and
enables caller tracking; SMS and WhatsApp remain disabled. Hospital handover and
hardware ingestion retain their prior disabled state. Database/cache services
were not restarted. The root website and DNS were not changed.

Five pending mobile migrations (000009–000013) passed a dry run and were applied.
A consistent private database dump, source files, original image ID and Compose
override are retained at `/opt/mapa-staging/caller-release-20261005/backup`.
This is a rollback artifact, not configured independent scheduled backup.

The production caller page and operator assets were copied from reviewed source;
no simulated GPS, fake map tiles or demo banners were deployed. Operator link
creation now includes Copy caller link, expiry and a manual-sharing explanation.
292 connected-backend checks, 356 crew/hospital checks, 116 caller behavior checks
and 50 operator logic checks passed locally. All nine public health/compatibility
checks passed; five published assets matched source bytes, caller/staff endpoints
rejected anonymous access, and the source ZIP remained blocked. The new public
track directory needed mode 755 to serve its files; this was corrected and the
asset checks rerun successfully. All nine VPS services were running afterward.

No operational call, assignment, location, caller link or provider message was
created during this deployment. The requested link for the owner's test phone
still requires an identified saved call; existing calls were not repurposed.
Real-phone/BR05 acceptance and provider commissioning remain pending.

## VPS cutover complete — 4 October 2026

The new `app.itda.asrhealth.in` hostname now serves the operational application on the purchased Hostinger VPS with valid HTTPS. The existing root website and mail DNS were preserved. The Pages compatibility relay now targets that same VPS (deployment `f8c61e89`); browser document routes redirect and Android/API paths proxy. Do not restore the old temporary tunnel.

The owner explicitly confirmed a safe pause. Four source writers stopped at 00:31 UTC; source database remained healthy. Fresh export `final-20261004T003110Z` (manifest SHA256 `ad2c13dbcf617f251521109393001f9dde165452a859c6aeeef8ed711f766327`) transferred 146 tables and 14 files. Exact counts, all 2,780 column definitions, equivalent schema and all file hashes matched. Six reviewed additive migrations passed and existing operational table counts were preserved. Final private and public authentication/WebSocket checks passed; verification tokens were revoked. Final volumes are `mapa-final-20261004t003110z-{mysql,uploads,redis}`; always include `compose.override.yml` in explicit Compose commands. **The VPS is authoritative. Never restart the old PC writers; rollback requires reconciliation.**

Prior staging reboot recovery and encrypted isolated backup restoration passed. Hostinger weekly backups were configured and an initial snapshot retained; independent scheduled off-server backups remain unconfigured. Fresh export is retained privately on the owner PC and VPS. A successful migration is not physical-phone/provider commissioning or a guarantee of emergency-service readiness.

September 30 mobile 1.11 deployment: both API worker sets were gracefully reloaded after isolated trip/push tests. The database was not restarted. Public health/auth/branding returned 200 and protected user/mobile reads returned 401. The new signed APK, ZIP and guide were byte-verified through the share link. Mobile password sign-in and trip capability were read-verified, then the verification session was revoked. Push remains unconfigured; no provider messages or operational calls were sent. Details: `../../mapa-android/RELEASE-1.11-VALIDATION.md`.

Stable share URL: https://mapa-asr-health.pages.dev/fleet-ops

## Current service

The operational app, database, uploads and workers run on the VPS. The PC and local Docker are no longer required to serve the application. The Codex heartbeat still needs Codex and this PC available; independent external monitoring remains pending.

The **Monitor ASR Health VPS availability** heartbeat (`keep-mapa-share-link-available`) checks canonical and compatibility access every 15 minutes, quietly while healthy. It must read `private/vps-deploy/cutover-state.json`, never revive PC writers or a temporary tunnel, and diagnose the authoritative VPS. It must not change accounts, passwords, operational data or paid hosting. Recovery may wait for the next scheduled check and tool authorization. All incident entries below predate this cutover unless explicitly stated otherwise.

## Incident resolved 2026-09-19

The native application and database were healthy. After an overnight connection loss, the temporary tunnel reported `Unauthorized: Tunnel not found`, its DNS name disappeared and the public relay returned Cloudflare error 1016. Restarting only `mapa-live-access-tunnel-1` created a fresh connector. The existing Pages project was redeployed to use that origin, retaining the public hostname.

Following repair, `/healthz`, `/auth` and `/api/int/v1/settings/branding` returned 200; anonymous `/api/int/v1/users/me` remained 401. No operational data or passwords were changed.

Restart policy `unless-stopped` was added to the live connector and the original socket and local console services. The API, gateway, access checker, database, cache and queue/scheduler already had it. Apply restart policy changes to running containers with `docker update --restart unless-stopped` rather than unnecessarily recreating healthy containers. A manually stopped container remains stopped according to that policy.

## Host recovery resolved 2026-09-29

Docker startup was restored by preserving the stale, zero-byte IPC socket directories as sibling backups and creating fresh runtime directories. The user reported clicking Docker's factory reset during diagnosis; the existing data disk and saved MAPA stack remained available afterward. No database replacement or application reprovisioning was needed.

The original ASR Health application and database recovered successfully. Only the failed public tunnel connector was recreated, and the existing Pages relay was updated to the validated origin `https://notes-disciplinary-pay-productions.trycloudflare.com`. The stable share URL is unchanged.

Public `/healthz`, `/auth` and anonymous `/api/int/v1/settings/branding` returned 200; anonymous `/api/int/v1/users/me` remained 401. The existing customer password and ASR Health membership were verified, along with authenticated reads of bootstrap, vehicles, drivers, places, orders and map settings. The verification session was revoked. No passwords or operational records were changed.

The existing 15-minute availability heartbeat was resumed at the user's request. Keep this PC, Docker, internet and Codex available; this remains PC-dependent hosting.

### Subsequent connector expiry recovered — 2026-09-29

The public relay returned HTTP 530 / Cloudflare 1016 while the local gateway and original application/database remained healthy. The connector reported `Unauthorized: Tunnel not found`. Only `mapa-live-access-tunnel-1` was restarted; its new registered origin is `https://basketball-divx-francis-calculator.trycloudflare.com`.

The PC's default resolver did not resolve the new temporary hostname. Its public A records were verified through 1.1.1.1, and the origin passed HTTPS health/auth/branding/protected-user checks using the resolved address with normal hostname and certificate validation retained. The reviewed relay was regenerated and deployed only to the existing `mapa-asr-health` Pages project (deployment `0d109134`).

After deployment, the stable public hostname returned 200 for health, auth and anonymous MAPA branding, and JSON 401 for anonymous current-user access. No database, application worker, account, password, operational record or access-control change was made. The same PC/Docker/internet availability limits still apply.

### Historical outage — 2026-09-20 00:45 IST (resolved above)

The 2026-09-19 19:13 UTC check found all four public checks returning HTTP 530 / Cloudflare 1016. Local port 8896 refused connections and the Docker Linux engine pipe was absent. Docker Desktop was stopped. One authorized `docker desktop start --timeout 45` attempt launched Desktop but the backend crashed while initializing its Ingest server: the local `sailor-ingest.sock` could not be renamed to `.stale` because Windows could not access the file. The backend reported all local engines stopped.

At that check the share link remained offline pending host recovery. No factory reset, data deletion, account change, application restart or relay redeployment was performed by the monitor. This blocker was resolved on September 29 as recorded above.

## Historical manual stop — 2026-09-29 17:33 UTC (resolved September 30)

The four public checks returned HTTP 530 / Cloudflare 1016. The local gateway on port 8896 was unavailable and the Docker Linux engine pipe was absent. Docker's host log recorded GUI compose stop actions at 17:20 UTC followed by `actionMenuQuit`; Docker Desktop and its backend were no longer running. This was a manual stop, not an unexpected connector failure, so the monitor did not undo it, restart services or redeploy the relay. The user must reopen Docker and start the existing MAPA services, or explicitly request their restoration. Do not repeat notifications while this same manually stopped condition is unchanged.

## Restored for the requested 25-phone rollout — 2026-09-30

The user's request to connect 25 real crew phones required restoring the existing service. Docker startup again failed on stale zero-byte IPC sockets. Only the stopped runtime socket directories were preserved as sibling backups and fresh empty directories created. Docker's existing database disk, volumes and saved containers survived; no factory reset or database reprovisioning was performed by the agent. Only the existing native ASR Health dependencies and MAPA live-access services were started. The unrelated preview and voice-demo stacks stayed stopped.

The new validated connector is `https://homeland-bryan-conference-prohibited.trycloudflare.com`. The existing `mapa-asr-health` Pages relay was republished as deployment `e5a14a99`; the public hostname is unchanged. Public health, auth and anonymous branding returned 200, while anonymous current-user and native mobile snapshot returned JSON 401. APK 1.10.0, guide and the crew installation ZIP returned 200 and matched the local files byte-for-byte. An existing crew password login and authenticated snapshot passed; logout succeeded and the revoked token returned 401. No new account, password, duty, GPS, call or assignment was created by these checks.

The real 25-person roster is pending. This restoration does not establish 25-phone capacity or continuous background GPS on every handset. The PC/Docker/internet dependencies still apply. The previous manually stopped condition is resolved; the monitor may continue its normal availability checks.

## Recovery procedure

1. Request public `/healthz` and `/auth`, plus anonymous branding and protected `/api/int/v1/users/me`. Expect 200, 200, 200 and 401. Do not treat a working static login shell alone as a healthy API.
2. If public access fails, check `http://127.0.0.1:8896/healthz`, the `mapa-live-access` containers, the original ASR Health dependencies, and recent connector logs. Do not restart a healthy database.
3. When a connector is confirmed expired or missing, restart only `mapa-live-access-tunnel-1`. Wait for its new `https://...trycloudflare.com` origin and a registered connection. Use the newest startup log, not an older URL from the same log file. Validate the exact HTTPS hostname before using it.
4. Run `python fleetbase/live-access/render-relay.py --upstream ACTUAL_HTTPS_ORIGIN`. Deploy only `fleetbase/live-access/pages` to the existing `mapa-asr-health` project, as documented in README.md. Never publish the private console export, environment files, credentials or database.
5. Repeat the four public checks. If they fail, report the observed blocker without claiming recovery. Stop repeated restarts when a persistent network, authorization or PC availability issue needs the user.

The installed tools are Docker at `%LOCALAPPDATA%/Programs/DockerDesktop/resources/bin/docker.exe` and Node/Python under `%USERPROFILE%/.cache/codex-runtimes/codex-primary-runtime/dependencies/`. Wrangler is `fleetbase/cloud/edge/node_modules/wrangler/bin/wrangler.js`; the authorized Cloudflare account ID is `e01cd9f1fa2f5d4b743bb1b819658e36`. Never read or print its OAuth token.

For dependable permanent availability, complete the existing always-on server migration and use a managed tunnel. Cloudflare describes temporary Quick Tunnels as development/testing infrastructure with no uptime guarantee: [official Quick Tunnel documentation](https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/do-more-with-tunnels/trycloudflare/).

## Crew self-registration API activation - 1 October 2026

The exact four-table mobile enrollment/evidence migration was applied after a private consistent database backup. Only the public API and original application services were recreated to load reviewed source and the deliberately enabled flags; the healthy database, cache, queue, scheduler, socket and connector remained running. The operator assets, signed 1.12.1/code16 APK and password-free guide/installation pack were published through the same existing export and Pages relay.

Public health, auth and MAPA branding returned 200. Anonymous user/mobile/staff/private-photo reads remained JSON 401. Registration now reaches validation (422 for intentionally invalid input) rather than 404. Existing staff password authentication and scoped new list/options/report reads passed; the verification session was revoked. The code tests used a separate disposable native database. Root did not create or approve an operational enrollment, change a password, dispatch a call or invent GPS. Real ambulance/base identity setup and handset acceptance remain pending. See ../../mapa-android/RELEASE-1.12-VALIDATION.md. Hosting remains PC/Docker/internet dependent.


## Connector recovery during ASR import - 1 October 2026

The existing connector lost its connection and returned `Unauthorized: Tunnel not found`; public access returned HTTP 530 / Cloudflare 1016 while the local application and database stayed healthy. Only the failed connector was restarted. Its validated replacement origin is `https://boulder-michigan-subject-trends.trycloudflare.com`; the existing Pages relay was republished as deployment `c2b62d85`. The public hostname is unchanged. Public health/auth/branding and authenticated staff reads passed; anonymous operational/private-photo reads remained protected. No database, cache or healthy native dependency was restarted for this repair. Hosting remains dependent on this PC, Docker and internet.

## Connector expiry recovered during final inventory QA - 1 October 2026

The temporary connector expired again at 23:38 UTC on September 30 and public requests returned 530/1016. Original application/database health and local gateway stayed healthy. Only the failed tunnel was restarted, and the validated replacement origin `https://create-result-surgeons-serves.trycloudflare.com` was published to the existing Pages project as deployment `516fe5b5`. Health/auth/branding returned 200 and anonymous operational APIs returned 401 after repair. No database restart was needed. This repeated expiry remains a production hosting limitation; the PC and temporary tunnel are not an always-on deployment.

## Recovery and physical device check — 1 October 2026, 06:20 UTC

The user confirmed a Docker factory reset. Startup failed on stale IPC sockets in the Docker run and docker-secrets-engine directories. Those exact runtime directories were preserved as timestamped siblings; the database disk and volumes were not deleted or restored. Existing MAPA containers restarted successfully and the existing database is healthy.

The validated replacement tunnel is https://instances-width-davidson-maryland.trycloudflare.com. The existing Pages relay deployment is 07456994. Public health, auth and branding passed; anonymous user/call/private crew reads remain denied. Authenticated crew review and real ambulance directory passed. All 94 vehicles remain present (3 Araku matches): 93 crew_unlinked and 1 gps_missing, with zero currently dispatch-eligible. Verification sessions were revoked.

The USB X300 Pro became available to ADB. Signed APK 1.12.1 was installed successfully over 1.12.0 without clearing app data. Its retained registration currently has a definite HTTP 409 existing-email conflict. No connection was discarded, identity approved, password changed or ambulance dispatched. Fresh handset GPS, ringing and trip completion are not yet accepted. Production Firebase configuration is absent; current APK uses duty-service polling. This remains PC-dependent hosting.


### Physical recovery acceptance, 1 October 12:12 IST
Signed 1.12.2/code17 installed on the connected X300 Pro. The owner account was preserved; the user supplied a distinct crew email and submitted fresh selfie/GPS. With explicit user confirmation, ambulance AP37CL5228 was added and the crew linked to existing AH Araku. Live GPS receipts verified. Fixed dispatch reader to use native spatial GPS (161 isolated checks/615 FK checks); after graceful API reload the public directory shows 95 vehicles and one eligible. No call dispatched. Sound retry requested three playbacks; human confirmation and trip completion still pending. Public APK hash matched. Source archive publication awaits explicit approval after automatic review rejection.

Source publication subsequently explicitly approved by the user. The 68-entry Android source archive was inspected for private files/driver emails, published and public SHA256 verified: fc5e278bb706209f96685a391668d16b8cd5b2e970c8d0acc8bfa171559bed87.

## Public file boundary hardening — 1 October 2026

The Legal popup no longer has a direct ZIP download button. Its Software notices link retains the applicable AGPL source offer; the reviewed console/Android corresponding-source archives remain intentionally accessible. No claim is made that browser-delivered JavaScript can be hidden or that these changes make the app immune to attacks.

The gateway explicitly disables directory indexes and denies development source maps, server scripts, key/certificate files, internal directories and unapproved archive/backup paths. Intended release packages and required source archives have explicit exceptions. Configuration validation passed before a graceful gateway reload; the database/API/crew duty services were not restarted. Seventeen public checks passed, including blocked debug-map/private/config/backup paths, protected anonymous user access and working APK/login routes. Evidence: security-boundary-verification-2026-10-01.json.

## Connector recovery — 1 October 2026, 14:11 IST

The availability monitor found public HTTP 530 while local health and original API/database services remained healthy. The connector logged network connection failures followed by Unauthorized: Tunnel not found. Only mapa-live-access-tunnel-1 was restarted. After one transient tunnel API timeout it registered https://trains-oklahoma-pulled-one.trycloudflare.com, which passed HTTPS health validation. Existing Pages project mapa-asr-health was redeployed as 5f833153. Public health/auth/branding now return 200 and anonymous users/me remains JSON 401. No operational data, accounts, passwords or healthy database services were changed. The PC/Docker/internet and temporary-tunnel hosting limitations remain.

## Connector recovery - 1 October 2026, 12:54 UTC

Public checks returned HTTP 530 while local health and original services remained healthy. Connector logs confirmed Unauthorized: Tunnel not found. Restarted only mapa-live-access-tunnel-1. The new https://lindsay-infrastructure-ryan-florida.trycloudflare.com origin registered successfully and passed HTTPS health validation using its public DNS-resolved IP while local DNS propagated. Regenerated the relay and deployed only existing mapa-asr-health Pages project as 4d417f99. Public health/auth/branding verified 200; anonymous users/me verified JSON 401. No operational data or accounts changed; no healthy database restart. PC/Docker/internet and temporary tunnel limitations remain.

## Connector recovery - 2 October 2026, 21:13 IST

Public HTTP 530 was traced to Unauthorized: Tunnel not found while local gateway and original database/API services were healthy. Restarted only the failed connector. The first replacement also expired following connection loss before deployment; it was not published. The newest https://functions-charger-wifi-calendars.trycloudflare.com origin passed HTTPS health validation. Regenerated the existing relay and deployed mapa-asr-health as ce07539c. Public health/auth/branding returned 200 and anonymous users/me JSON 401. No operational data, accounts, passwords or healthy database services changed. PC/Docker/internet and temporary-tunnel limitations remain.

## Connector recovery - 3 October 2026, 13:59 IST

Public HTTP 530 was traced to Unauthorized: Tunnel not found after connection failures. Local gateway and original database/API services remained healthy. Restarted only mapa-live-access-tunnel-1. New origin https://bullet-video-node-morrison.trycloudflare.com passed HTTPS health validation with its public DNS-resolved IP while local DNS propagated. Regenerated the relay and deployed only existing mapa-asr-health Pages project as 2fe310bc. Public health/auth/branding verified 200; anonymous users/me JSON 401. No operational data, accounts, passwords or healthy database services changed. PC/Docker/internet and temporary-tunnel limitations remain.

## Connector recovery - 3 October 2026, 15:38 IST

Public endpoints returned HTTP 530; connector logs showed repeated edge connection refused errors. Local gateway and original database/API services remained healthy. Restarted only mapa-live-access-tunnel-1. New origin https://atmosphere-shared-using-collectors.trycloudflare.com registered and passed HTTPS health validation. Regenerated the relay and deployed existing mapa-asr-health Pages project as 82a061ee. Public health/auth/branding verified 200 and anonymous users/me JSON 401. No operational data, accounts, passwords or healthy database services changed. PC/Docker/internet and temporary-tunnel limitations remain.

## Connector recovery - 3 October 2026, 22:02 IST

Public endpoints returned HTTP 530. Connector logs confirmed Unauthorized: Tunnel not found following edge connection failures; local gateway and original database/API services remained healthy. Restarted only mapa-live-access-tunnel-1. New origin https://alien-public-syndicate-kilometers.trycloudflare.com registered and passed HTTPS health validation using its public DNS-resolved address. Regenerated the existing relay and deployed mapa-asr-health as 62f75640. Public health/auth/branding verified 200; anonymous users/me JSON 401. No operational data, accounts, passwords or healthy database services changed. PC/Docker/internet and temporary-tunnel limitations remain.

## Docker recovery - 3 October 2026, 23:45 IST

Public endpoints returned 530 and Docker's Linux engine was absent. A startup attempt confirmed the stale sailor-ingest.sock failure; Docker interface logs also recorded a factory-reset action. The existing 16 GB data disk remained present. With Docker processes stopped, preserved only Docker/run and docker-secrets-engine runtime folders under timestamped sibling backups, recreated empty runtime folders and started Docker Desktop. Existing ASR Health and live-access containers recovered without database reprovisioning. New tunnel https://wed-trials-min-kelly.trycloudflare.com passed HTTPS health validation with its public DNS address. Published the existing Pages relay as 1deef804. Public health/auth/branding returned 200 and anonymous users/me remained JSON 401. No accounts, passwords, operational records or access controls were changed. PC/Docker/internet dependencies remain.


## Connector recovery - 4 October 2026, 00:55 IST

The replacement temporary connector expired again while local application/database stayed healthy. Logs confirmed Unauthorized: Tunnel not found. Restarted only mapa-live-access-tunnel-1 and validated https://cyber-assumptions-robinson-reno.trycloudflare.com using its public DNS address with normal TLS. Updated existing Pages relay as 260bd4db. Health/auth/branding returned 200 and anonymous users/me JSON 401. No operational records or access controls changed. Permanent hosting remains required for rollout; this PC/tunnel arrangement is not guaranteed uptime.

## Connector recovery - 3 October 2026, 21:03 UTC

Public endpoints returned HTTP 530 while the local gateway and original application/database services remained healthy. Connector logs confirmed Unauthorized: Tunnel not found. Restarted only mapa-live-access-tunnel-1. The new https://guild-screenshot-tub-silly.trycloudflare.com origin passed health validation with normal TLS using its public DNS address. Regenerated the existing relay and deployed only mapa-asr-health as 388f5d7f. Post-deployment public health/auth/branding returned 200 and anonymous users/me remained JSON 401. No accounts, operational data, passwords or access controls changed; no healthy database restart. PC/Docker/internet and temporary-tunnel limitations remain.

## Connector recovery - 4 October 2026, 00:10 UTC

Public access failed with Cloudflare 1016/530 while the local application and database remained healthy. The connector logged Unauthorized: Tunnel not found. Restarted only mapa-live-access-tunnel-1. The replacement https://shall-hunt-jade-rear.trycloudflare.com registered at 00:10:51 UTC and passed normal TLS health, auth, branding and anonymous authentication-boundary checks. Regenerated the existing relay and deployed only mapa-asr-health as 9045d90d. Public health/auth/branding returned 200 and anonymous users/me remained 401. The public MAPA-Crew-1.13.4.apk returned 200 and matched the local SHA256 7d6b79f81bf660ebf176724a444511bb6f1cc38aebfec6e10fac87efd8a87049. No operational records, passwords, accounts or access controls changed; no healthy database restart. The operational app still depends on this PC, Docker engine and internet. The permanent VPS is staged, with final coordinated cutover pending the owner's safe-pause confirmation.

## Final VPS service verification — 4 October 2026

All nine VPS services are running. API, database, cache, queue and scheduler health checks pass. Worker liveness probes were corrected from inherited web-server probes, without changing the database or API. Final canonical health/auth/branding and anonymous authentication protection passed. Independent QA also verified the rendered login screen, both-origin APK hashes, Pages browser redirects and the unchanged original root website. Public service is independent of local Docker; the Codex monitor is still PC-dependent.



## ASR Health presentation published — 5 October 2026, 00:29 UTC

The startup screen and web app logos now use ASR Health; MAPA remains the underlying software and the bottom-left MAPA.DEV attribution is retained. The guarded file-only overlay reported 12 applied presentation files, loading cache `830f4ed5d6f5`. It preserved the actual live console, initializer order and legal assets; no service restart, database/operational write, account change or DNS change was part of this branding deployment.

Independent public checks confirmed the ASR-only startup markup, ASR Health app manifest, exact source hashes for both new SVGs, compatibility SVG aliases and loading CSS/JS, plus the three native logo modules matching the prepared source. The attribution module SHA256 remained `ec2b8d4ea8d1b5dd98aa045c81bc29782c2461b5366530b0185aaa8bb551ceb9`. Canonical `/healthz`, `/auth` and anonymous branding returned 200; anonymous `/api/int/v1/users/me` remained JSON 401. Evidence: `../../private/asr-branding-20261005/public-branding-verification.json` and `live-verification.json`. Root's fresh Chrome Operations inspection also confirmed the ASR Health logo and retained bottom-left MAPA.DEV credit; screenshot evidence is `asr-health-operations-branding-live.png`. The application remains VPS-hosted independently of the PC; the Codex monitor still depends on the PC, Codex and internet.

## Navigation and crew rollout check — 5 October 2026, 01:36 UTC

Published compact Crew and Saved calls header buttons; both measure 32px high on
one line. Full statuses remain in accessible labels and tooltips. Static files
only; no database, container, runtime configuration or operational record changes.
VPS backup: /opt/mapa-staging/nav-alignment-20261005T013641Z.
114 native crew and 77 native intake checks passed. Live registration review
loaded under the existing admin with zero pending records and existing approvals.
Canonical health/auth/branding returned 200; anonymous users/me returned 401 JSON.
The published 1.13.4 APK SHA256 remains
7d6b79f81bf660ebf176724a444511bb6f1cc38aebfec6e10fac87efd8a87049.
Local 1.14.0 pilot validation explicitly says unpublished, matching backend not
deployed, Firebase unconfigured and physical device unverified. Do not substitute
it for the published pilot or describe fleet-wide rollout as accepted.
Registration notifications poll while the console is visible; no closed-browser
push guarantee. A real-phone registration/approval/duty test remains required.

9 October 2026 feed-continuity update: the authoritative VPS now refreshes signed ASR caller data every minute independently of an open Board and checks ASR caller/fleet freshness, Way4Track poller freshness and API health in a private one-minute systemd timer. Two consecutive caller refreshes succeeded, returning 30 active calls; the final private check was `ok` (GPS 7 seconds, ASR fleet 18 seconds, ASR callers 29 seconds old). Existing GPS and fleet timers remained active/enabled, and the temporary SFTP key was removed. This detects outages but does not guarantee the upstream provider remains online or renew an expired Way4Track session. See `private/feed-continuity-20261009/DEPLOYMENT_STATUS.md`.

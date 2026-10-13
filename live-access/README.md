8 October 2026 bottom GPS fleet update: all 32 Way4Track devices are now in the native Ambulances drawer with search/scroll, exact captured provider labels and permanent map labels. Native MAPA vehicle controls remain available via the records toggle. 62 UI and 17 publisher checks passed; live 32-row/32-label parity and scrolling verified. See private/all-devices-20261007/BOTTOM-LIST-RELEASE-REPORT.md. Provider display names are captured metadata; telemetry continues polling automatically.

8 October 2026 all-device GPS map: the authoritative VPS now displays all 32 Way4Track provider locations, including six explicitly unmapped devices and stale/offline last-known reports. Browser verification confirmed all 32 markers within the visible map area, plus explicit IST timestamps. Native fresh GPS ingestion and ASR operational independence remain unchanged. Final frontend hash b599184d879e5ba752aa6c3275f3b3974670301e4e2d97120b21c5ceafd2999c. See private/all-devices-20261007/RELEASE-REPORT.md for checks and evidence. This is not a claim that all devices are online.

7 October 2026 Way4Track GPS update: the VPS poller is active and fresh provider positions are verified in native vehicle projections. A private runtime-credential repair preserved the strict file checks, existing database, root credentials and authoritative volumes. ASR source dispatch remains independent; provider-reported GPS does not authorize native dispatch. At 15:47:16 UTC, five provider vehicles were projected, four fixes were fresh, and browser vehicle-list/marker updates were verified. This does not mean all 26 configured devices are online. Main-map vehicle coordinates also advanced during inspection and focused the correct marker. The embedded New Call desk remained at its session-loading message, so its quality-popup check and overall readiness were not established. Physical-device commissioning remains pending. See `private/way4track-connect-20261007/RELEASE-REPORT.md` and `RECOVERY.txt`.

7 October 2026 reference integration release: the updated New Call desk, matching native automatic-offer backend and signed Firebase crew APK **1.14.3/code 26** are published and verified on the authoritative VPS. Native MySQL QA passed 264 checks and automatic MySQL QA passed 30; public APK bytes match the signed local release. The automatic scheduler is configured every ten seconds. ASR source dispatch remains independent and cannot be controlled or reserved by native MAPA offers. See `private/reference-integration-20261007/RELEASE-REPORT.md` and `TEST-CHECKLIST.md` for evidence and limits. Browser QA remains in progress; real-phone notification/GPS/trip acceptance and physical BR05/provider commissioning remain pending. Earlier separate-tab and release notes below are historical.

Public application: **https://app.itda.asrhealth.in/fleet-ops**. Existing Pages links remain compatible.

5 October, 01:11 UTC routing update: private road routing is enabled with the
Geofabrik India Southern Zone dataset. Coverage is regional; missing coverage,
stale GPS or an unavailable engine yields no road geometry. The 19 private-engine
checks used synthetic in-memory fixtures; real-trip acceptance remains pending.
The separate `mapa-road-router-20261005` container is private-only, has 6 GiB
memory/2 CPUs and restarts unless stopped. Compose does not recreate it if deleted.
Keep `compose.override.yml` included for the authoritative volumes, private API
network and routing environment. Do not blindly clear or rebuild the current API
configuration cache: its original container environment predates activation.
See the latest AVAILABILITY entry and private enable receipts before changing
cache or router state. Earlier disabled-routing notes describe previous phases.

5 October presentation update: the loading screen and app logo now show **ASR
Health**. The underlying MAPA software identity, `MAPA.DEV` credit and legal
notices remain. Existing operator tabs need a normal reload after saving any
drafts to receive the new presentation assets.

5 October confirmed-pickup update: the caller explicitly confirms a fresh GPS
point through the private trip link. The operator then reviews and accepts that
point before an assigned trip's pickup changes; subsequent phone movement does
not move a confirmed pickup automatically. Crew review follows a changed pickup.
Before dispatch, applying the point updates only the unsaved review draft.
Road-route availability and its current verification status are recorded in
AVAILABILITY.md; a planned route is distinct from the reported GPS trail.

5 October Operations update: NEW CALL opens a separate tab from both native
create controls. **Saved calls** in Operations refreshes after confirmed saves
and every 10 seconds while visible. The Operations session stays independent.
See AVAILABILITY for validation and recovery details.

5 October pickup update: local place search is repaired on the VPS. The operator
desk retains **Search map** and manual pins, and adds **Open Google Maps** plus
coordinate import. The Google link uses each browser's own sign-in; Google
credentials are not stored on the server. **NEW CALL** now opens a separate tab.
Caller network recovery and operator GPS/manual comparison updates are published.
See the latest AVAILABILITY entry for verification and the current image selection.

5 October caller update: manual private full-journey links are enabled on the VPS.
In a saved, non-training call, confirm the caller's permission, select **Create
private trip link**, then **Copy caller link** to share it manually. The real
caller page has no preview fixtures; a valid call-bound token is required. SMS,
WhatsApp, hospital handover and hardware ingestion remain disabled pending their
commissioning. See the latest AVAILABILITY entry for deployment and test evidence.

### Permanent-host migration status — 4 October 2026

The final operational cutover to **https://app.itda.asrhealth.in** completed on 4 October 2026. Only the new `app.itda` DNS record was added; the existing `asrhealth.in` website, `www` and mail records were preserved. The VPS now serves the command centre with valid HTTPS.

The owner confirmed a safe pause. Fresh export `final-20261004T003110Z` restored 146 tables and 14 upload files into new VPS volumes, with exact counts, all 2,780 column definitions, schema equivalence and upload hashes verified. Six reviewed additive migrations passed; original operational table counts were preserved. Existing customer password sign-in, authenticated native reads/WebSocket and anonymous/private-file denials passed against the final target. The four PC writers remain intentionally stopped. **Never restart them or revert to the old database: the VPS is authoritative and rollback now requires reconciliation.** See `private/vps-deploy/cutover-state.json` and the deployment report.

This is the full native MAPA console connected to the transferred ASR Health database, uploads, queue, cache and socket service on the VPS. Sign in with an existing active ASR Health administrator account. Passwords were preserved. The former localhost application is no longer the operational system.

The earlier `mapa-client-preview.pages.dev` address remains an isolated fictional, read-only demo. Its demo login does not provide administrator access to this address. The active ASR Health application now excludes the reviewed DEMO fixtures; they were archived with recoverable history on 1 October 2026.

## Availability

**The operational application now runs on the Hostinger VPS, independently of this Windows PC and local Docker.** VPS Docker starts on boot and services use restart policies. The Codex monitor itself still depends on the PC, Codex and internet being available. This does not guarantee uptime.

The existing Pages relay now targets `https://app.itda.asrhealth.in` (deployment `f8c61e89`). Browser document routes redirect to the canonical host; Android/API requests remain proxied. Do not restore a temporary Cloudflare tunnel origin. Signed APK 1.13.4 and its guide/source are published on the VPS; saved phone origins remain compatible. Physical-phone acceptance, supplier commissioning and independent scheduled backups remain pending.

On 2026-09-19 the expired connector was recovered and periodic availability checks were enabled. See [AVAILABILITY.md](AVAILABILITY.md) for the recovery procedure and monitoring limits. Relevant containers now use `unless-stopped` restart policies; this does not start Docker itself or keep Windows awake.

## Crew registration and trip evidence - 1 October 2026

Android **1.12.1/code16** adds phone-submitted crew details, a fresh private selfie and GPS, explicit staff approval against actual native ambulance/base records, a retained revocable connection, private trip-end note/photo/GPS and repeating checked-call alerts. The existing operator desk has **Crew registration** and **Trip reports**. The four reviewed additive mobile tables and enrollment/evidence flags are now active in both API services. Native accounts, passwords, operational records and original dependency services were preserved.

The public registration endpoint now returns readable validation errors instead of the former 404. Definite 422/409 attempts do not auto-resubmit in 1.12.1; uncertain retries preserve their exact saved request. Existing-account or another phone's pending-email conflicts still require control review. A submitted form is not approval. Actual ambulance/base records and supervised phone acceptance are required before starting real duty. See ../../mapa-android/RELEASE-1.12-VALIDATION.md and /downloads/MAPA-Crew-Guide.html.

## Access and functionality

- September 30 launch scope: operators answer ASR Health's existing telephone number and manually enter **New Call** details. Manual intake, confirmed pickup selection and operator-confirmed crew dispatch work independently of Exotel provider activation. The same native order reaches the crew app and its supported trip workflow. Phone-number activation, speech capture and provider audio are optional future integrations. Active-duty polling alerts remain the current APK's delivery path and require handset acceptance; Firebase is not a prerequisite for this supervised manual-call trial.

- September 30 mobile 1.11 update: assigned crew can confirm the reviewed ASR single-destination trip through response start, transport and hospital handover. Native records and retry receipts are atomic, with current-assignment and session-bound GPS checks. Unsupported extended/proof workflows remain in the console. FCM registration/queue code is implemented but disabled pending the operator's Firebase configuration and physical delivery test. The hotline bridge still needs its verified incoming number, public audio endpoint and staffed fallback. See `../../mapa-android/LIVE-READINESS.md` and the updated crew guide.

- September 30 real-crew rollout: new Call Intake drafts default to real manual calls. Training creation is disabled in the reviewed voice configuration, regardless of older environment-file values. The operational call queue excludes training records; historical records and their labels are preserved. The real 25-person roster and verified ambulance/hospital identities are still required. This does not commission the hotline, push alerts or remaining mobile workflows.
- The native web console exposes the administrator's full menu and authenticated create/update/delete operations. No read-only demo filter is applied.
- The public web API requires native authentication and active ASR Health membership. It admits the original native administrator, plus the one explicitly provisioned customer UUID with the exact approved company Administrator role. Anonymous requests cannot read operational records or write them. Public registration is disabled.
- Native HTTP tokens stay subject to native role and permission checks. A bounded, short-lived, opaque socket cookie is issued after valid administrator authentication. WebSocket upgrades recheck the native credential; credentials are not embedded in public assets.
- Mobile and Call Intake endpoints retain their existing, separate company/role-bound authentication. Web administrator access does not invent or bypass a mobile assignment.
- Optional provider services still need their own setup. Exotel number verification and audio streaming have not been commissioned by publishing this link and are outside the selected manual-call launch workflow. Android device acceptance is still required. Saving a draft or receiving a notification does not acknowledge or dispatch a call.
- WebSocket acceptance verifies transport authentication and upgrade, not every notification or GPS workflow. This release does not replace the underlying socket server's channel authorization or certify emergency-service readiness.

## Customer test login

The user explicitly authorized a separate full operational customer account on 2026-09-19. Its email is `customer.admin@mapa.invalid`; the generated password is stored only in the private local `private/live-access/Customer-login.txt` handoff. It is not a real email-delivery address. Normal password login was tested through the public API and browser, including the operations map, native ambulance-call creation form and Call Intake.

The native account is `type=user`, with the existing company Administrator role and 621 policy permissions. Only its exact UUID is additionally allowed through the public gateway; other company administrators are not implicitly admitted. It can edit ASR Health operational data. Customer writes to user/company/security management routes are blocked because native company administrators do not consistently protect the original owner account from takeover. The original owner retains their existing native permissions. This customer is not a platform administrator.

`provision-customer-access.py --docker DOCKER_PATH --provision` creates this exact identity idempotently and password-tests it; `--verify-only` verifies the saved password without resetting it. `verify-customer-features.py DOCKER_PATH` checks 23 further read endpoints and the protected account-management boundary. Tests revoke only their own API tokens.

`enable-customer-call-intake.py --docker DOCKER_PATH --enable-call-intake` adds the explicit company-bound mobile `admin` binding for the same customer. It was enabled and tested with the same password. It does not enable real telephone intake, extraction, push notifications or road ETA; those capabilities remain disabled until commissioned.

Public branding now returns MAPA SVG paths. `fix-entry-branding.py` updates exact native entry templates, shared icons, labels and asset cache versions, including the customer portal notification copy. Required third-party notices and dependency namespaces remain intact. Generated credentials and recovery links are excluded from the source archive.

`fix-operations-navigation.py` preserves the native `fleet-ops` menu and permission identity beneath the MAPA Operations title, so company administrators can see the operations extension and shortcuts. Preparation applies this fix before branding computes the final asset cache versions.

## Requested platform Super Admin

The user subsequently requested installation-wide administration access. The separate `customer.superadmin@mapa.invalid` account has true native platform administrator privileges and active ASR Health membership. It can manage platform settings, organizations and users. Its generated password is stored only in `private/live-access/Super-admin-login.txt`; the original owner and company-only customer credentials are unchanged. Actual password sign-in and admin-only endpoints were verified through the public hostname and browser. The account's mobile and Call Intake Super Admin binding is explicitly allowlisted and remains scoped to ASR Health.

## Native caller contact

Native ambulance calls store the optional caller coordination number in `order.meta.mapa_caller_phone`. Indian ten-digit numbers normalize to `+91`; other international numbers require a leading country code. Invalid values receive JSON 422 before persistence. An explicit blank clears the number without restoring an earlier Call Intake callback; an absent field preserves the older callback as a fallback. Assigned crew receive the number as `dispatch_details.callback_number`, already displayed by APK 1.9.0. No phone call or SMS is triggered by saving this field.

The `OrderResource.php` and `CurrentJobResource.php` overrides retain callback metadata for native operator controllers and redact it from public, customer-portal and webhook resources. Both API stacks and the native background workers mount the reviewed overrides. The caller field does not change assignments, dispatch status or GPS data.

## Runtime layout

`compose.yml` creates the separate `mapa-live-access` API, access checker, gateway and tunnel. The API reuses the original database and uploads without a database clone. Reviewed mobile migrations add only their module-owned tables; the crew registration/evidence release adds four tables without altering native table schemas. Original credentials are loaded privately from `../asr/.env`. Public hostname settings are in `private/live-access/live.env` relative to the workspace root. Never publish either private directory or the original `.env`.

The gateway serves the native console copied to `private/live-access/console`. `prepare-console.py` applies the navigation fix, map attribution and truthful ASR Health home text. It does not rebuild or replace the original local console.

`overrides/SettingController.php` fixes a native map-settings error when the optional Google Maps key is null. The same one-line fix is present in the FleetOps source tree for a future image rebuild.

## Start, stop and recover

Run from the workspace root in PowerShell, after starting Docker and the original ASR Health stack:

```powershell
$mapaDocker = "$env:LOCALAPPDATA/Programs/DockerDesktop/resources/bin/docker.exe"
& $mapaDocker compose --env-file fleetbase/asr/.env --env-file private/live-access/live.env -f fleetbase/live-access/compose.yml up -d api access gateway
& $mapaDocker compose --env-file fleetbase/asr/.env --env-file private/live-access/live.env -f fleetbase/live-access/compose.yml --profile public up -d tunnel
```

Check the tunnel log for its current HTTPS `trycloudflare.com` origin. When it changes, render the relay with the actual origin, then deploy to the existing Pages project:

```powershell
python fleetbase/live-access/render-relay.py --upstream https://ACTUAL-CONNECTOR.trycloudflare.com
node fleetbase/cloud/edge/node_modules/wrangler/bin/wrangler.js pages deploy fleetbase/live-access/pages --project-name mapa-asr-health --branch main --commit-dirty=true
```

Wrangler requires the user's authorized Cloudflare account. Do not create a replacement project, publish private exports or commit access tokens.

To stop public access without stopping or deleting the original workspace:

```powershell
& $mapaDocker compose --env-file fleetbase/asr/.env --env-file private/live-access/live.env -f fleetbase/live-access/compose.yml --profile public stop
```

Do not use volume deletion commands. Public operations change the original data, so normal workspace backup and restore procedures still apply.

## Verification

```powershell
node --test fleetbase/live-access/access.test.mjs
python fleetbase/live-access/verify-live.py --base https://mapa-asr-health.pages.dev --docker $mapaDocker
```

The acceptance script temporarily creates a native, ten-minute administrator test token, performs HTTP reads, tests anonymous denials and an authenticated WebSocket upgrade, and submits an intentionally invalid order that fails required-field validation before persistence. It revokes exactly its own test token in `finally`. It does not print tokens, credentials or operational records, create orders, dispatch ambulances, or contact people.

Password sign-in must also be checked in the browser using the administrator's existing password. The acceptance token does not prove the password flow. See `VALIDATION.md` for observed results and remaining checks.

## Account recovery

This shared origin opens an existing workspace. Its login has no account-creation button, `/onboard` redirects to the HTTPS sign-in page, and blocked signup requests return readable JSON errors. The native reset-link validation endpoint is publicly reachable with rate limiting; it returns only the link identifier and validity. Private operational API endpoints still require authentication.

When the verified owner requests recovery, `prepare-admin-recovery.py --docker DOCKER_PATH --issue` prepares a native single-use link with a 15-minute expiry for the original ASR Health administrator. It expires earlier active recovery requests and writes the new link only to `private/live-access/Administrator-recovery.md`. It never selects or changes a password or sends a notification. The owner enters and submits the new password in the native page. Do not run it merely to test availability, and never include the generated private file in a source archive or public deployment.

`fix-account-recovery.py` adapts only the copied live console: removes the unavailable signup action, guards the onboarding route, scopes verification to password reset and avoids an unnecessary private branding request on the reset page. `prepare-console.py` reapplies it after a fresh export.

## Source and notices

The application's Legal dialog retains upstream notices and offers `/mapa-source.zip`. `package-source.py` adds only the reviewed source files for this deployment to that source archive. It excludes private environment files, databases, uploads, logs, tokens and customer data. MAPA branding does not transfer ownership of upstream components.

## Real ASR inventory and release audit - 1 October 2026

Imported 93 source ambulances (51 Special, 42 Feeder/Bike) and 42 source hospital/base records, including three researched missing facility pins. The existing real phone-linked ambulance/base were preserved, giving 94 active vehicles and 43 active places. PHC Nathavaram remains pending a confirmed pin because published locations conflict. Source vehicle locations remain SQL NULL and offline until actual crew GPS is received. The 151 source driver rows are stored privately for identity review, not activated with fabricated accounts or positions.

The operator desk has a database-driven area/registration/hospital/mandal search with unavailable reasons and unchanged explicit dispatch guards. DEMO records were soft archived after backup; only their bindings/sessions were disabled. Native nullable vehicle GPS and scoped mobile broadcasts were corrected; exact unknown(0,0) phone fixes are rejected. A duplicate floating New Call shortcut that blocked list pagination was removed where the native sidebar already provides it.

The signed Android release remains 1.12.1-pilot/code16; its public APK and refreshed password-free guide/install pack were verified. See MAPA-BUILD-REPORT-2026-10-01.html for the local owner build report, tests, hospital citations and concrete outstanding handset, crew linkage and permanent-hosting gates. The build report contains operational summary information and is not included in the public source archive.

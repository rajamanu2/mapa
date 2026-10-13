# MAPA Console Android

## Local candidate — 11 October 2026

**1.14.4-pilot / code 27** is signed for an in-place update with the same `in.asr.ambulance` package and certificate. It is staged locally at `outputs/release-1.14.4/ASR-Health-Crew-1.14.4.apk`; it has not been published or installed. Tapping a crew push hint now opens Home and fetches the latest offer, including when MAPA was left on Account or Updates. This fixes that notification path; a missing offer after a manual Home refresh still requires a server/session binding check. The prior 1.14.3 download remains unchanged. See `outputs/release-1.14.4/BUILD-VALIDATION.json` for the artifact hash and build checks.

## Current published pilot — 7 October 2026

**1.14.3-pilot / code 26** and its matching backend are published on the authoritative VPS at `https://app.itda.asrhealth.in`. [Download the signed Crew APK](https://app.itda.asrhealth.in/downloads/ASR-Health-Crew-1.14.3.apk). Public bytes match SHA256 `686222d793d932e712d2380bb575c67419a07288a4f30ef56c15b9704f2bfda3`. Install in place without uninstalling or clearing app data; the existing package and signing certificate are retained.

This Firebase-enabled build adds the current offer countdown, estimated road travel, operational decline reasons and server-authorized overdue responses. The server confirms the winning assignment. Current native acknowledgement, arrival and trip-completion checks remain enforced. ASR-owned trips remain independent and tracking-only in this native dispatch workflow. Withdrawal after acceptance and closure-delay reason submission are not new supported actions.

Android verification: **380 native checks, 32 reliability checks, release build/lint and signature checks passed**. Physical-phone push delivery, background GPS, weak-network recovery and complete-trip acceptance remain pending; build success is not commissioning. See `outputs/release-1.14.3/BUILD-VALIDATION.json` and `../private/reference-integration-20261007/TEST-CHECKLIST.md`. Older version-specific sections below describe historical implementation and do not override this current release status.

Version **1.12.1 pilot / code 16** connects to the chosen MAPA console's own Laravel backend and native company, users, drivers, vehicles, orders and places. It does not connect to the former separate command/review application. Original application additions Â© 2026 MAPA.DEV; bundled third-party notices remain intact.

## Crew registration and evidence update (1.12)

The registration-enabled release adds camera self-registration with actual crew contact, ambulance and hospital/base details; explicit command-center identity/vehicle approval; a private, bounded and revocable device connection; trip-end note/photo/current-session GPS evidence; and repeating checked-call alerts. Existing assigned email/password login remains a compatibility option. It does not reset or overwrite the numbered accounts.

Command center uses the **Crew registration** and **Trip reports** tabs in the existing operator desk. Registration is pending until authorized staff select a real matching native ambulance and hospital/base and confirm identity. No vehicle/base is fabricated or silently reassigned. Registration coordinates are labeled historical device reports; fresh duty GPS requires an explicit start and server receipts.

The additive mobile migration `2026_10_01_000004_create_mapa_crew_enrollment_tables.php` and two deliberately enabled deployment flags are required: `MAPA_MOBILE_ENROLLMENT_ENABLED` and `MAPA_MOBILE_COMPLETION_EVIDENCE_ENABLED`. Camera JSON bodies are bounded; images are decoded/reencoded as JPEG to remove metadata and stored outside public uploads. Authenticated private media access revalidates company and role/crew scope. Existing emergency/provider and MFA controls remain in force.

Completion uses the same immutable order activity receipt. It needs a note, new camera photo and fresh accurate accepted non-mock GPS for the current acknowledged call. A committed receipt can replay even after capture expiry. An uncommitted stale capture is definitively rejected, requiring explicit recapture; an uncertain action is never silently replaced. Ending duty remains a separate confirmed action after no active trip remains.

The checked-call alert repeats the permitted notification sound every eight seconds while MAPA or the duty service is active. Opening a call silences its alert without acknowledging it. A local 30-second test with a stop button checks the handset's configured alert behavior without dispatch or API writes. Android permission/channel settings, silent/DND, network and service restrictions still govern sound. Firebase cold-app remote push is unconfigured.

Use [SELF-REGISTRATION-GUIDE.html](SELF-REGISTRATION-GUIDE.html) and the independent `../mapa-crew-qa/ACCEPTANCE.md` checklist. The exact build, source/test results and physical-device acceptance must be recorded separately; this section describes the implementation and required cutover rather than certifying all 25 devices.

## Install and connect

1. Install the signed `outputs/release-1.14.3/ASR-Health-Crew-1.14.3.apk` on an authorized Android 8+ test phone. The existing package/signing identity allows compatible pilot upgrades.
2. Use the canonical **https://app.itda.asrhealth.in** server. Previously selected native servers are preserved, so check the Server address after updating. Existing Pages links remain compatibility links. A new login is required when you deliberately change the server; credentials and old telemetry never migrate silently.
3. New crew use Register / connect to ASR Health, submit real driver, ambulance and hospital/base details with a fresh selfie and GPS, and wait for command-center approval. Existing assigned users may still sign in with their native email/password. The server provides the approved role and bindings.
4. Crew require a native Driver linked to that User and a native Vehicle. Start duty explicitly, grant location and notification permissions, and verify the same vehicle's received position in the existing console.

`localhost` on a phone is the phone itself. The local Windows console page at port 4200 and API at port 8000 need a trusted HTTPS deployment reachable by the phone. Do not type an old review-site address, plain HTTP LAN address, or phone localhost. The app does not accept certificate-validation bypasses.

## What this build does

- Native email/password login obtains an opaque, expiring, revocable phone session. No organization API key, broad Sanctum credential, or staff password is embedded or saved.
- Crew see server-scoped native order IDs, pickup/drop-off and current status. Acknowledge explicitly only when `can_acknowledge` is the Boolean `true`; missing or false flags hide that action. Supported ASR calls expose server-advertised next steps after acceptance: response start, transport and hospital handover. Completion requires a separate receiving-team handover confirmation, a trip-end note, a fresh camera photograph and a fresh accepted GPS report. Other workflows remain with control.
- Foreground GPS requests explicit sharing consent and sends phone-reported coordinates, device-fix time, accuracy and speed in metres per second to the native driver/vehicle backend. Disabled or stale GPS is not manufactured. Position freshness is not inferred from an unrelated update time. One encrypted GPS intent retains its exact key and payload through an uncertain response or process restart until a receipt or definite rejection; resolved timestamps are skipped. The receipt state is bound to the origin, company, user, driver, vehicle and phone-session digest. Sign-out, explicit stop and lost authorization clear it; changed scope cannot send old telemetry.
- Online duty changes require a server receipt. An active order may prevent going offline. Sign-out stops local tracking but does not pretend the server completed a trip or ended operational duty.
- Native order actions use an encrypted durable outbox with immutable action IDs and expected revision. Network uncertainty stays pending; conflicts require review. Company, user, driver, vehicle and server boundaries prevent replay into another account or deployment. Earlier assignment actions remain held; the screen shows a neutral count without revealing their order details or sending them under the current binding. Duty changes are not queued.
- Hospital accounts show only the server-provided place binding. Administrator and super administrator accounts see only the native records and read capabilities returned for them. They do not impersonate crew or start crew GPS.
- The map shows the phone fix when available, or a clearly marked stored vehicle position with unknown fix time. In-app navigation uses validated native pickup coordinates and labels unavailable routes honestly. The pickup pin and operator-confirmed handover details help crew find the agreed access point. No phone geocoding silently replaces a confirmed pin.
- Crew can open **Travel record** to inspect the server-received observations for their current driver and ambulance: device-fix time, server receipt time, accuracy, coordinates and DEMO label. Only the latest 200 points within 24 hours are shown, with an explicit truncation notice. Lines break across gaps over two minutes and are not calculated road routes. Device observations are evidence for review, not proof of who travelled, a completed journey, distance or attendance. Only observations received after the server history upgrade are available.
- Android locations flagged by the OS as mock are withheld and labeled. Normal observations send `mock_location:false`; this signal alone is not tamper-proof. No GPS starts silently after installation, reboot or update.

## Unsupported features remain unavailable

The reviewed single-destination ASR trip is supported. Private registration and trip-end photographs are supported when the reviewed deployment flags are enabled. Hospital acceptance writes, general administrative writes, multi-stop workflow editing, crew readiness and transfer forms remain unavailable in the Android client. The client does not copy the former backend's independent dispatch database or clinical workflow into the console.

Trip updates atomically persist native order, tracking and immutable receipts without pre-commit external effects. Custom rules, proof, multi-stop, return, manifest and financial workflows require the console. Completion webhooks, automatic reallocation and outgoing completion messages are not invoked. The app never invents trip stages or bypasses those requirements.

Alerts use polling while the app is open or its on-duty foreground service is running. **The published 1.14.3 build includes Firebase messaging; actual phone delivery remains unverified.** Android notification permission/channel settings, network access, session validity, screen lock, force-stop and manufacturer battery policies affect delivery. Reboot/update reminders ask crew to reopen and explicitly restart; they do not restart GPS silently.

## Native API contract

Base: `/mapa/mobile/v1`; contract identifier: `mapa-native-mobile-v1`.

| Endpoint | Request / result |
| --- | --- |
| `POST /login` | `{email,password,device_name}` -> opaque `token`, `expires_at` epoch milliseconds, native `user`, capabilities |
| `GET /snapshot` | user, capabilities, own driver/vehicle or null, visible native orders with revision, Boolean can_acknowledge and supported next_activities after acceptance, places, server_time |
| `GET /capabilities` | contract, user, server-advertised capabilities |
| `POST /duty` | `{action_id,online}`; explicit requested state |
| `POST /gps` | `{action_id,latitude,longitude,recorded_at,accuracy_m,heading?,speed_mps?,mock_location:false}` |
| `POST /orders/{native_order_id}/ack` | `{action_id,expected_revision}` |
| `POST /orders/{native_order_id}/activity` | `{action_id,expected_revision,activity_code,confirmed:true,handover_confirmed?:true}`; supported configured next step only |
| `POST /logout` | `{}`; revoke this phone session |

Successful mutations return `ok:true` and may include `replayed` and `revision`. Refresh the native snapshot after confirmation. Retry the same saved body/key. HTTP 409 and permanent failures require review; retries cannot silently rebase onto another revision.

Native users include `id`, `name`, `role`, `company_id`, `driver_id`, `vehicle_id` and `place_id`. Supported roles are `crew`, `hospital`, `admin` and `superadmin`. Unknown roles or incompatible contract IDs fail closed. GPS requires the crew/driver/vehicle binding and both `duty` and `gps` capabilities. Snapshot `travel_evidence.points` carries the current driver/vehicle-scoped received observations (`latitude`, `longitude`, `recorded_at`, `received_at`, `accuracy_m`, `source`, `mock_location`, `simulated`, `driver_id`, `vehicle_id`); `truncated` indicates the limit. Older servers without this optional field show no history. Orders may include the reviewed `dispatch_details` subset: reported problem, caller-reported consciousness, breathing and scene safety, callback number, landmark, village, mandal, access directions, patient count, priority and ambulance requirement. The three condition fields are explicitly labeled as caller reports; unknown values stay unknown. Raw transcripts and arbitrary patient fields are not rendered.

## Build and tests

Run `test-native.ps1`, `test-reliability.ps1`, then `build.ps1`. The toolchain uses the existing JDK, Android 35 platform, AAPT2, D8, zipalign and apksigner under `../tmp/android-tools`, or the override parameters documented in `build.ps1`. `test-native.ps1` needs the test-only JSON jar described with its URL/hash in `tests/lib/README.txt`; it is not an APK dependency or part of the source bundle.

The build explicitly includes only native connection classes and shared map/storage utilities. It clears the verified build-class directory before compilation, so old compiled command-mode classes cannot enter the APK. Manifest launchers point to `NativeMainActivity`, `NativeDutyService` and `NativeRecoveryReceiver`.

`package-source.ps1` creates `outputs/MAPA-Console-Android-Source.zip` with the active source, map resources, notices, build/test scripts and guides. It excludes old command-mode source, outputs, build caches, SDKs, signing keys, credentials and binary test dependencies. Older source remains locally preserved in `history/command-1.6`; it is not an application mode in the new APK.

The development signing key remains outside the bundle. The public pilot key password is not production key protection. An organization-owned release signing/update strategy is required before a paid rollout. Package `in.asr.ambulance` is retained to support existing installations; new native session and outbox storage are separate from the previous connection.

## Validation scope

Automated contract, path/origin, capability, role/binding and action-queue checks plus compilation/package/signature checks are recorded by this task. These checks do not prove installation or a real phone-to-server connection. The shared HTTPS origin is configured. The latest ADB check found no attached debugging phone. Earlier handset checks and newer server observations are recorded separately; they do not establish current handset acceptance. Actual installation and handset results are recorded separately in outputs/BUILD-VALIDATION.json and RELEASE-1.12-VALIDATION.md. Compilation alone does not prove locked-screen GPS or actual phone-to-web synchronization.

Use [FINAL-ANDROID-BUILD-REPORT-2026-10-01.md](FINAL-ANDROID-BUILD-REPORT-2026-10-01.md) for the latest signed artifact and executed audit, and [LIVE-READINESS.md](LIVE-READINESS.md) for rollout blockers and [NATIVE-ACCEPTANCE.md](NATIVE-ACCEPTANCE.md) for isolated technical fault checks. Do not insert fictional incidents into the real operations workspace. The full workflow remains subject to the native backend's tested, advertised capability set.
## Crew interface in 1.9

English is the default; the top-right Telugu option is persisted on this phone. The current accepted call is shown first, followed by calls ready for acceptance and then calls awaiting dispatch. Native names, addresses, call IDs and activity codes are preserved. The phone provides large controls and fixed Home / Updates / Account navigation. Hospital and administrator screens remain read-only under the current capability contract.

Duty and phone GPS are separate indicators. A running service alone never implies successful transmission: the display also requires enabled phone location, an accurate fresh device fix, and a recent server receipt. Held updates from previous assignments show a count without exposing their order details.

The server enables only the reviewed atomic ASR trip adapter; unsupported workflow shapes fail closed. Acceptance, duty and GPS remain subject to the backend contract. Accounts requiring MFA or a password reset must be rejected by this release rather than silently bypassing those requirements.

Location sharing starts only from an explicit action while the activity is resumed; returning from a slow network request while the app is backgrounded does not start GPS. The receiver posts a reminder rather than starting the service. Relevant official Android guidance: https://developer.android.com/develop/background-work/services/fgs/service-types#location and https://developer.android.com/develop/background-work/services/fgs/restrictions-bg-start . This code compiles against Android API 35 and requires Android 8 or later.

Builds in this workspace can use external toolchain paths via `build.ps1 -JavaDirectory ... -BuildToolsDirectory ... -PlatformDirectory ... -KeyStorePath ...`; tests accept `-JavaDirectory`. Signing material is read externally and never enters the source bundle. No emulator, physical phone, live Android-to-server sign-in or Telugu device layout is certified by unit tests.
## Historical 1.11 delivery evidence

Built and signed with the existing certificate on 30 September 2026; 268 Android assertions passed. The public APK and guide were byte-verified. Optional FCM code compiled with the actual Firebase SDK, but no operator Firebase configuration or physical push acceptance exists yet. No phone was detected for this update. See `outputs/BUILD-VALIDATION.json`, `fcm/BUILD.txt` and `../fleetbase/asr/mobile/PUSH-SETUP.txt`.

## New-screen language scope

Registration, consent, trip-photo and local sound-test screens currently use English. Existing Telugu translations and tests do not certify these new forms as localized.

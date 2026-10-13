# MAPA Android final build audit - 1 October 2026

The installable signed release is **MAPA 1.12.1-pilot, version code 16**, Android package `in.asr.ambulance`. It requires Android 8 or later (minimum API 26), targets API 35 and connects by default to `https://mapa-asr-health.pages.dev`.

- Local APK: `outputs/MAPA-Console-Android-pilot.apk`
- Published download: https://mapa-asr-health.pages.dev/downloads/MAPA-Crew-1.12.1.apk
- APK size: 140453 bytes. This is the complete dependency-free native Android client; screens, camera, GPS, notifications and encrypted local state use Android APIs. Shared records and maps are fetched from MAPA rather than bundled as fictional offline data.
- SHA256: `9f2317ccf449f5aa651b95df752f8490128ef3893cdec881094b20fbc5e6febc`
- Signing certificate SHA256: `6fa24fd0e67495eac8023c36ea2d2ee64faf6b5f040fd63c10f38e09048a4fea`; v2 and v3 signatures verified, same pilot signer for compatible updates preserving app data.
- Source hash: `8f3c3886791be06a66e0c664d861e92037f44e60a554e64a41bdd6307ec043ff`. Source still matches the recorded signed release; no runtime Android code was changed in this audit.

## Verification performed now

The build-task suites passed 321 assertions. The independent Android suite passed 331 assertions including push policy, complete API 35 source compilation and stable source hashes. These checks cover native contract/scope, presentation, routes, immutable action and GPS queues, enrollment rejection recovery, private trip evidence and checked-call alert rules. The existing APK's package, version, minimum/target SDK and signing certificate were separately reverified. No replacement signing key or reinstall that clears app data was used.

ADB responded but detected **zero attached debugging phones**. This audit did not install an update, operate a phone, capture a selfie, start GPS, ring a phone, acknowledge a call or complete a trip. Earlier handset evidence was for version 1.12.0/code 15; it does not prove every phone has installed 1.12.1.

A read-only native database check confirmed enrollment and trip-evidence features enabled, one approved real registration and one enabled corresponding crew binding. Its last accepted GPS fix was 1555 seconds old at 23:50:14 UTC on 30 September (05:20:14 IST on 1 October), so it was not fresh under the 120-second limit. No real trip-completion report was present. These are time-specific server observations rather than physical handset acceptance.

Root's exact reviewed DEMO retirement was separately verified by 21 read-only assertions. DEMO vehicles/drivers/orders/places and their selected history remain recoverable through soft deletion; four DEMO phone bindings are disabled and their session history is revoked. The actual approved phone registration, binding and private selfie remain active, along with all 93 imported ASR ambulances and 39 hospitals/bases. These imported resources do not create driver identities or live GPS automatically.

The initial public APK fetch returned HTTP530. Root repaired the expired temporary connector and redeployed only the existing Pages relay. A subsequent fetch returned HTTP200 and exactly matched the signed APK SHA256 and 140453-byte size. Public health/auth/branding and protected authentication boundaries were verified separately by root.

## Delivered synchronization

1. A new crew member submits their actual contact, ambulance registration/name and hospital/base, explicit consent, fresh camera selfie and real accurate non-mock GPS. The encrypted request keeps its exact action and payload through uncertain responses.
2. Authorized command-center staff review the private selfie/details, select matching actual native ambulance/base records and explicitly approve the identity. The phone then obtains a scoped connection; it cannot claim another existing account or vehicle without review.
3. Crew explicitly start duty and location sharing. Server receipts update the same native driver, ambulance, positions and received travel evidence that the command center reads. A running service or stored vehicle coordinate alone does not prove current GPS.
4. Operator-entered calls require confirmed pickup and explicit dispatch. The linked phone polls for its actual assigned call while MAPA or the foreground duty service is active. Repeated permitted sound continues until the call is checked. Checking stops sound without accepting the call; acknowledgement is separate.
5. After acknowledgement, the supported single-destination ASR workflow exposes response, transport and handover steps. Completion requires a note, fresh private camera photograph, explicit handover confirmation and fresh accepted current-session GPS. Exact committed retries return the original receipt; definite stale-evidence rejection requires explicit recapture. Ending duty is a separate confirmed action after active work is resolved.

## Remaining operational acceptance

- The public APK link is restored and byte-verified. Reopen MAPA and explicitly resume sharing on the real phone. Confirm a fresh accepted GPS receipt and web marker, not only an online label.
- Verify the installed version, live selfie camera/permissions, correct actual crew/ambulance/base mapping, precise GPS, screen-lock behavior, network recovery and process restart on each intended phone.
- Verify permitted audible alerts using the bounded local test and an authorized real assignment, then explicit acknowledgement and all trip stages, private end-photo/report receipt and separate duty-off. Human observation is required to claim the phone actually rang. No fictional incident should be inserted into operational records for these tests.
- Run supervised acceptance in waves of 3, 10 and 25 phones. Isolated tests do not establish 25-phone load, battery endurance or field reliability.
- Firebase cold-app remote push is unconfigured. This APK relies on open-app/foreground-duty polling. Silent/DND/channel/permission/OEM restrictions are respected; force-stop can stop delivery. Reboot/update requires reopening and explicitly resuming location sharing.
- New registration, consent, trip-photo and local-alert forms currently use English; existing Telugu tests cover older UI strings only.
- The public site still depends on this PC, Docker, internet and the temporary connector. Always-on hosting, backup/restore acceptance and operational approval remain separate commissioning work.

Evidence: `outputs/BUILD-VALIDATION.json`, `../mapa-crew-qa/results/android-final-2026-10-01/latest-results.json`, and private read-only server receipt `../private/android-release/android-live-read-only-2026-10-01.json`. Private receipts, identities, media, keys and credentials must not be included in a public source or installer pack.

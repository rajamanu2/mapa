# MAPA ASR Health crew release 1.12.1 / code 16

Verified: 2026-09-30T21:09:58.212890+00:00

## Delivered implementation

- Driver-submitted registration with real contact, ambulance and hospital/base details, consent, fresh camera selfie and accurate non-mock device GPS.
- Staff review of pending registration, private selfie, actual native ambulance/base selection and explicit identity approval. No arbitrary assignment takeover or password reset.
- Scoped, encrypted and revocable device connection; existing accounts remain intact.
- Trip-end note, fresh private photo and accepted current-session GPS committed atomically with native completion and exact retry receipt. Duty-off remains a separate confirmed action.
- Repeating checked-call alerts and a separate bounded local sound test. Checking does not accept a call.
- Contact-preserving explicit recapture after definite stale-evidence rejection. Version 1.12.1 stops repeated automatic submissions after 422/409 and terminal enrollment states.

## Executed verification

- Independent final Android QA: 331 assertions, complete Android API 35 source compilation and stable source hashes.
- Android build task: 321 assertions, same-signing-certificate verification, package in.asr.ambulance, min SDK 26 / target 35.
- Web: 53 independent boundary tests and 69 administrator behavior checks; existing operator suite 138 checks passed.
- PHP: 121 pure checks and 21 module plus three migration syntax checks.
- Dedicated fresh MySQL/native API fixture database: 196 integration checks passed. Its tagged temporary database/network were removed; no production connection was used by this harness.
- Private media rollback tests cover request isolation, committed-photo preservation and unknown commit state.
- Production public verification: health/auth/MAPA branding 200; anonymous current user, mobile snapshot, staff enrollment/options/reports, own-enrollment status, connect and private-media reads denied with JSON 401. Invalid registration body over 16 KB rejected with JSON 422 before persistence. An unauthenticated camera-sized activity with a valid action key is denied with 401.
- Existing staff password login and scoped enrollment/options/trip-report reads passed. Unknown private media returns 404. Only the verification session was logged out and confirmed revoked. No acceptance account, registration, duty, GPS or ambulance call was created by root's verifier.
- Public APK download byte-verified against the signed build: SHA256 9f2317ccf449f5aa651b95df752f8490128ef3893cdec881094b20fbc5e6febc; 140453 bytes.

## Deployment

A consistent private database backup preceded the exact additive migration. Four mobile enrollment/media/report/audit tables were added; native tables and operational data were not reset. Only the existing public API and original application services were recreated to load the deliberately enabled enrollment/evidence flags and reviewed routes. The database, cache, queue, scheduler, socket and healthy tunnel were not restarted. Reviewed operator assets, APK, guide and password-free installation pack were copied to the existing public export. The Pages project and public hostname remain unchanged.

## Historical handset evidence and current follow-up

The earlier V2514 handset check confirmed MAPA 1.12.0/code 15 and the registration button. A second phone submitted a registration before matching native resources were available. This was the state at the original release check, rather than the current commissioning status.

The 1 October follow-up reverified the unchanged signed 1.12.1/code 16 APK, 331 independent Android assertions, complete API 35 compilation and stable source hashes. The public APK initially returned 530; root repaired the expired connector and the next public fetch returned 200 with exactly matching signed APK bytes. ADB now responds but sees no attached debugging phone, so latest handset installation and physical camera/audio/trip acceptance remain unverified.

The read-only native check now shows one approved non-DEMO crew registration and enabled binding. Its last accepted fix was 1555 seconds old at 23:50:14 UTC on 30 September (05:20:14 IST on 1 October), so it was not a current fresh GPS report. No real trip report was present. Root imported 93 actual ambulances and 39 hospital/base records separately; these source records do not create drivers or manufacture live GPS.

See FINAL-ANDROID-BUILD-REPORT-2026-10-01.md and outputs/BUILD-VALIDATION.json for current artifact, test and field-acceptance boundaries. No new real call, account, registration, approval, duty or GPS action was performed by this Android audit.

## Remaining field acceptance

- Identify each phone's email; resolve any existing account/pending phone link without account takeover or shared credentials.
- Supply and verify actual ambulance registrations and hospitals/bases, then review identity and approve the intended crew connections.
- Install 1.12.1 and verify real camera permissions/capture, precise GPS receipts, correct vehicle mapping, audible and locked-screen alerts, safe network/restart recovery, acknowledgement, trip-end evidence and separate duty-off.
- Run the checklist in waves of 3, 10 and 25 devices. This release does not prove 25-device load or endurance.
- Firebase cold-app remote push remains unconfigured. Polling alerts require the app or explicitly started duty service; Android silent/DND/channel/battery restrictions still apply.
- New registration/consent/photo/sound-test forms currently use English.
- The backend still depends on this PC, Docker, internet and the temporary connector. Always-on server migration has not been commissioned.

Independent evidence: ../mapa-crew-qa/results/RELEASE-STATUS.md and ACCEPTANCE.md. An hourly QA heartbeat monitors changed source through isolated checks and stays quiet for unchanged pending conditions.

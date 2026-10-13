# ASR Health deployment status — 4 October 2026

**Public VPS cutover is complete.** Open https://app.itda.asrhealth.in/fleet-ops. The existing https://mapa-asr-health.pages.dev address now routes to that same VPS: browser screens redirect, while Android/API calls remain proxied. The app no longer needs this PC or its Docker engine to stay on.

Only the new `app.itda` DNS record was added. The existing `asrhealth.in` website, `www`, nameservers and email records were preserved.

## Completed and verified

- Hostinger VPS Docker deployment with private database, cache, API and sockets; only HTTPS and SSH are public.
- Initial database/upload restore and six reviewed additive migrations in private staging.
- Existing customer administrator password sign-in and company scope, native authenticated reads and WebSocket upgrade. Exact verification sessions revoked; passwords unchanged.
- Anonymous operational access and private-file access denied.
- Valid certificate for the new hostname; staged services recovered automatically after a real VPS reboot and private acceptance passed again.
- Hostinger weekly backup schedule verified; initial provider snapshot created, expiring 5 October. No provider restore or daily backup upgrade performed.
- Separate AES-256 encrypted manual backup retained on the owner PC and restored from its returned copy in isolated volumes. Exact counts for 157 tables and hashes for 12 upload files matched. Schema equivalence and all 2,888 column definitions matched. The only raw schema-text difference was redundant UTF8MB4 charset notation preceding the same collation. Rehearsal containers stopped, artifacts retained privately.
- Signed Android 1.13.4-pilot/code 22 published on both the canonical host and compatibility relay, with 330 native assertions passing and the existing signer preserved. Its saved/default Pages origin reaches the same VPS. APK: https://app.itda.asrhealth.in/downloads/MAPA-Crew-1.13.4.apk (144,549 bytes; SHA256 `7d6b79f81bf660ebf176724a444511bb6f1cc38aebfec6e10fac87efd8a87049`). Guide and reviewed source match their published checksums.
- Pages deployment `f8c61e89` uses the permanent origin. Independent live QA verified HTML redirects, API responses without redirects, protected user/mobile APIs, denied private files and APK hashes on both origins. Canonical login screen was visually checked in Chrome.

## Final transfer and acceptance

The owner explicitly confirmed a safe pause. The four PC source writers were gracefully stopped at approximately **06:01 IST on 4 October**; the healthy source database was retained running. Empty queue work was verified before the stop. Existing calls and driver-duty records were preserved, not closed or reset.

Fresh export `final-20261004T003110Z` contained **146 tables and 14 upload files**. Source and transferred checksums matched. Restore used new volumes `mapa-final-20261004t003110z-mysql`, `mapa-final-20261004t003110z-uploads`, and `mapa-final-20261004t003110z-redis`. Original staging and PC data were preserved. Exact table counts, all 2,780 column definitions and all upload hashes matched. The schema gate caught MySQL's redundant UTF8MB4 declarations; narrowly tested normalization plus independent exact column metadata confirmed equivalence before continuing. This review extended the initial 10-minute estimate.

Exactly six reviewed additive migrations passed, with original operational table counts preserved. Four private media references were verified against files. Existing application key, native identities, password hashes and integration configuration were retained; uncommissioned provider flags remained disabled. Both private and public tests verified login, authenticated native reads, WebSocket upgrade, anonymous 401 boundaries and private-file 404 boundaries. Only exact verification tokens were revoked. No ambulance was dispatched or operational trip created for testing.

VPS API, queue and scheduler are now authoritative. The queue/scheduler inherited an inappropriate web-server health probe; it was replaced with tested process probes and a 90-second graceful-stop allowance. Final verification shows all nine services running; API, database, cache, queue and scheduler are healthy. Docker is enabled at boot and service restart policies are configured. The staged reboot recovery was tested before cutover; no post-cutover reboot was performed during operations.

**Never restart the four old PC writers or restore a temporary tunnel origin.** After new VPS writes, rollback requires reconciliation. Every explicit Compose command must include `compose.override.yml`, which selects final volumes. Fresh capture and acceptance receipts are retained privately on the PC and VPS. The availability monitor has been updated for the VPS and must not revive the old services.

## Remaining rollout work

Daily independent scheduled backup storage and external uptime monitoring remain to configure. Hostinger weekly backup configuration, the initial provider snapshot and manual restore evidence do not establish ongoing independent daily backup coverage. The Codex monitor still depends on this PC being on. GPS supplier, WhatsApp Business and Firebase are not commissioned. Physical phone GPS, sound/background alerts and an end-to-end operational trip remain separate acceptance tasks. Successful server deployment does not establish full emergency-service readiness or guaranteed uptime.

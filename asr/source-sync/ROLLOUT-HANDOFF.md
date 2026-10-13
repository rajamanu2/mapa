# ASR Health rollout handoff — 6 October 2026

Status: local candidate preparation, not a production deployment or fleet acceptance receipt. See current test receipts before enabling services.

## Verified local deliverables

Signed Android testing build: `ASR-Health-Crew-1.14.2-test.apk`, version code 25, in the isolated Android chat's `outputs` directory. SHA256: `41385f9914d6d6ce5efc88c7b15fc6ac5aa66f0719769a23b41e45802409df8b`. The build retains Firebase configuration, passes release lint, signature/package verification and 799 local Java/map checks. This is not physical-phone or provider-delivery acceptance.

New Call destination correction: 176 UI checks and 207 disposable native database checks passed. Deploy `VoiceService.php` and `mapa-voice.js` together; see `../voice/tests/destination-verification.json` for exact hashes. Eligible destinations paginate without losing an existing selection; stale responses cannot replace another call's selection.

Connected GPS/trip suite: 327 disposable native checks passed, plus five bridge tests and binary GT06 receiver acceptance. See `../mobile/tests/connected-tracking-native-result.txt`. These use isolated fixtures and do not certify a production receiver or physical BR05.

Early hospital assignment projection is additive and authenticated, with caller-reported information separated from crew observations. The APK supports its capability flag. Final native retest passed 477 checks, and 37 pure policy checks passed; see `../mobile/tests/hospital-assigned-verification.json`. Polling visibility is not an early background push alert.

## Accounts

Operations staff: an ASR Health administrator creates or invites a native user through IAM/User management, assigns active membership in the ASR Health organization and the required operator permissions. Keep platform administrator access limited to administrators. New Call and integration management additionally require the reviewed company-bound mobile staff binding. A native account alone does not establish that binding. Do not distribute shared administrator credentials.

Crew: install the approved signed APK; choose Register / connect to ASR Health; submit individual identity, mobile, email, ambulance registration, hospital/base, consent, fresh selfie and GPS. The control team opens Crew registration, verifies the person and exact real ambulance/base and approves. Registration is pending until approval. The approved connection is retained by the phone. Existing email or already assigned ambulance conflicts require review; do not create duplicates. Start duty only after approval and a real phone GPS test. Registration does not dispatch a call.

Hospital: administrator creates a named staff account and reviewed mobile role hospital binding to the correct same-company receiving place. Sign in using the assigned account in the APK. Crew registration must not grant hospital access. Hospital accounts see only their currently assigned receiving cases. The account/facility binding must be provisioned and tested before distributing login instructions.

No real users, passwords or bindings were created by preparing this handoff. The original ASR website login is not assumed to be a MAPA credential.

## GPS supplier and operator checklist

Candidate server IPv4: 148.135.136.242. Planned receiver: GT06 TCP 5023. Do not tell the supplier that the listener is ready until deployment and an external TCP test confirm it. Website HTTPS 443 and localhost 127.0.0.1:5023 are not the tracker destination.

Supplier provides exact BR05 firmware/protocol, verified server/APN commands, active data SIM/APN and reporting interval. Operator supplies each 15-digit IMEI, ambulance registration, driver and base mapping. Never send private MAPA credentials to a tracker by SMS.

Required server work: pinned production receiver with persistent database; private management; bridge credentials; per-device IMEI registration in receiver and reviewed MAPA ambulance binding; reviewed GPS migrations/flag; only necessary TCP listener and network restriction; collection/backups, disk monitoring and retention policy. Local H2 and loopback compose are test-only. Unknown-device auto-registration remains disabled.

Once a known receiver device and credential map to a valid ambulance, fresh valid satellite fixes update the shared ambulance projection automatically. Unknown devices do not create ambulances, users or crew. GPS does not start duty. Device and phone timestamp ordering prevents an older fix moving the map backwards. IMEI allowlisting is not cryptographic authentication; supplier private APN/VPN or gateway restrictions remain relevant.

Pilot one ambulance: power/satellite fix outdoors, matching IMEI/vehicle, position/time, movement, reconnection after network/power loss, stale warning, phone/GPS coexistence and map visibility. Then test operator intake/dispatch, crew acknowledgement, pickup, hospital readiness and completion. Do not use real emergency calls for acceptance.

## Deployment gate

Preserve the authoritative VPS data and compose.override.yml. Never start the four retired PC source writers. Apply only reviewed additive migrations and verified file/image changes after backup and configuration comparison. Keep source sync, early hospital projection and GPS independently capability-gated. No deployment from this document alone.

Not yet certified: full original-ASR ambulance workflow parity; durable transfer alerts/acknowledgement; ASR session/completed-trip bridge; native dashboard projections; provider message delivery; physical BR05/phone acceptance; independent backup restoration and approved retention policy. Design preview checks do not certify these functions.

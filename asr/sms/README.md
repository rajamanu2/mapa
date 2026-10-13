# MAPA SMS gateway — local candidate, disabled

This integrates the existing MAPA caller link with Gammu SMSD. It has **not** installed Gammu, connected a modem/SIM, opened a port, sent an SMS or changed the authoritative VPS. The operator has no modem/provider account available for this run. Keep SMS disabled until commissioning.

## End-to-end contract

The operator saves a real call's callback number and explicitly confirms permission for a service SMS. The console may then invoke the automatic endpoint once; do not send on keystrokes. Authentication, company scope, administrator/dispatcher role, non-training call, active trip, callback and consent are rechecked server-side.

- `GET /mapa/mobile/v1/tracking/sms`: `configured`, `delivery_receipts: false`, `transport: gammu_smsd_gateway`.
- `GET /mapa/mobile/v1/tracking/calls/{id}/sms`: recent status summaries; no recipient, capability or gateway credentials.
- `POST /mapa/mobile/v1/tracking/calls/{id}/sms/auto`: JSON `action_id`, `expected_callback_number`, `consent_to_message: true`. Expected callback is normalized and compared under the call lock. A different saved callback returns 409 before creating a link.
- The automatic endpoint checks previous attempts for the same call and callback **before** issuing a full-journey link. Replay keeps the original valid link; it does not revoke/reissue it or inject again. An expired/revoked/reassigned previous link returns 409 for explicit operator review instead of silently resending. No raw link/token is returned from automatic sending.
- Manual reviewed sending remains `POST .../sms` with `action_id`, an existing scoped `token`, and `consent_to_message: true`. A link cannot have a second SMS attempt, even after an unknown outcome.

Migration `2026_10_05_000010_create_caller_sms.php` stores status, hashed request/recipient, gateway ID and consent time, never raw SMS text/capability/phone. Auto-send also requires the full-journey tracking migration 000011. Migrations are local candidates; do not apply them blindly to production.

The call/link/attempt reservation commits before any gateway request. Transport timeout/crash is `unknown` and is not automatically retried. Successful gateway injection is **`queued`**, not sent, delivered or read. SMSD may later encounter modem/network errors. Delivery-report correlation is not implemented; the API intentionally reports `delivery_receipts: false` and `delivered: false`. A queued message can arrive late with an expired link, which the tracking API will deny. Test this operationally.

## Gammu host and transport

`gateway.py` provides one narrow `POST /v1/sms` endpoint. It accepts only a 64-hex request ID, normalized phone and canonical ASR Health `/track/#` capability URL. It constructs a fixed service message without patient/hospital/clinical details.

All connections require TLS 1.2+ with a client certificate issued by the dedicated private client CA **and** a separately generated 256-bit bearer secret. The PHP transport validates the gateway's server certificate using its configured CA and presents its own client certificate/key. Redirects are prohibited. Use a private VPN or restricted administrator network as an additional boundary. The example binds loopback; no internet listener is created by preparing these files.

The gateway persists an `unknown` reservation to a private SQLite ledger before calling Gammu. The ledger contains HMAC fingerprints and statuses only. Repeated/conflicting IDs cannot enqueue twice, including after a gateway crash. Do not erase the ledger to resolve an ambiguous outcome. A request timeout after modem injection cannot prove non-delivery.

The injection call uses `subprocess.run` with a fixed executable, an argument list, `shell=False`, and the message on standard input. Capability URLs never enter command-line arguments or captured output/logs. Gammu requires the recipient as a process argument; restrict process visibility on this dedicated host. Standard output/error are discarded. The HTTP adapter emits no access logs or request bodies.

### Pinned multipart CLI contract

The candidate requires **Gammu SMSD and gammu-smsd-inject 1.45.0**, upstream release commit `17178d4ed8aaf090344bcc46f36cc35837f82d8e`. Both configured executable paths are queried with `-v` at startup and before each new injection; older, mismatched, unreviewed or unrecognized versions fail closed before reserving/enqueueing a new message. Version checks contain no recipient or SMS text and do not contact a modem. The daemon supervisor must run the exact reviewed SMSD binary; a version check cannot attest to an unrelated already-running daemon.

The [1.45.0 release notes](https://github.com/gammu/gammu/releases/tag/1.45.0) explicitly introduce automatic multipart text handling, corrected decoded-length handling and the FILES-backend retry fix. Do not rely on a distribution's older default package. A newer release needs explicit source/CLI review and regression testing before changing the pin. **1.45.0 is the reviewed expected version, not a claim of a locally installed or physically tested binary.**

Current injection arguments are `TEXT RECIPIENT -len FULL_ASCII_LENGTH -maxsms 2`. The exact complete ASCII message, including every character of the 64-hex capability URL, goes to stdin without a trailing newline. The gateway rejects a body longer than 306 bytes; `-len` equals the actual body length rather than a smaller truncation limit. The reviewed Gammu CLI rejects more than two encoded SMS parts before injecting. This avoids silent fallback to the old 160-character behavior. Fake-CLI tests verify these arguments and the full body, but actual encoded multipart reassembly and clickable full-link receipt still require a real modem/SIM and test phone.

Gammu SMSD's own outbox/sent storage necessarily contains message text and recipients. Protect it with private filesystem/database permissions, encrypted disks/backups and an approved retention policy. It must never be exposed through MAPA web assets or public downloads. Configure SMSD with `MaxRetries = 0`, `DebugLevel = 0` and no logging (or `/dev/null`); the gateway refuses injection otherwise. This prevents this adapter from deliberately retrying uncertain messages; it cannot guarantee carrier-level exactly-once delivery. Verify the selected Gammu version's multipart and network failure behavior with the real modem. Messages containing the long capability link will usually require multiple SMS parts and incur the associated carrier charges.

## Owner commissioning requirements

1. Obtain a Gammu-compatible SMS-capable modem and an active SIM with a suitable SMS plan; verify model, OS support, driver/serial device and carrier usage rules. A BR05 GPS tracker is not assumed to be an SMS gateway. The VPS alone does not provide a USB modem.
2. Provide an always-on modem host with power/network resilience. Keeping the modem on the owner PC would make SMS availability depend on that PC even though MAPA itself runs on the VPS. A separately managed always-on hardware host or a reviewed SMS provider is needed for independent operation.
3. Install/review Gammu SMSD on that host using its official platform packages/docs, configure the physical modem and private storage, disable retries/debug logging, and test modem connectivity privately. No example device path, SIM PIN or account is invented here.
4. Create a private CA/server certificate for the chosen gateway hostname and a dedicated client certificate. Keep server key, client key and bearer secret in protected files. Configure the SMSD file/ledger directory and copies of `gateway.example.json` outside the repository. Directory 0700 and private files 0600 on Linux. The example is deliberately disabled and contains no credentials.
5. Set the VPS's private `MAPA_SMS_GATEWAY_ORIGIN` (exact HTTPS origin, no path), `MAPA_SMS_GATEWAY_TOKEN`, `MAPA_SMS_CA_FILE`, `MAPA_SMS_CLIENT_CERTIFICATE`, `MAPA_SMS_CLIENT_KEY`. Mount keys read-only in the actual API service; never put them in Android/JavaScript. Keep `MAPA_SMS_ENABLED=false` until private connectivity, migrations and acceptance are reviewed.
6. Review production service management/firewalls separately. `python3 gateway.py --config /PRIVATE/gateway.json` is the executable entry point; there is no automatic installation or enabled startup unit in this candidate. Verify actual TLS rejection without/wrong client certificate and bearer, plus a consenting test recipient. Configure process supervision only after approval and successful setup.
7. Perform one controlled operator-save → SMS queue → real phone receipt → link-open → location-consent → ambulance-trip test. Check wrong callback, duplicate clicks/reloads, concurrent saves, expiry/revocation, temporary gateway outage and restart. Never treat queue acceptance as delivery or emergency-service readiness.

## Local validation

`python -B fleetbase/asr/sms/tests/test_gateway.py` uses an injected fake subprocess only. It exercises durable deduplication, concurrent submissions, crashes/timeouts, malformed payloads, disabled configuration and sensitive-output boundaries without Gammu, a network listener or a SIM. Native backend coverage is `mobile/tests/sms-native-cases.php` within the disposable connected-tracking suite; all HTTP requests there are faked.

Local results after the multipart follow-up: **20 gateway tests passed**, including exact multipart length/body preservation and rejection of old/mismatched/failing version checks. `python -B fleetbase/asr/sms/tests/test_tls.py` passed **4 real TLS memory-BIO handshake tests**: trusted mutual TLS accepted; missing/untrusted client certificates and wrong server hostname rejected. These tests create ephemeral fictional certificates in a temporary directory, open no listener and send no SMS. The TLS tests use the installed `cryptography` test dependency; the gateway runtime itself uses only the standard library. Native SMS cases are handled by the main isolated suite runner; record its result separately rather than inferring it from gateway tests.

## Primary sources reviewed

- [Gammu source](https://github.com/gammu/gammu): existing external project; its own license remains applicable. No fork or binary is bundled here.
- [SMSD injection](https://docs.gammu.org/smsd/inject.html): injection queues messages; SMSD later uses the connected modem. Supports stdin and no-log mode.
- [SMSD configuration](https://docs.gammu.org/smsd/config.html): modem/storage settings, debug logging and retry configuration. Hardware/network delivery must be commissioned separately.

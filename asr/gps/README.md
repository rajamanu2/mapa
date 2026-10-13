# MAPA GT06 local integration

Status: local implementation and synthetic verification only. No VPS service, firewall, DNS, live vehicle or production feature flag is changed by these files.

## Flow

GT06 TCP -> pinned Traccar 6.16.0 -> private authenticated JSON bridge -> MAPA per-device ingestion -> existing native ambulance map/history.

The supplier confirmed the BR05 uses GT06. Actual BR05 firmware, SIM/APN, packet timestamps, satellite reception and reconnection must still be commissioned on one physical device. The simulated test uses a fictional IMEI, never the owner's real tracker.

## Local setup

1. Run `python prepare-local.py` from this directory. It creates ignored `private/traccar.xml` and `private/bridge.json` with a random forwarding secret and **empty** device allowlist.
2. Run `docker compose -f compose.local.yml up -d` only for this new isolated project. The receiver uses `127.0.0.1:5023` TCP and its management page `http://127.0.0.1:8083`. Neither is reachable from an ambulance on the internet. There is no UDP publication. The bridge has no host-published port.
3. Use a separate disposable MAPA API/database for local work. Never start the four stopped former-PC writers, point at stale operational data, or change the production API for a local test. The generated bridge endpoint points to local port 8897, not the VPS. The correct public API path is `/mapa/mobile/v1/tracking/ingest` without an `/api` prefix; the native gateway handles mobile bearer tokens on this route.
4. Apply the mobile migrations including `2026_10_04_000009_add_hardware_fix_quality.php` to that isolated API. Enable `MAPA_MOBILE_ENABLED` and `MAPA_DEVICE_INGEST_ENABLED` there only.
5. In MAPA's existing **Ambulance GPS devices** screen, select the correct ambulance, enter the IMEI as `external_id`, and give it a label. Keep its returned one-time credential private. Add `"IMEI": "device credential"` under `devices` in the ignored bridge configuration. Each device needs a different credential.
6. Initialize a private local Traccar administrator at its loopback management page; register the same IMEI there as the unique ID. Unknown-device automatic registration is disabled. Restart only the local bridge after editing its configuration. Do not put the MAPA credential into tracker SMS commands.
7. After testing, `docker compose -f compose.local.yml stop` stops this project without touching the operational system. The local H2 database is for testing only, not production.

## Data and security behavior

- The bridge rejects unknown IMEIs, mismatched receiver device IDs, disabled devices, other protocols, invalid/LBS/approximate/outdated fixes, fewer than four satellites, impossible coordinates, old fixes (>5 minutes) and timestamps >30 seconds ahead.
- Missing/zero Traccar accuracy is stored as `null`, explicitly unknown. Satellite count is preserved, not converted into a fictitious metre accuracy. Caller and phone accuracy validation are unchanged.
- MAPA independently rechecks credential, external ID, tenant, vehicle activity, quality and timestamp. Exact GT06 retries are idempotent; differing fixes at an existing timestamp are rejected. History, map and broadcasts do not duplicate.
- The existing shared projection prevents hardware/phone updates moving the map backwards. GPS never turns a crew or ambulance on duty.
- MAPA credential revocation immediately denies subsequent writes, including retries. Remove/disable the matching Traccar device and bridge mapping as well to stop collection at the receiver.
- Bridge requests are bounded; redirects cannot forward credentials elsewhere. Logs contain rejection categories/status codes, not coordinates, IMEIs or secrets. The forwarding secret authenticates Traccar to the bridge; individual credentials scope bridge-to-MAPA access.
- **GT06 itself does not cryptographically authenticate a tracker. IMEI allowlisting cannot prevent a knowledgeable sender spoofing an IMEI.** For deployment, prefer a supplier private APN/VPN or authenticated gateway, restrict network sources where feasible, and document this remaining limitation. GPS is device-reported evidence, not proof of physical presence.
- Traccar retries temporary bridge/API failures with a bounded in-memory queue (8 retries, initial 1 second, at most 1,000 queued positions). It is not a durable delivery guarantee across restarts. Received positions remain in Traccar's database. Stale historical data must not be replayed as live GPS. Monitor rejection/outage counts and database growth.

## Verification

`python -B -m unittest discover -s tests -p test_bridge.py -v`

`python tests/run-receiver.py --docker PATH_TO_DOCKER`

`python ../mobile/tests/run-connected-tracking-native.py --docker PATH_TO_DOCKER`

The receiver test creates a disposable internal Docker network, ephemeral H2 and a mock MAPA HTTP capture. It sends synthetic binary GT06 over TCP and tests fragmented login/ACK, coordinates, accuracy, authenticated forwarding, invalid GPS and unknown-device rejection. The separate native suite uses disposable MySQL and checks real migrations, tenancy, credentials, history, projection, phone ordering and duty invariants. No production data is used.

## Before a separately approved deployment

Review local results, use a production Traccar database (not H2), plan durable collection/backups and retention, initialize restricted management access, register the actual ambulance/IMEI, configure the bridge secrets, apply the additive native migration, and verify authenticated ingestion before enabling GT06 traffic. Publish only the required TCP port after review. Preserve the authoritative VPS compose override and final volumes. Physical BR05 acceptance must verify UTC/device time, movement, loss/recovery of reception/network/power, and coexistence with crew GPS.

References: [Traccar Docker](https://www.traccar.org/docker/), [forwarding](https://www.traccar.org/forward/), [supported protocols/devices](https://www.traccar.org/devices/). Traccar is a separate Apache-2.0 component; this package does not replace its license or notices.

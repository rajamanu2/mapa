# VPS operations candidate validation

## 5 October 2026 — local recovery and monitoring follow-up

The operational VPS cutover is complete; see `DEPLOYMENT-2026-10-04.md`. This follow-up changed local scripts/docs only. No VPS service, account, DNS, provider message, operational data or backup repository was changed.

- `test_recovery_monitoring.py`: **18 tests passed**, including corrupted/missing/unlisted backup components, storage-key exclusion, checksum traversal and aliases, tar traversal/links/devices/duplicates/size limits, exact relay redirect, JSON 401 authentication denial, network failure, and missing/stale/future/malformed backup success markers.
- `test_vps.py`: **10 tests passed** on the current source.
- `verify-backup.py` never extracts/imports data; its successful result explicitly keeps database restoration and application acceptance unverified. Fixtures were isolated synthetic files only.
- `uptime-check.py` now optionally includes all compatibility API checks and the exact browser redirect. `backup-freshness.py` provides a private host-side 26-hour success-marker check.
- Independent-host monitor systemd service/timer prepared, **not installed**. They return/log health; the owner's alert provider/recipients must still be configured and tested.
- Backup storage account, repository credentials/recovery key, retention policy, first scheduled backup plus isolated restoration, external alert route and PC-off monitoring verification remain required. Previously passing migration restoration does not commission these new scheduled backups.

## Historical package preparation — 4 October 2026, before cutover

Status at this earlier preparation stage: **prepared, not deployed**. The deployment report supersedes the infrastructure status below; it is retained as historical local-test evidence.

- `test_vps.py`: 10 tests passed. Covers fail-closed launch gate, temporary hostname rejection, legacy connection override rejection, runtime integrity, public-file exclusion, company consistency, network-check failure, authenticated native/socket routes, proxy identity/query logging and private path blocks.
- Docker Compose `config --no-interpolate --no-env-resolution --format json`: successfully parsed all nine services (access, api, cache, database, gateway, https, queue, scheduler, socket). Only `https` publishes TCP 80/443. No database/Redis/socket/API published ports.
- `backup.sh` and `launch.sh`: `bash -n` passed. Parsing only; no backup, launch, service change or network provider action was executed.
- Runtime refreshed after final module changes: 103 source files plus SHA256 manifest. The earlier snapshot was preserved under private/runtime-snapshots after verifying source/destination stayed within this package. Runtime is an ignored local release snapshot, not a public artifact. Regenerate again if source changes before shipping.
- `preflight.py --launch` correctly exits nonzero for missing private `.env` and `private/api.env`. These are intentional provisioning gates, not populated with invented secrets.

Not tested yet: Caddy certificate issuance, live Nginx under the target network, API image rebuild on Ubuntu, fresh database/upload migration, restore rehearsal, boot/crash recovery, remote backup destination, independent monitoring/alerts, supplier tracker delivery, provider push/WhatsApp delivery, capacity/soak test. Shell and static tests cannot establish those outcomes.

Privacy: gateway access logging stays disabled and Caddy access logging is not enabled, so webhook query tokens are not written to access logs. Existing source/legal release exceptions are retained from the reviewed live gateway; private configuration and user uploads are excluded from public-console.

Final backend evidence for the refreshed snapshot: 335 dedicated native checks and 168 combined tracking/provider checks (including 35 hardware projection checks) passed with exact end-of-suite markers and exit code zero. Disposable isolated QA resources were removed; production services were not changed. These backend checks do not substitute for target-host migration/provider acceptance.

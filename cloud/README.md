# MAPA always-on cloud pilot

**Always-on migration prepared, not deployed.** This package moves the existing MAPA console and its native backend onto a separate Linux server. No cloud server has been purchased. A separate PC-hosted full application is now available at **https://mapa-asr-health.pages.dev/fleet-ops**, using the existing ASR Health workspace; see [live-access/README.md](../live-access/README.md). That public link still depends on this PC and Docker. The proposed always-on address `https://mapa.rajamanu2.workers.dev` must not be presented as live.

## Hosting proposal

Start the demonstration on one Ubuntu 24.04 x86-64 server with **2 vCPUs, 4 GB RAM and 80 GB persistent SSD**. The current local stack used about 1.7 GB RAM at idle during inspection. That is a sizing observation, not a load test or a production capacity guarantee. The cloud API is limited to two workers to keep the pilot predictable.

A DigitalOcean Basic Droplet with those resources is listed at **US$24/month before tax**, excluding backups and any other paid services, as checked on 2026-09-18: [official Droplet pricing](https://www.digitalocean.com/pricing/droplets). Prefer a nearby available region such as Bengaluru for the Indian pilot. The user must create the hosting account and authorize its recurring charge before any server is provisioned. No payment has been made by this preparation.

Cloudflare terminates HTTPS on a Worker. Its private VPC service reaches a named Cloudflare Tunnel running on the Linux server, which can remain online when the PC is switched off. The tunnel connects only to `gateway:8080`. MySQL, Redis, PHP and voice state remain on the server's persistent volumes. No application or database port is published on the server; restrict SSH at the host/provider firewall to the administrator.

Workers VPC is currently in open beta and free during beta; standard Workers request/compute limits still apply. Availability and eventual pricing can change: [Workers VPC pricing](https://developers.cloudflare.com/workers-vpc/platform/pricing/), [private service configuration](https://developers.cloudflare.com/workers-vpc/configuration/vpc-services/). Cloudflare Containers have ephemeral disks, so this migration does not put MySQL on container-local Cloudflare storage: [container lifecycle](https://developers.cloudflare.com/containers/concepts/architecture/).

## What is prepared

- The same existing MAPA application, ASR Health organization, native roles and clearly marked demo records.
- Private export of the exact running images, database, uploads and voice journal, with hashes and interruption recovery. Credentials remain in a private transfer directory.
- An isolated console copy configured for a single HTTPS origin; current local console files remain unchanged.
- A separate `mapa-cloud` Docker Compose project with persistent volumes, database/app-user separation, queue, scheduler and automatic restarts.
- A Cloudflare edge proxy and a private Nginx gateway preserving native sign-in and Android/Call Intake authentication.
- Public registration, internal bridge ingestion, diagnostics and telephone media blocked at the gateway.

## Current limits

This is a cloud **pilot**, not a production/emergency-service readiness certification. The existing SocketCluster server has no user/channel authorization. Consequently the public `/socketcluster/` route is deliberately unavailable: live chat, notifications and live vehicle positions need that work before they can be enabled. Ordinary authenticated HTTP operations can be tested independently. VPC WebSocket transport also needs an actual remote acceptance test; mock forwarding tests do not prove it.

Real telephone calls remain off. Exotel number assignment, provider verification, the human fallback destination and a controlled phone test are still pending. Sarvam speech credentials are transferred privately if configured, but that does not enable calls. Dispatch remains an explicit operator action.

This snapshot is not ongoing PC/cloud synchronization. Use one authoritative installation after cutover. The Android app must be pointed to the cloud HTTPS origin and signed in again; current APK and on-device acceptance are separate steps. Existing absolute localhost upload URLs stored in database rows require a targeted review if present. The reused console is the currently installed development build, not a fresh optimized production rebuild.

## Deployment procedure

1. Create the approved server in the user's hosting account. Install Docker Engine and Compose using the [official Ubuntu instructions](https://docs.docker.com/engine/install/ubuntu/). Keep Python 3.11+ available. Configure a provider firewall: no inbound HTTP, HTTPS, MySQL or Redis; SSH only from the administrator's IP. Permit outbound HTTPS and UDP 7844 for Cloudflare Tunnel.
2. Create a named tunnel in the connected Cloudflare account and a VPC HTTP service for `gateway` port 8080 through that tunnel. DNS resolution from the connector uses Docker's resolver (`127.0.0.11`) for that hostname. Keep the token private. Do not start the connector yet. The exact API procedure and Worker configuration are in [edge/README.md](edge/README.md).
3. Follow [MIGRATION.md](MIGRATION.md) to review and capture the private transfer. Final capture briefly stops local writers and restarts them. Do not continue dispatching independently into two databases after the final snapshot.
4. Prepare `cloud-console` on the PC using [FRONTEND.md](FRONTEND.md): `node runtime-config.mjs --source PRIVATE_EXPORT/raw-console --output PRIVATE_EXPORT/cloud-console --origin https://mapa.rajamanu2.workers.dev`. Copy the reviewed deployment files from this directory alongside the private export. Do not put the export or `.env` in a public download.
5. Transfer over authenticated SSH/SFTP to a private `/opt/mapa` directory. Verify the server's SSH host key against its provider console. Restrict the directory to the administrator/root; `.env` and `private/tunnel-token` to mode 0600.
6. From `/opt/mapa`, run `python3 restore-cloud.py` to verify, then `python3 restore-cloud.py --restore-empty-server`. It refuses existing `mapa-cloud` volumes, imports into a fresh database, restores storage, and starts the private app. It does not start the public tunnel. If it fails, inspect the private resources; no automatic deletion or overwrite is performed.
7. Verify private service health and sign-in from an SSH-forwarded test port. Run the reviewed Worker build/dry run. Create the real Worker/VPC binding, write its tunnel token file privately, and run `docker compose --profile public up -d tunnel`. The public address is ready to share only after the checks below pass.

## Acceptance before sharing

- Main HTTPS URL and direct `/fleet-ops/connectivity/telematics` route load the existing MAPA console with no localhost API requests or mixed-content errors.
- Native administrator sign-in, role boundaries, an operations screen and Call Intake administrator sign-in work through the public origin. Wrong passwords are rejected; no credentials appear in logs or browser config.
- Android signs in against the same HTTPS origin and reads the same demo assignment. This must be checked on a physical device as well as the backend.
- Internal bridge ingestion, registration, `.env`, SQL/backups and diagnostics are inaccessible publicly. Database/Redis have no public ports. Live voice and unauthorized sockets remain unavailable.
- A clearly labeled demonstration call can be reviewed and dispatched with an explicit operator action. No real notification recipients or emergency dispatch are used.
- A new request still works after this PC is shut down, and after a server restart. Confirm database and uploads persist. This verifies the actual requirement; a local tunnel test cannot substitute for it.
- Configure protected off-server backups and test restoration. `backup.sh` provides a database/config snapshot only; a complete recovery backup also needs coordinated uploads and stopped voice-journal archives. Hosting-provider backup services may cost extra.
- Preserve the existing upstream notices and source offer. Add these reviewed cloud modifications to the public corresponding-source download before publication, using the same source-only allowlist as `package-cloud.py`; never add the private migration export or credentials.

Do not mark the deployment complete until the relevant remote checks pass. The cloud provider account, recurring server charge and SSH access are the remaining infrastructure prerequisites.

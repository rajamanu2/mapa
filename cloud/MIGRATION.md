# Move the existing MAPA installation to an independent cloud server

This procedure transfers the selected native MAPA console, API, ASR Health
organization, role bindings, database, uploads and voice journal. It does not
create another product UI. The destination Linux server must run continuously;
a tunnel from the current PC does not meet that requirement.

The cloud server and public gateway must be prepared before a final transfer.
The proposed public origin is `https://mapa.rajamanu2.workers.dev`. This is a
configuration proposal, not a claim that that URL has been deployed.

## 1. Review the transfer without changing anything

From this `cloud` directory, with Docker Desktop and the existing local MAPA
stack running:

```powershell
python export-cloud.py --origin https://mapa.rajamanu2.workers.dev
```

If Python or Docker is not on `PATH`, invoke the installed Python executable
and pass `--docker` with the Docker CLI executable path. The default command
only reads container metadata and prints service states. It does not create
directories, tag images, copy data or stop any service.

The default private destination is
`%LOCALAPPDATA%\MAPA\CloudExports\<UTC timestamp>` on Windows, or
`~/.local/share/MAPA/CloudExports/<UTC timestamp>` on Linux. `--output` selects
a new private directory. Existing directories, source-repository destinations,
OneDrive destinations and common web-served directories are rejected. The
exporter restricts the directory to the current Windows account or Unix mode
`0700` before writing private data.

## 2. Capture during an agreed cutover window

Only this command performs the transfer capture:

```powershell
python export-cloud.py --origin https://mapa.rajamanu2.workers.dev --capture
```

Do not run local demos, receive calls, rebuild containers, edit configuration
or make dispatch changes during capture. Keep the PC powered on until it
finishes. Do not interrupt Docker Desktop. The exporter:

1. Records the exact running image IDs, tags them for transfer, saves the
   images and copies the actual served console files. These potentially slow
   steps happen before the API interruption.
2. Confirms the selected containers did not change during preparation.
3. Stops only the application, queue, scheduler and voice bridge services that
   were running, allowing up to 120 seconds for each to stop. It leaves MySQL,
   Redis and the local console running.
4. Checks Redis queues for queued, delayed or reserved jobs. A nonempty queue
   stops export for review; Redis queue jobs are never silently discarded.
5. Captures a single-transaction MySQL dump, the uploads volume and the entire
   stopped voice-journal volume, including any SQLite companion files.
6. Restarts the previously running writer services in a `finally` block even
   if capture fails. A service that was already stopped remains stopped.
7. Writes hashes and a complete manifest only after a successful capture and
   restart. It removes the `INCOMPLETE` marker last.

Downtime depends on database and upload size. Image export happens before
downtime; large database or upload captures can still take several minutes.
The current local `.env`, database and persistent volumes are not altered.

The private bundle contains:

| File | Purpose |
| --- | --- |
| `images.tar` | Exact API, console and voice-bridge images, tagged `mapa-cloud-api:transfer`, `mapa-cloud-console:transfer`, `mapa-cloud-voice-bridge:transfer` |
| `raw-console/` | Console HTML, JS and assets copied from the serving container; rewrite runtime URLs before exposing this copy |
| `database.sql` | Full existing native database, including accounts, role bindings and dispatch records |
| `uploads.tar` | Existing native API storage volume, with original ownership |
| `voice-journal.tar` | Existing voice journal volume, with original ownership |
| `.env` | Private cloud configuration and credentials; never a public release asset |
| `manifest.json` | SHA-256 hashes, byte sizes, image IDs and completion status |

The application encryption key, company UUID, explicit mobile roles, demo
flags and configured provider keys are retained from the running containers.
MySQL root/app passwords, the server adapter credential, bridge credential and
voice stream password are newly generated. Provider keys cannot be rotated
locally, so retained keys remain shared until separately rotated with each
provider. Real voice intake and the human-fallback-ready flag are forced off;
public WSS is cleared, and outgoing mail uses the log driver.

The MySQL password is read from the database container environment. It is
never put into a process argument or printed. Image/dump/archive binary output
is written directly by Python, avoiding PowerShell redirection encoding.

## 3. Restore privately, then publish

Transfer the complete private directory over authenticated SSH/SFTP to a
root-only directory on the destination server. Do not upload it to a public
bucket, release download, Git repository, email attachment or shared cloud
folder. Keep the private bundle separate from the public source archive.

Follow the server deployment instructions in this directory. Before loading
the public gateway, the restore process must:

1. Reject an `INCOMPLETE` export, validate its format/completion flag and
   verify every manifest hash. Use an empty destination database and volumes;
   do not overwrite a running installation.
2. Load `images.tar`, create the destination database/app user, import
   `database.sql`, and restore both volume archives inside isolated containers.
   Do not unpack the data-volume tar files directly over the server filesystem.
3. Use the provided environment consistently for API, workers, scheduler,
   database and voice bridge. Preserve `APP_KEY`; changing it can make existing
   encrypted application data unreadable.
4. Revoke copied native and mobile session tokens before exposing the server,
   requiring fresh sign-in on the cloud system. `restore-cloud.py` revokes all
   MAPA mobile sessions using a millisecond timestamp and deletes native
   Sanctum tokens whose `tokenable_type` is `Fleetbase\Models\User`. Inspection
   of the serving native authentication source confirmed this is the console
   login token type. Business integration credentials in `api_credentials` and
   platform key settings are preserved. The cloud configuration uses a fresh
   Redis session store; Redis cache and browser sessions are not transferred.
5. Run `runtime-config.mjs` against `raw-console/` to prepare `cloud-console/`
   for the HTTPS origin, following `FRONTEND.md`. Serve the generated copy.
6. Check sign-in, tenant scope, read screens, a clearly marked demo dispatch,
   and Android server configuration through the public hostname. Verify that
   old native/mobile tokens are rejected. The current public gateway blocks
   SocketCluster until user and per-channel authorization is implemented;
   live socket notifications remain unavailable during this gate. Keep real
   calls disabled until the provider number, human handoff and a controlled
   end-to-end phone test are complete.

The exporter restarts the local stack after capture so it remains available.
This makes the export a snapshot, not ongoing database replication. At final
cutover, stop local writes until the destination is verified and designated as
the authoritative system. If local writes occurred after capture, export again
before making the destination authoritative. Do not allow two installations to
dispatch from diverging copies of the same database.

## Recovery and verification

An `INCOMPLETE` directory must never be restored. Leave it private for diagnosis
or delete it deliberately after confirming the path. Use a new directory for a
retry; the exporter never overwrites an earlier bundle. If a local service
restart is reported as failed, inspect and start that named service before
retrying. The script does not erase the source database or volumes.

The export is a sensitive migration copy, not the server's backup policy.
Configure encrypted off-server backups and verify a restore before relying on
the cloud installation. Delete temporary transfer copies only after the new
server and its backups have passed acceptance checks.

The included `test-export-cloud.py` and `test-restore-cloud.py` use fake Docker operations and temporary
synthetic data to check interruption recovery, queue protection, origin
validation, environment isolation, manifest verification, empty-target guards
and scoped session revocation. They do not stop live services or copy or
restore the actual database.

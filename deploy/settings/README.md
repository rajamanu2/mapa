# Settings UI deployment

This manually triggered workflow publishes only `mapa-dispatch-settings.js` and updates its reference in the existing root `index.html`. It does not rebuild the console, deploy API code, restart containers, change dispatch settings or send notifications.

## One-time prerequisites

An authorized VPS administrator must establish a dedicated `mapa-deploy` SSH account. It must have a home directory, Python 3, bash and flock. Grant only the filesystem access needed to read the existing public assets and atomically replace files in `/opt/mapa-staging/release/public-console`. Directory write access permits changes to the public UI, so treat this credential as a production publishing credential. Do not grant sudo, Docker group membership, access to private configuration or root access. Disable SSH forwarding for its key. Preserve existing file ownership/access and test permissions before enabling the workflow.

Generate a dedicated SSH key pair through the approved access setup, install the public key for this account, and independently verify the VPS SSH host key. Do not copy the server root password or any key into the repository or chat.

In GitHub repository Settings → Environments → production, configure:

- Environment secret `MAPA_DEPLOY_KEY`: dedicated account's private key.
- Environment secret `MAPA_KNOWN_HOSTS`: independently verified known_hosts entry for `148.135.136.242`.
- Environment variable `MAPA_DEPLOY_USER`: `mapa-deploy`.
- Restrict deployments to main and configure reviewer protection where supported by the GitHub plan.

## First deployment

Open Actions → Deploy MAPA settings UI → Run workflow on main after the one-time server setup is verified. The workflow fails closed without credentials. It tests the settings UI, exercises publication/rollback locally, then connects with strict SSH host verification.

The publisher discovers current asset references, checks live file hashes before writing, keeps a backup under the account's `releases/settings-RUN-ATTEMPT/rollback`, and verifies the public HTML reference and JavaScript SHA-256. A public verification failure triggers rollback. No deployment runs automatically on push.

If the connection drops after publication, inspect `receipt.json` and the public site before retrying. Do not delete release backups. To roll back a completed release, the administrator runs `python3 publish-settings.py --rollback` from that exact release directory; it refuses rollback if files have drifted.

Status: live SSH access, publication and public asset hash verification succeeded in GitHub run 38462141312 on 2026-10-14 IST. This does not resolve the dispatch scheduler lock or establish API deployment readiness. Deployments remain manually triggered.

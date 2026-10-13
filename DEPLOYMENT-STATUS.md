# Production deployment status

GitHub source publication is complete. The validation workflow runs isolated console tests and has read-only repository permissions. It has no VPS credentials and cannot deploy.

Do not run the repository Compose template over the existing production stack. It depends on a verified base API image, private environment files, generated runtime files and built console assets. Its default live-dispatch setting is false. Production overrides must first be inspected and preserved.

Before enabling deployment:

1. Establish an approved, dedicated deployment account on the VPS with access limited to reviewed releases. Do not use a root password or an unrestricted Docker-capable runner.
2. Verify the server SSH host key independently and store the deployment private key in GitHub Actions secrets; never commit it.
3. Configure a production environment and branch restrictions appropriate to the GitHub plan.
4. Implement and test versioned release staging, backups, health verification and rollback against the exact live configuration.
5. Perform the first deployment manually through the workflow and verify public assets and API/worker health before enabling deployment on merge.

The automatic-dispatch scheduler heartbeat/lock issue is separate and remains unverified. Passing source tests is not proof of live dispatch readiness.

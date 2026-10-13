# Cloud preparation validation — 18 September 2026

Status: prepared locally; no remote server, Worker, VPC Service or Tunnel was created. No paid service was purchased. The proposed workers.dev URL is not live.

Completed:

- Compose configuration validated with non-secret example settings. The cloud project publishes no host ports.
- A separate `mapa-cloud-api:pilot` image built successfully from the actual running API image. Cloud-only trusted proxy PHP passed syntax checks; application config/routes cleared; uploads symlink prepared. The running local API image/container was not replaced.
- The complete currently served console was copied into a private test directory. Its adapted bundle matched `ccc848d656156235e4801adf5d9727ca5ec354e4900a68f7091b72599eafde54` for the proposed origin. Source assets were unchanged.
- A temporary gateway bound only to `127.0.0.1:8894` passed 16 integration checks against the existing native API: native deep-link HTML, HTTPS runtime config, unauthenticated API denial, registration/internal route denial, encoded internal alias denial, private-file denial, and voice/socket gates. The gateway was stopped after verification.
- 15 synthetic export tests and 10 synthetic restore tests passed, including pending-queue protection, interrupted export recovery, existing-volume/container refusal, checksum and image identity verification, archive path restrictions and scoped session revocation.
- Nine edge Worker tests, type checking and Wrangler dry-run preparation passed. See `edge/VALIDATION.md` for scope and versions. The dry run does not establish remote connectivity.
- Real read-only migration inventory found all required local services running. No database snapshot was captured, no source writer was stopped and no live data was imported.

Still required: hosting account/server authorization, secure server setup, final private data capture and transfer, a real database restore, cloud sign-in and tenant checks, Android device testing, persistence/reboot checks, off-server backup/restore, and a check with this PC switched off. Native socket authentication/channel authorization must be implemented before publishing live updates. Real telephone media also remains off.

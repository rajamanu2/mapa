# MAPA edge validation — 18 September 2026

This package is a prepared deployment component. No remote deployment or account mutation was performed.

- Nine focused Node tests passed: native auth/body/cookies, proxy-header replacement including invalid client-IP removal, internal route and encoded-alias denial, native path preservation, response streaming, host enforcement, sanitized origin errors, manual redirect behavior and SocketCluster upgrade pass-through contract.
- `wrangler types --include-runtime=false --config wrangler.example.jsonc` generated `MAPA_ORIGIN: Fetcher` from the actual current binding schema.
- `tsc --noEmit` passed against current Workers types.
- `wrangler deploy --dry-run --config wrangler.example.jsonc` compiled the Worker and accepted the config/binding shape. A sandbox filesystem restriction in esbuild required rerunning the same local dry run with filesystem access; the successful run made no deployment.

Not validated: the real VPC Service (none exists yet), a real tunnel/server, public DNS/HTTPS publication, a native login across the edge, SocketCluster upgrades across VPC, remote Android access, server persistence or PC-off operation. These require the newly provisioned server and live acceptance in README.md.

The WebSocket test uses a synthetic upgrade response because Node's standard Response constructor does not accept status 101. It must not be cited as proof that Workers VPC supports the final WebSocket path.

The integrated cloud gateway intentionally returns `503` for public SocketCluster. The current socket server lacks authenticated handshakes and per-channel authorization; both are prerequisites before enabling and validating a public WebSocket path. The prepared proxy upgrade contract does not override that gateway restriction.

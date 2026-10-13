# MAPA Cloudflare edge

This is the HTTPS entry point for the **existing MAPA console and native API**, with the complete application running on a separate, always-on Linux server. It does not host the database, replace the native console, or depend on the Windows PC staying on.

**Prepared, not deployed.** The proposed address is `https://mapa.rajamanu2.workers.dev`. The configuration contains a deliberately empty VPC Service ID. No Worker, Tunnel, VPC Service, server or paid plan was created by this package.

## Routing contract

```text
Browser / Android / Windows client
  -> Cloudflare Worker HTTPS
  -> MAPA_ORIGIN (a single registered VPC Service)
  -> named Cloudflare Tunnel running on the new cloud server
  -> gateway:8080
  -> native console and native API
```

Register an HTTP VPC Service for the cloud server's private `gateway` on HTTP port `8080`, reachable from that server's tunnel. When using its Docker hostname, use the tunnel resolver and make sure the connector shares the gateway network. Do not register MySQL, Redis, the API container, or the voice bridge as this binding. The tunnel transport remains encrypted; HTTP is only the final private hop inside the server.

The VPC Service fixes the destination independently of the request URL. The Worker passes the **public** host with an `http:` scheme to select the Service's configured HTTP port. It sets `Host`, `X-Forwarded-Host`, `X-Forwarded-Proto: https` and `X-Forwarded-Port: 443`; the private gateway must preserve these and configure native trusted-proxy behavior appropriately.

Paths are preserved without rewriting:

| Public path | Private gateway responsibility |
| --- | --- |
| `/fleet-ops/...`, assets, `/fleetbase.config.json` | Serve the existing native MAPA console with HTTPS runtime configuration |
| `/api/...` | Strip the `/api` prefix and reach the native API |
| `/mapa/mobile/v1/...` | Native Android/Windows integration API |
| `/mapa/voice/v1/...` | Authenticated operator call intake API |
| `/socketcluster/` | Currently return `503`; public SocketCluster is disabled until native authentication and channel authorization are implemented |

The Worker denies `/mapa/voice/v1/bridge/...`, its `/api` alias, `/media`, diagnostic endpoints, dotfiles and ambiguous encoded paths. The gateway must independently deny internal paths. The internal voice event sender connects directly inside the private application network. Exotel streaming is a separate future deployment and stays disabled here. Public SocketCluster is also intentionally disabled at the gateway: the current server lacks an authenticated handshake and per-channel authorization. The Worker's upgrade handling is preparation for a future authenticated integration, not an enabled public realtime service.

The native MAPA backend continues to authenticate and authorize every protected operation. This Worker does not create an administrator, auto-login, bypass native permissions or provide a public demo password. Credentials, cookies, request bodies and response bodies are streamed without logging them. Responses are marked `no-store`, including assets for this initial demo; redirects are returned to the client instead of followed with its credentials.

## Local checks

Use Node.js 22 or newer and the pinned dependencies in `pnpm-lock.yaml`:

```sh
pnpm install --frozen-lockfile
pnpm test
pnpm types
pnpm typecheck
pnpm dry-run
```

`pnpm dry-run` uses the example config and **does not deploy**. It cannot verify that a VPC Service exists. Wrangler's types were generated from that config; regenerate them after any binding changes. Actual app-level test credentials are neither required nor included in these checks.

## Deployment after the new server is ready

1. Confirm the cloud server has restored the intended database and private uploads, with the native queue and scheduler running. Confirm its gateway is reachable only through the intended private network.
2. Create and connect a **named Tunnel on the cloud server**. Create the scoped HTTP VPC Service described above. Save its actual Service ID.
3. Copy `wrangler.example.jsonc` to `wrangler.jsonc` and replace the all-zero Service ID. Confirm the account, Worker name, and `PUBLIC_ORIGIN`; the origin must be an HTTPS origin without a path. Set the same public origin in the gateway/native app/runtime config. The actual `wrangler.jsonc` is ignored as deployment-local state.
4. Authenticate Wrangler using the owner's account or a narrowly scoped token held outside source files. Required VPC roles are Connectivity Directory Bind for binding an existing service, or Connectivity Directory Admin for creating it. Do not place tunnel tokens, backend keys or voice credentials in the Worker configuration.
5. Run `pnpm exec wrangler types --include-runtime=false --config wrangler.jsonc`, `pnpm typecheck`, and `pnpm exec wrangler deploy --dry-run --config wrangler.jsonc`.
6. After server readiness and billing approval are complete, `pnpm exec wrangler deploy --config wrangler.jsonc` is the explicit publish action. No package script invokes it automatically.
7. Complete the live acceptance checks below before sharing it as a working MAPA deployment.

## Live acceptance gates

- Load MAPA over HTTPS from a separate network. Sign in using a native account; verify a denied login, a successful login, and logout.
- In browser network tools, ensure API calls go to the public `/api` origin and never `localhost`. Open hospitals, ambulance calls and the map. Verify no mixed-content failures.
- Test a native Android sign-in/assignment refresh against this public origin without changing the local pilot server.
- Confirm public `/socketcluster/` returns `503` and describe the current demo as using refreshed API data rather than live socket updates. Before enabling it, implement native authenticated handshakes **and per-channel authorization**, test cross-tenant subscription denials and session revocation, and review the gateway change.
- Only after that authorization work, verify a real `101` SocketCluster handshake, authorized native subscription/messages, and reconnection after an interruption through **Worker -> VPC Service -> Tunnel -> gateway**. Current VPC docs describe `fetch()` and generated types expose `Fetcher`, but do **not explicitly promise WebSocket forwarding**. The unit test only proves that the proxy preserves an upgrade response when supplied; it is not an end-to-end VPC test. If this gate fails, use a verified Cloudflare Tunnel public hostname on an owned domain for the authenticated app and sockets, and retest; do not claim live updates work.
- Confirm public internal bridge/media requests receive `404`, normal unauthenticated protected API requests receive the native denial, and private gateway/MySQL/Redis ports are not Internet-reachable.
- Confirm queue work and uploaded files survive a server restart, and test a private backup restoration before real use.
- Shut down the Windows PC and repeat the remote sign-in and map checks. That final check demonstrates the user's requested independence from this PC.

## Documentation used

Checked on 18 September 2026. Workers VPC is currently beta; reevaluate its status and applicable quotas before production.

- [Workers VPC binding API](https://developers.cloudflare.com/workers-vpc/api/): absolute URLs; Service routing fixes the target; URL host supplies the HTTP Host header.
- [VPC Service configuration](https://developers.cloudflare.com/workers-vpc/configuration/vpc-services/): HTTP ports, tunnel hostname resolution, binding and permissions.
- [Workers VPC limits](https://developers.cloudflare.com/workers-vpc/platform/limits/): normal Worker limits still apply.
- [Worker WebSockets](https://developers.cloudflare.com/workers/runtime-apis/websockets/): general runtime WebSocket behavior, separate from the unverified VPC upgrade path.
- [Workers best practices](https://developers.cloudflare.com/workers/best-practices/workers-best-practices/): streamed responses, bindings and current configuration/types. Invocation logs are deliberately disabled because URLs may contain personal information.

Validation versions: Wrangler `4.134.0`, Workers types `5.20260917.1`. See [VALIDATION.md](VALIDATION.md) for completed checks and remaining limits.

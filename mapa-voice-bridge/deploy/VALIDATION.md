# Optional ingress validation — 18 September 2026

**Passed: 41 checks.** Validation ran against the existing local `asr-fleetbase-pilot-console:latest` image's nginx 1.31.6 binary with this package's entire nginx configuration replacing the image defaults.

- Nginx configuration syntax passed while running as unprivileged UID/GID 101, with all capabilities dropped, a read-only filesystem and a private temporary filesystem.
- A disposable random test password was delivered to `mkpasswd` over stdin in a network-disabled container. The resulting SHA-512-crypt file authenticated successfully in real nginx. The password was absent from the file.
- Missing/wrong Basic credentials and browser Origin headers were rejected.
- `/`, health/readiness, API, console, source/archive, `.env`, trailing slash, encoded path, traversal normalization, unsupported sample rate and query-token routes were denied.
- Three authenticated WebSocket upgrades succeeded against a local mock for `/media`, `sample-rate=8000` and `sample-rate=16000`. The exact URI and original Basic credential reached the mock; cookies were stripped.
- No access URL or credential was found in nginx logs.
- Compose configuration checks confirmed a separate project, an explicit public profile, a loopback-only validation port, read-only auth/config mounts, no automatic tunnel restart, and no native MAPA network membership for cloudflared.

Tests used a newly created **internal Docker network** and an internal probe container. No host port was needed. The mock was independent of native MAPA; no account or call record was created. All test containers, network and disposable credential files were removed afterward.

```json
{
  "result": "passed",
  "checks": 41,
  "public_tunnels_started": 0,
  "provider_requests": 0,
  "native_services_changed": 0,
  "nginx_image": "asr-fleetbase-pilot-console:latest",
  "cloudflared_started": false
}
```

This verifies the local nginx boundary and mock WebSocket proxying. It does **not** verify Cloudflare connectivity, a public hostname, Exotel delivery, real Sarvam audio, or a staffed telephone fallback. The optional services remain stopped until explicitly activated by the operator/root task.

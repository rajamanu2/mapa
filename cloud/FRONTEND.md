# Native MAPA console on one HTTPS origin

This deployment keeps the existing Ember console. It does not substitute the separate interface on port 8791.

## Prepare an isolated static copy

Export `/usr/share/nginx/html` from the selected, immutable console image into a plain directory. Include the current source download, legal notices, lazy-loaded engine assets and translations. Do not export an entire container filesystem, `.env`, private credentials or database backups into this directory.

Node.js 22 or later is sufficient; no package installation is required:

```sh
node runtime-config.mjs \
  --source /opt/mapa/releases/current-console-original \
  --output /opt/mapa/releases/cloud-console-001 \
  --origin https://mapa.your-domain.example
```

The output directory must not exist. Its parent must exist. The script validates the source before copying, refuses symlinks or overlapping source/output directories, and never changes the input. On a disk/copy failure, retain the failed output for inspection and use a new output directory for the retry.

Mount the complete output as the cloud web server's document root. Serving only the new JSON while using a different compiled bundle is insufficient for this release. The script changes the compiled environment JSON and the supported `fleetbase.config.json` overrides together, and gives the console script a cache-busting query. The filenames and third-party package names remain compatible. The old bundle source map is not rewritten; if publishing source maps, it describes the original pre-adaptation config.

The script does not execute the bundle. It narrowly parses the plain JSON export of `@fleetbase/console/config/environment`. It refuses unfamiliar/minified export layouts or integrity-protected scripts, rather than guessing replacements. A future build with a different format must be rebuilt with the correct settings or receive a reviewed adapter update.

## Gateway contract

For `https://mapa.your-domain.example`, the generated settings are:

```json
{
  "API_HOST": "https://mapa.your-domain.example/api",
  "API_NAMESPACE": "int/v1",
  "SOCKETCLUSTER_PATH": "/socketcluster/",
  "SOCKETCLUSTER_HOST": "mapa.your-domain.example",
  "SOCKETCLUSTER_SECURE": true,
  "SOCKETCLUSTER_PORT": 443
}
```

| Public path | Destination | Notes |
|---|---|---|
| `/api/…` | Native API, with the leading `/api` removed | Covers core `/int/v1/…`, extension API namespaces and API-generated download paths. Preserve query strings, cookies and Authorization. |
| `/mapa/mobile/v1/…` | Native API, unchanged path | Android and the Call Intake sign-in use the direct MAPA contract. |
| `/mapa/voice/v1/…` | Native API, unchanged path | Call Intake screen uses relative requests. Apply the gateway's public route policy; bridge ingestion can remain internal-only. |
| `/socketcluster/…` | SocketCluster service | WebSocket upgrade over HTTPS/WSS, port 443 externally. Preserve Origin and configure the server's allowed origin for the public host. |
| `/fleetbase.config.json` | Generated static JSON | Send `Cache-Control: no-store`. |
| `/assets/…`, `/engines-dist/…` and other real static files | Exported document root | Preserve the full original directory layout. |
| Other frontend routes | `index.html` fallback | Includes direct navigation to `/fleet-ops/connectivity/telematics`. |

The broad `/api` transport prefix is intentional: the native API and its extension namespaces remain unchanged behind it. The gateway still needs endpoint-specific restrictions for setup/registration/debug or other inappropriate public routes. Routing does not replace authentication.

**Socket publication gate:** the currently installed SocketCluster image is the upstream default server. Its client connects without a native-user authentication exchange and subscribes directly to user, chat and notification channel names. Before exposing `/socketcluster/`, add and verify server-side authentication plus per-channel authorization, or keep that public route denied. Origin validation alone is not channel authorization. A blocked socket route means live notifications/chat/position updates are unavailable even though ordinary authenticated HTTP screens can work.

Set the cloud API's public `APP_URL` to `https://mapa.your-domain.example/api` so generated file URLs use the same prefix, `CONSOLE_HOST` to the HTTPS origin, and its session domain to the public hostname. Configure Laravel's trusted proxy handling consistently with the selected gateway. Existing absolute `http://localhost:8000` URLs stored in database file/avatar fields do not change when the frontend is adapted; inspect and migrate those fields explicitly if they exist. Do not perform a blind database text replacement.

## Verified source and limits

Inspection of the actual served console on 2026-09-18 found version `0.7.59`, environment `development`, runtime configuration enabled, API host `http://localhost:8000`, socket host `localhost`, insecure port `38000`. `ember-cli-build.js` uses `storeConfigInMeta: false`, so the main application environment is inside `assets/@fleetbase/console.js`, not the index meta tags.

`console/app/utils/runtime-config.js` supports every generated key above. `console/app/initializers/load-runtime-config.js` defers boot while fetching the JSON. The served core adapter and fetch service capture `API.host` and namespace at construction; the served socket service copies `config.socket` at construction. Updating the embedded values too provides correct settings even if service construction precedes the async override or the runtime JSON fetch fails.

The original runtime loader caches production overrides in localStorage for one hour. This adapter gives `APP.version` a configuration-specific suffix so an older deployment's runtime config is not reused after a hostname/config change. It leaves the compiled environment mode unchanged: this is reuse of the current development build for a controlled pilot, not an optimized production rebuild. The production build defaults `disableRuntimeConfig` to true, so a future production build must explicitly enable it or bake in the final URLs.

The adapter does not modify login credentials, Android APK settings, database data, mail, telephony, clinical workflows or TLS. Cloud deployment acceptance must still test native administrator sign-in, authenticated API traffic, one real SocketCluster connection, a main fleet screen, Call Intake sign-in, and Android against the new origin. Preparing the assets alone does not establish that the cloud deployment works.

## Local preparation validation

On 2026-09-18, an isolated temporary export of the actual served `index.html` and all seven referenced JavaScript files passed the copy/adaptation check. The original bundle remained byte-identical, all other scripts remained byte-identical in the copy, and the generated environment and runtime JSON agreed on HTTPS API/WSS settings. Re-adapting the resulting bundle was idempotent. Existing output, an output nested under the source, an insecure origin, a credential-bearing origin and an unfamiliar bundle were rejected. Temporary validation files were removed afterward. `node --check runtime-config.mjs` also passed.

For the proposed, **not deployed** origin `https://mapa.rajamanu2.workers.dev`, the adapted console bundle's SHA-256 was `ccc848d656156235e4801adf5d9727ca5ec354e4900a68f7091b72599eafde54`. This checks the configuration transformation, not browser sign-in or a cloud server.

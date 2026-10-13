# MAPA client preview

This runs the current MAPA native console with a separate fictional demo database, cache, uploads volume, and viewer account. The existing local application at http://localhost:4200 remains independent.

## Private runtime files

`private/client-preview/demo.env` contains newly generated demo service credentials. `credentials.json` and `Client-login.txt` contain the client viewer login. These files are ignored by version control and are not served by nginx.

The public gateway permits GET/HEAD/OPTIONS and only the native login/logout POST endpoints. Other writes return 405. Public onboarding, bridge ingestion, debug endpoints, raw files and unauthenticated SocketCluster are denied. Live phone calls are disabled. MAPA's real service keys and original uploads are not supplied to this deployment.

The database starts empty. Its table definitions and permission names come from the installed app; all people, organization, ambulance, crew and hospital records are synthetic DEMO fixtures created separately.

## Availability

Client URL: https://mapa-client-preview.pages.dev/fleet-ops

The Pages relay forwards to the isolated local preview through a Cloudflare quick tunnel. Keep this PC, Docker and the preview containers running. The public Pages address is stable, but the upstream tunnel address can change if its process is restarted. This is a client evaluation preview, not hosting that remains online when the PC is off.

To stop the preview (preserving its demo volumes), run from the workspace:

```powershell
& "$env:LOCALAPPDATA/Programs/DockerDesktop/resources/bin/docker.exe" compose --env-file private/client-preview/demo.env -f fleetbase/client-preview/compose.yml stop
```

To inspect status, replace `stop` with `ps`. Do not use the original `fleetbase/asr/.env` for this preview.

If restarting the tunnel produces another hostname, update the fixed upstream in `relay.mjs`, copy it to `pages/_worker.js`, and redeploy the Pages relay. Keep MAPA_PREVIEW_ORIGIN/HOST set to the public Pages origin. The original app needs no changes.

Deploy from the workspace with the already authorized Wrangler login:

```powershell
node fleetbase/cloud/edge/node_modules/wrangler/bin/wrangler.js pages deploy fleetbase/client-preview/pages --project-name mapa-client-preview --branch main --commit-dirty=true
```

The new project is separate from the existing `asr-ambulance` Pages project. No existing Cloudflare project is deleted or overwritten.

## Native viewer navigation

The copied console needs `python fleetbase/client-preview/fix-viewer-navigation.py` after preparing `private/client-preview/console-pages`. This narrowly patches the known native bundle so login events see the resolved viewer permissions and abilities do not retain an empty permission snapshot. The script checks exact anchors and refuses unfamiliar bundle layouts.

Also run `python fleetbase/client-preview/fix-osm-attribution.py` on a fresh copied release. It supplies OpenStreetMap credit for that provider and keeps it visible at the top right of the map. Other tile providers retain their own options. Keep the gateway's origin-only referrer policy so ordinary browser tile requests meet the provider's identification requirements.

The viewer needs `see extension` and `see` permissions for the eight read-only resource types in addition to their list/view permissions. After changing these grants, invalidate the native `UserCacheService` entry for this viewer and demo company; clearing only the permission cache leaves the serialized user profile stale. The private provisioning script does both.

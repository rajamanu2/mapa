# MAPA native console server

This is the server for the selected MAPA application at http://localhost:4200/fleet-ops/connectivity/telematics. The same application contains ambulance calls, ambulances, drivers, hospitals and bases, maps, maintenance and telematics. ASR Health is the current operating organization.

## Existing installation

Start Docker Desktop with its WSL 2 Linux backend, then run the workspace `Start MAPA.cmd`. This starts the existing services and preserves their database and uploads. `Start MAPA Demo.cmd` also refreshes the five clearly marked fictional ambulance positions. It does not add patient data or real telephone contacts.

The launchers respect Windows script policy. If this workstation blocks unsigned PowerShell scripts, use an IT-approved signed launcher; do not disable Application Control or script protections. The container services can also be managed directly with the installed Docker CLI.

- Console: http://localhost:4200
- Native API: http://localhost:8000
- Adapter health: http://localhost:8000/mapa/health
- Scoped fleet readout: http://localhost:8000/mapa/v1/fleet

The API, console and realtime service bind to this PC only. Database ports are not published. A phone or another PC cannot use this computer's localhost address.

## Fresh server installation

The Windows executable is a client for this server. It does not embed Docker, MySQL, Redis, the API or a production hosting account. A shared installation needs one administrator-managed server and reachable HTTPS endpoints configured for the console and Android app.

For a fresh local database, install/start Docker Desktop and use `Initialize-MAPA.ps1` in this directory. It generates unique local secrets, builds the source and runs upstream initialization. Complete the normal console onboarding. Do not distribute the generated `.env`, private credentials, database exports or signing keys.

After creating your organization, set `MAPA_COMPANY_UUID` to its native company UUID. `MAPA_ADAPTER_TOKEN` is a server-only integration credential. Changing it requires restarting the API workers. Native driver/user mobile credentials are separate from this adapter token. Never embed an organization API key or adapter token in an APK.

The local build was tested against the existing runtime snapshot tagged `mapa-runtime-base:2026-09-17`, using the Docker build argument `MAPA_API_BASE`. A fresh build defaults to the upstream base in the Dockerfile and requires internet. Pin and validate a production image digest before distribution. The source archive is not an offline container-image bundle.

## Demo records

`seed-mapa-demo.php` creates or updates only the owned `mapa-demo-v1` dataset: five ambulances, five linked drivers, five non-login users, five fictional hospitals/bases and five position records. Every resource has an unmistakable DEMO name and metadata marking simulation. The five stations are Paderu, Araku Valley, Chintapalli, Koyyuru and Munchingiputtu.

Run the MAPA Demo launcher before a presentation. Repeating it refreshes the existing positions without duplicating records or inventing a travel history. Do not present those positions as live GPS. The 11-mandal viewport is an operational overview, not a surveyed district boundary.

## Fleet readout contract

The canonical contract is `mapa-v1`. It returns company-scoped native vehicle IDs and the latest stored position, including `simulated` and the `mapa_position_created_at` time source. Position time is server receipt/storage time, not a guaranteed device capture time. Unknown speed stays null. Authentication and pagination are checked server-side.

The old `/asr/v1/fleet` route is a compatibility alias. It retains its previous contract and excludes DEMO vehicles because older consumers may not understand simulated GPS. Existing database names, volumes and upstream package namespaces are technical compatibility details; they are not the product's display brand.

`verify-mapa.py` runs read-only local acceptance checks using the ignored `.env`: adapter health, missing/invalid credential rejection, five marked demo units, district positions, invalid pagination and legacy demo exclusion. It never prints credentials.

## Android and native call intake

The Android 1.11 pilot and Call Intake panel use this console's own Laravel API, company, users, drivers, vehicles, orders and places. They do not use the stopped port-8791 application. Android signs in at `/mapa/mobile/v1/login` with an explicitly provisioned native role binding and a phone-reachable HTTPS origin. Its English/Telugu crew UI supports duty, real-device GPS, acknowledgement and crew-confirmed stages for the supported single-pickup/single-destination ASR workflow. Independent hospital-account acceptance and extended workflows still use the console. Assignment alerts use an active app or explicitly started duty service; optional Firebase delivery needs configuration, a configured build and physical acceptance testing. See [the native mobile contract](mobile/README.md).

The console's **Call Intake** panel signs in separately through that same native login with an approved administrator or super administrator. Its bearer token stays only in page memory; it does not read browser login storage or bypass native MFA/reset restrictions. The panel uses `/mapa/voice/v1` and creates the same native order that the crew app reads. Crew/hospital accounts cannot operate telephone intake.

The native voice module accepts authenticated bridge events and unverified transcripts/drafts. An operator confirms the callback number, reported problem, pickup address/coordinates, landmark, priority, ambulance capability and native destination. Only after review can the operator select an eligible ambulance and explicitly confirm dispatch. A later transcript invalidates a prior review. The app reports success only after a server receipt and preserves an uncertain request's action ID/body for retry.

Candidate distance is straight-line distance, **not a road route or arrival estimate**. Historical DEMO calls use only marked DEMO resources; live candidates require eligible on-duty native crew/vehicles and fresh device GPS. The selected crew receives the same native order while the app or duty service is active, then explicitly acknowledges it. A fresh server-received GPS report after acknowledgement is required before starting a supported trip. Crew-confirmed hospital handover is distinct from independent receiving-team acceptance. Firebase is not configured in the current shared installation, and automatic reassignment remains unavailable. Voice writers are serialized, but independent native manual assignment paths do not yet share a global reservation protocol.

## Exotel voice bridge and current validation boundary

`mapa-voice-bridge` connects Exotel Voicebot audio to Sarvam speech recognition and the native `/mapa/voice/v1/bridge/events` endpoint. It uses fixed English/Telugu prompts and a durable private retry journal. Optional server-side extraction suggests text fields only; it cannot choose a priority, map point or ambulance. The bridge itself cannot create or dispatch an order.

Local synthetic checks cover the bridge protocol/retry journal, native voice policy and temporary-schema order/crew-ACK integration, and the console panel's validation/retry rules. The bridge's local demo makes no provider requests, real calls or native database changes. In contrast, the console's authenticated DEMO flow deliberately creates a marked native training order when an operator confirms dispatch.

**Installed local status, 18 September 2026:** the additive voice migration is installed, the console serves Call Intake, and 119 HTTP checks through its same-origin API passed, including fictional native dispatch and crew-API acknowledgement. The entry and sign-in dialog were visually checked; authenticated operator browser acceptance remains. The bridge is healthy with live calling disabled. A real Exotel call, public WSS ingress, Sarvam speech quality, staffed human fallback and a physical Android end-to-end test remain uncommissioned.

The selected September 30 launch workflow uses operators answering ASR Health's existing phone number outside MAPA, then entering and reviewing **New Call** details. `MAPA_VOICE_ENABLED` exposes the module and `MAPA_VOICE_MANUAL_ENABLED` enables manual intake independently of `MAPA_VOICE_LIVE_ENABLED`. Manual calls need no Exotel activation, speech service or audio stream. Operators confirm the pickup pin and destination, then select and dispatch an eligible crew. The current reviewed configuration disables new training calls regardless of older environment values.

Optional automatic provider capture requires an enabled Exotel account/number and Voicebot access, public WSS, a Sarvam key, the matching server-only bridge credential, phone-reachable HTTPS and a tested human **Connect** applet after Voicebot. A normal phone can call the provider number; an existing inbound number needs a carrier-supported forwarding or provider-onboarding arrangement. A SIM alone does not supply the required stream. These requirements apply to provider capture, not manual New Call operation.

See [the native voice contract](voice/README.md), [the bridge setup guide](../../mapa-voice-bridge/README.md) and [the operator/provider guide](../../mapa-delivery/docs/VOICE-SETUP.md). Provider setup follows the [official Exotel Voicebot documentation](https://docs.exotel.com/exotel-agentstream/voicebot-applet). The static panel assets are `console/public/mapa-voice.js` and `mapa-voice.css`; `console/scripts/install-mapa-voice.sh` installs them and injects index tags idempotently into an already-built console. The deployment owner supplies the same-origin API proxies and performs the rollout.

## Branding and distribution

MAPA is the product name. MAPA.DEV identifies original MAPA additions. Accurate upstream copyright and license notices remain available under Legal and in the source. `console/scripts/mapa-customize.mjs` applies the presentation overlay while retaining native models, routes and dependencies.

The MAPA Legal window is served by `console/public/mapa-legal.js` and `mapa-legal.css`. It shows the MAPA identity first and keeps original dependency notices in the expandable third-party section. The complete licence and reviewed source archive are available as local downloads. For a fresh build from the source ZIP, copy that ZIP to `console/public/mapa-source.zip` before building; do not substitute a ZIP of a working directory containing private environment files. The delivery assembler generates this reviewed download automatically in the development workspace.

To update these static Legal assets on an existing console without rebuilding Ember, tag the tested console image as a rollback base and use `asr/Dockerfile.console-legal` with the console directory as its build context. Its `MAPA_CONSOLE_BASE` argument selects that local base. Normal console builds include the same assets and index references. Only the console container needs replacement; the API and database are unaffected.

The source includes deployment and demo tools, not production credentials. Before commercial rollout, complete native mobile/role acceptance, HTTPS and identity setup, backup/restore tests, release signing and clean-PC installation tests. Provider commissioning is required only if optional automatic telephone capture is enabled. The Windows pilot installer is unsigned; this workstation's Application Control blocked an earlier installer execution. No security policies were weakened.

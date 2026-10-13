# Live-access validation — 2026-09-18

Application: https://mapa-asr-health.pages.dev/fleet-ops

Backend: existing ASR Health workspace. No cloned demo database is used.

## Passed

- Access-checker unit tests: anonymous denial, administrator allowance, company/status/role restrictions, cookie-only HTTP denial, forged/expired/revoked socket grants, and upstream failure handling.
- Public acceptance: **25 checks passed** using `verify-live.py`; its temporary native test token was revoked successfully.
- Public health, native console, runtime configuration, branding and login bootstrap returned 200.
- Anonymous operational reads, writes and socket access returned 401.
- Environment/private paths, sign-up, bridge ingestion and provider media returned 404.
- Native administrator profile, session/bootstrap, vehicles, drivers, places, orders, users and map settings returned 200. The profile matched the original active ASR Health administrator and original company.
- An authenticated POST with the required order configuration deliberately omitted returned the native 422 validation response. The controller validates this before record creation. This proves the public path forwards authenticated saves to native validation; it is not an end-to-end dispatch acceptance test.
- Authenticated public WebSocket upgrade returned 101.
- The native map controller's null-key fix passed PHP syntax validation and the public map-settings request.
- Browser opened the MAPA sign-in page at the new public hostname, with email/password fields and MAPA Legal footer visible.

No operational records were created, edited, dispatched or deleted by acceptance. No calls, emails, SMS or notifications were sent.

## Still pending

- The user must complete normal browser password sign-in with the original administrator account; API checks used a temporary native token and do not verify the password UI. The password was not read or reset.
- Review the authenticated full menu in that browser session, and perform any authorized real workflow acceptance with the operator. No claim is made that every native screen/integration has been tested.
- A separate always-on server, persistent backups and an actual PC-off availability test. Current API/database/connector run on this PC.
- Real telephone provider commissioning, verified number and human fallback, plus a controlled end-to-end phone test.
- Physical Android device, GPS/notification and remaining crew/hospital workflow acceptance against the public origin.

The full native web application's functionality is available according to existing permissions. This deployment does not make unconfigured external integrations operational or certify the system for emergency service use.

## Account-recovery repair — 2026-09-19

- Reproduced the reported signup failure: disabled registration returned an HTML 404 body to a JSON client.
- Blocked signup now returns status 404 with a readable JSON `errors` array, with no new user or company created.
- Direct `/onboard` navigation follows an HTTPS redirect to `/auth`.
- Public native verification validation returns `is_valid: false` for a nonexistent password-reset ID and `is_valid: true` for the authorized owner's newly prepared 15-minute native link. The one-time link remains in a private local file.
- Anonymous operational profile access remains 401; public health and native bootstrap respond successfully.
- The adapted live console passed JavaScript syntax validation. Nginx configuration passed validation and was reloaded without restarting the API or database.
- Browser automation timed out; visual confirmation of the reset form and the user's password submission remain pending. The assistant did not read, enter or change a password, and no notification was sent.

## Customer access and branding — 2026-09-19

The user subsequently requested a newly generated customer username/password and a real login test instead of further owner recovery.

- Created only the dedicated ASR Health customer identity and active membership, with native company Administrator role. Native `is_admin` remains false. Original owner credentials were not changed.
- Actual public password login, current profile, session/bootstrap, vehicles, drivers, places, orders, users and map settings passed. An intentionally invalid order still returned 422 without creating a record.
- All 23 additional operational and configuration read endpoints in `CUSTOMER-FEATURE-RESULTS.json` returned 200.
- An empty request to the customer-blocked account-password-management route returned the gateway's JSON 403. Unit checks also cover other identities, inactive membership, foreign company, wrong role, encoded paths, and preserved owner access.
- Added the one explicit Call Intake admin binding. Same-password login, capabilities, snapshot and calls reads passed; its API test session was revoked. No phone call, provider activation, dispatch or operational-data mutation was performed.
- Browser sign-in visibly reached MAPA command centre as MAPA Customer Admin. The operations map, resource/maintenance/connectivity/settings navigation and native new-ambulance-call form rendered. The separate Call Intake dialog also signed in successfully as MAPA Customer Admin and listed the existing clearly marked training calls.
- Fixed the branded operations header identity: its visible title remains MAPA Operations while its permission ID stays `fleet-ops`. Reloading the customer browser now shows MAPA Operations, IAM, Ambulance Calls, Hospitals & Bases and Drivers in the top navigation. No permission expansion was required.
- Sign-in heading displays "Sign in to MAPA". Public branding returns `/mapa-icon.svg` and `/images/mapa-logo.svg`. Native entry, shared icon, portal and first-party legal/footer branding patches are applied, while required third-party notices remain.
- Password is handed to the user separately and is absent from public code, archive and validation output. Only authorized customer operational testing is enabled; account-owner/security writes stay protected.

This verifies customer access and representative web workflows, not every possible native operation. Live hotline intake, extraction, push notifications and road ETA are still false in the server capability response. Physical Android and connected-provider commissioning remain separate acceptance work. The installation still depends on this PC and Docker.

## Crew sync, operator desk and startup animation — 2026-09-19

- Android pilot 1.9.0 (code 11) defaults to the same public MAPA origin. Its APK passed v2/v3 signature validation with the previous pilot signer, Java compilation, 108 contract checks, 72 crew/evidence checks and 32 reliability checks. The public APK bytes matched SHA256 `5289e433a57c3bb185bace99c8828b28fa6812b8020e429b789652a1dee7e374`.
- Public mobile acceptance passed 120 checks across crew, hospital and administrator accounts without GPS/duty/dispatch mutations; all test sessions were revoked. Physical-phone acceptance remains pending.
- Native isolated integration passed 99 mobile checks and 61 voice checks, with 58 native foreign-key checks in the voice suite. The temporary-table tests wrote no production rows and sent no notifications. The local GeoNames location search passed 47 isolated checks.
- The public operator page signed in with the existing customer account, returned Paderu map candidates, and saved one explicitly fictional `DEMO Operator Acceptance` manual call into `needs_review`. It was not reviewed or dispatched. Unknown clinical observations stayed unknown; pickup confirmation remained unchecked.
- The administrator's Crew locations view loaded the exact DEMO Mobile Crew binding. It correctly displayed zero newly accepted fixes, rather than fabricating a travel path. A phone must now submit real GPS observations during the authorized test.
- Browser inspection showed the MAPA / ASR HEALTH ambulance startup animation and then the usable authenticated console. Lifecycle tests cover cleanup and reduced-motion behavior. Local port 4200 and the public console serve the updated loading and operator assets.
- The temporary Cloudflare connector expired during final browser checks. It was restarted and the existing Pages relay was updated. The stable hostname remained unchanged. Subsequent public health, auth, loading and operator-page checks returned 200; anonymous native and mobile protected endpoints returned 401.

The current source supports manual operator intake, explicit reviewed dispatch, shared native assignments and bounded device-location evidence. Real telephone capture, FCM, Android native activity/completion actions, physical-device acceptance and PC-independent hosting remain outstanding. Device GPS is evidence of reported device observations, not guaranteed proof of a person's travel or completed patient transport.

## Native call creation and requested Super Admin — 2026-09-19

- Reproduced the native create-call drawer with overlapping heading, actions and map credit. The disabled Create action became enabled after selecting the required existing DEMO pickup and receiving hospital.
- Submitted one fictional native call through the actual public browser form as the original customer Administrator. The server saved `order_jle2e5jp2j`, internal ID `DEMO-NATIVE-CALL-20260919-CHECK`, and the browser displayed the saved call detail. Exact guarded metadata tagging identifies it as `mapa_demo=true`, `simulated=true`, dataset `mapa-native-create-acceptance-20260919`. It remains created, unassigned and undispatched; no driver was selected.
- At the user's explicit request, provisioned a separate `customer.superadmin@mapa.invalid` identity with true native `type=admin` / `is_admin=true`, ASR Health company membership, and an exact UUID-allowlisted mobile Super Admin binding. Existing owner and customer credentials were unchanged. Credentials are held only in the private access file, not this report or the source offer.
- Verified the new account using actual public password login, native admin-only overview, platform organizations and metrics, operational reads, mobile capabilities/snapshot and Call Intake. Exact API test sessions were revoked. Both API containers were recreated to load the changed mobile allowlist; database, queue, console and tunnel remained intact.
- Browser password login opened MAPA as Customer Super Admin. The ASR Health menu exposed Admin, and `/admin` rendered the Organizations, Branding, 2FA Config, Platform API Token, Schedule Monitor, Fleet-Ops Config, Extensions Registry, AI Config and System Config navigation.

This Super Admin account can change installation-wide administrative settings and users. Its MAPA mobile/Call Intake binding remains scoped to ASR Health. Granting it access does not commission external providers.

- The final public browser check confirmed the call title and actions occupy separate rows, the form body scrolls beneath them, and OpenStreetMap credit is visible at the bottom-left of the map without covering controls. The native button is labelled `Create call`, with a pickup/drop-off requirement hint. Local static assets matched the reviewed layout deployment.
- AI Provider Settings now visibly displays `MAPA AI` and `Enable MAPA AI`. Related Open/Close/Ask/accessibility labels were updated in the AI bundle and source build hook. The AI enable switch remained off; no provider settings or credentials were changed.

## MAPA administration branding — 2026-09-19

Public and local static assets expose MAPA Operations Config with the existing native fleet-ops admin slug intact. Product-owned documentation panel text is neutral; upstream documentation/support destinations and notices remain accurate. App, vendor, and engine JavaScript syntax passed; repeated label patching is idempotent. Eight public/local HTTP checks confirmed branding and retained caller-phone, MAPA AI, loading and layout assets.

After the coordinated API recreate, the existing Super Admin password login and protected mail-config read confirmed `fromAddress=hello@mapa.dev` and `fromName=MAPA`. The response was filtered in memory; provider settings were not output or changed, no test email was sent, and the exact verification token was revoked. `set-mapa-mail-defaults.py` changes only absent/upstream defaults in the private environment and preserves custom values. The local compose log-mailer retains its explicit `pilot@example.invalid` sender.

The public browser also visibly confirmed MAPA Operations Config, `hello@mapa.dev` and MAPA on the Mail Configuration screen. No Test Config action was invoked.

## Caller number handover — 2026-09-19

- Native caller-phone validation and privacy checks passed 66 assertions; isolated native mobile integration passed 108 checks. Frontend normalization and metadata checks passed 36 assertions before the saved-call editor follow-up.
- Both APIs and the native queue/scheduler were recreated with the reviewed resource overrides and reached healthy state. Database, cache, uploads and connector were preserved.
- The public new-call form visibly rendered Caller phone number and its assigned-crew guidance. A malformed number displayed an inline validation error; a formatted international number cleared the error. The heading, action buttons and map attribution remained correctly separated.
- `verify-caller-phone-live.py` tested only the existing, guarded `order_mapa_mobile_demo_01` fictional assignment. An invalid native PUT returned JSON 422 without persistence. A formatted number saved as `+12025550142` and reached the exact assigned crew snapshot. An explicit empty value removed the callback; the fictional number was then restored for the demo.
- Exact company, driver/vehicle assignment, call status and unrelated metadata were preserved. Deployed public, customer-portal and webhook serializers excluded caller numbers while native operator serialization retained them. All acceptance tokens were revoked. No duty, GPS, assignment, dispatch, real phone call or notification action was performed.
- APK 1.9.0 already renders `dispatch_details.callback_number`; no APK replacement is required. Physical-device display acceptance remains pending.

## Final product-copy audit — 2026-09-19

The final compiled catalog audit covers all 16 supported console locales. Product translation values now contain no Fleetbase or Fleet-Ops labels; keys and native identifiers are preserved. Reviewed literal UI labels now use MAPA for the IAM policy statistic, native telematics loading warning/vendor tooltip, customer invoice footer and refund handoff text. Invitations, webhook help/dialogs, map/routing/notification headings, operational widgets and Storefront introduction were included. Upstream blog/repository descriptions, source notices, registry/CLI credential names and the externally hosted Merchant Backend remain accurately attributed.

The source overlay paths were verified against official installed-version package archives without executing dependency code. Pure source translation checks and compiled patch idempotence passed; module names, model namespaces, upstream URLs and unrelated engine manifest entries were invariant. The final locale pass changed only vendor assets and their script URI, retaining the latest native caller-phone host re-export and engine. No authentication, provider configuration, messages or dispatch state changed.

### Saved-call editor and optional sections

- Actual public browser Edit rendered the caller field after its host-app component export was installed. Entering a fictional number and cancelling restored the previous absent value. Reopening and saving `+1 (202) 555-0142` closed the dialog successfully; a separate read-only native check confirmed persisted `+12025550142` on `order_jle2e5jp2j`, original DEMO metadata, created status, no driver/vehicle and no dispatch.
- Final phone frontend checks passed 38 normalization/metadata assertions and 9 hydration/cancel assertions. Compiled and clean-source installers passed byte-idempotence checks, including the host component export.
- Read-only native validation/configuration audit confirmed documents and orchestration constraints do not block creation or crew assignment. Both the unassigned browser-created DEMO call and the existing assigned DEMO mobile call have zero files, no scheduled time, no required skills, no time-window start/end and no required proof of delivery. Priority defaults to 50. ASR Ambulance Response has no required custom fields or metadata. Native assignment requires order and driver identifiers; no documents or time windows.

Final HTTP acceptance: all ten public/local asset checks passed, including all 16 catalogs, MAPA IAM/invoice/telematics copy, the retained caller-phone host cache version, loading/layout assets and accurate upstream notices. Public and local vendor JavaScript plus the clean-build overlay passed syntax checks. Account/install locale values were included; product install guidance uses neutral application CLI wording.
- New-call Documents & Files and Orchestrator Constraints panels now have explicit optional labels, start collapsed, and explain that neither is needed to create a call or assign crew. Existing-call panels retain their previous behavior. Scoped source/compiled installers, JavaScript/Python syntax and byte-idempotence checks passed; runtime ContentPanel supports an explicit false open state.
- Final public browser verification confirmed both optional headers are collapsed on a fresh new-call form. Expanding Documents displayed the optional guidance; collapsing it removed the upload controls again. No extra call was submitted during this check.

## Key-free ASR pickup search — 2026-09-19

- Rebuilt the local GeoNames index from the existing official India archive: 7,457 regional records covering settlements, facilities, administrative areas, roads and natural features where the source supplies them. The 17.40–18.75 / 81.80–83.35 rectangle includes neighbours and is not an official district polygon. Added English/Telugu aliases for the current 11 mandals and five source-linked public-place address facts without invented coordinates.
- Local typeahead remains local. Explicit Search map or Enter adds bounded OpenStreetMap/Photon results without an API key. Only the public place query is sent, with India/bounds filters, result validation, shared request throttling, caching and a six-second timeout. Unavailable online search retains the local results and manual pin workflow. Attribution is visible.
- Passed 56 local location assertions, 20 mocked Photon transport/validation/cache assertions, 18 frontend lifecycle/search checks, 19 stubbed optional Google adapter checks and four map-configuration assertions. The existing 109 operator pure checks passed during integration. These checks do not prove provider availability or complete district coverage.
- Published reviewed operator assets to the existing local and public consoles and reloaded both API worker pools. The public customer account signed in successfully at `/call-intake/`. Searching the original failing query `itda` returned ITDA Paderu, I.T.D.A Quarters and the address-only ITDA Office directory entry. Selecting ITDA Paderu visibly filled 18.088168 / 82.667449 and rendered its map pin. The interface continued to require caller confirmation. No call was saved, reviewed, assigned or dispatched during this acceptance check.
- No Google key was persisted or Google service activated. The optional adapter does not block the active key-free search. The source offer includes the provider adapter, expanded dataset, provenance, tests and pickup-search operations notes.

Coverage remains source-dependent: small hamlets, new buildings and exact entrances may be absent or inaccurate. Photon’s public service is best-effort; a scaled deployment needs provisioned geocoding capacity. This update does not change the existing PC/Docker hosting dependency, telephone provider commissioning or crew dispatch safeguards.


## Embedded dynamic pickup and call progress — 2026-09-19

- Removed the external Google Maps search action. The embedded map supports direct pin placement and dragging. Coordinates and a generated pickup description update immediately; local nearby-place context enriches the description without moving the selected pin. Operator-entered addresses are preserved. No external reverse geocoder receives the selected point.
- Added visible progress for pickup selected, call saved, review confirmed, crew dispatched and crew acknowledged. The saved call queue includes pickup details and coordinates. Changes to reviewed inputs invalidate displayed review completion and eligibility as before. Crew acknowledgement continues to come only from the native backend.
- Passed 20 dynamic-pin/lifecycle/progress checks, 18 local-search UI checks, 18 optional Google SDK stub checks, 109 operator contract checks and 62 local location assertions. The isolated native suite passed 63 checks and 58 native foreign-key checks with zero production rows written and zero notifications; it includes administrator/crew authorization for the new local context endpoint.
- Actual public-browser acceptance: searched ITDA Paderu inside MAPA, selected its marker, then dragged it. The fields updated to 18.096965 / 82.670924 and local context resolved to near Talarsing, 535 m from its mapped settlement centre. Saved one explicitly fictional `DEMO Pin Workflow` intake at 17:57 IST. The queue and subsequent server-loaded review displayed the same coordinates/address, with Pickup selected and Call saved complete. Caller confirmation remained unchecked; no review, crew assignment, dispatch, real phone call or notification was performed.
- The map no longer uses sticky positioning over the form footer. The complete reviewed source and tests are included in the source download. Nearby names remain approximate; exact entrance, village, mandal and access instructions require caller confirmation.


## Native sidebar entry to the operator desk — 2026-09-19

- The exact native sidebar Create new ambulance call action now opens `/call-intake/` in the same browser tab. The floating NEW CALL link uses the same destination and tab behavior. A narrowly scoped capture handler replaces only that sidebar action's old native create-order transition; disabled/aria-disabled controls and unrelated buttons retain their behavior.
- JavaScript syntax passed. Live browser verification clicked the visible sidebar button from `/fleet-ops` and reached the MAPA operator desk in the same tab. The existing administrator sign-in boundary is retained. No call, review or dispatch was submitted for this navigation check.
- Published matching source/public/local console assets with refreshed cache versions. The expanded search, draggable pin, automatic pickup details and progress tracker are shared unchanged by both entry points.

## DEMO mobile ambulance assignment eligibility — 2026-09-19

- Diagnosed the available-but-unassignable DEMO Mobile Ambulance: the crew was active and reporting fresh location, but the original `order_mapa_mobile_demo_01` training fixture still reserved the driver and vehicle in dispatched status. The operator's newer reviewed DEMO call remained ready and unassigned.
- `release-mobile-demo-assignment.py --release-exact-demo` guarded the exact ASR company, order, driver, vehicle, fictional dataset marker, original assignment, dispatched status and unstarted state before invoking native cancellation. It retained assignment and cancellation history, sent no notifications and changed no GPS data or real calls. It refuses unrelated records or a started assignment.
- The API and operator desk now explain unavailable ambulances using the same eligibility checks as dispatch: existing assignment, duty status, crew access, vehicle link, required capability and recent GPS. The existing dispatch transaction rechecks eligibility; no gate was bypassed. Refresh eligibility is also visible when an ambulance is available.
- JavaScript/PHP syntax and 109 operator checks passed. The isolated native suite passed 66 checks and 58 foreign-key checks, with zero production test rows written and zero notifications. Added assertions cover stale GPS, missing crew eligibility and the exact blocking order reference.
- Published to both existing consoles and reloaded both API worker pools. The actual public operator desk loaded `DEMO kumat` in ready status, displayed DEMO Mobile Ambulance — Paderu, and accepted its radio selection. Expanded reasons correctly explained the other six blocked DEMO vehicles. Dispatch confirmation remained unchecked; no new assignment or dispatch was submitted during verification.

## MAPA changelog — 2026-09-19

- The native changelog now reads the deployment-owned `/mapa-changelog.json`, containing MAPA release notes for the implemented operator, pickup, assignment, crew and administration features. It no longer loads unrelated upstream release announcements or presents upstream component versions as MAPA releases.
- Updated the maintained component, clean-build customization and public preparation hook. The compiled patch changes only the native changelog data source and vendor cache version; dependency identifiers and third-party notices remain intact.
- Public and local JavaScript syntax and scoped patch idempotence passed. Both consoles returned the exact UTF-8 release-note JSON and refreshed script version. Live browser verification opened Changelog and displayed both MAPA sections without Fleetbase or FleetOps product names. No API restart, account change or operational write was required.

## ASR pickup search and automatic form fill — 2026-09-29

- ASR and nearby areas remain the default. The existing 7,457-record regional directory and explicit online OpenStreetMap/Photon search remain key-free. The optional India scope removes the regional bound and ranking bias, with separate cached results and the same fixed provider, country validation, timeout and rate limits.
- Explicit result selection now replaces the old pickup address and both coordinates together. Provider locality is copied when available; unsupported old locality/mandal values are cleared. County/district names are not guessed to be mandals. Manual address edits remain protected from later passive nearby lookups.
- Passed 109 operator-contract, 32 search/transport, 20 dynamic-pin, 19 optional Google stub, 65 local-search, 27 Photon mock and 4 configuration checks. Public health, auth and branding returned 200; anonymous current-user access remained 401. Browser tests on the public operator desk verified ITDA Paderu and Araku results and address/locality/coordinate transfer, plus Hyderabad results with optional India scope.
- Updated static files in both consoles and gracefully reloaded the existing API workers. Google Maps remains unconfigured, with no Google key, billing or account added. The source offer was refreshed. Verification used an unsaved form and temporary login sessions; no call or dispatch was created.
- Coverage is source-dependent, not an exhaustive or surveyed ASR register. Operators still confirm the actual entrance, mandal and access directions with the caller before review and dispatch.

## ASR landmark coverage and Araku station — 2026-09-29

- Added a reproducible, sanitized OpenStreetMap regional index containing 11,074 named records alongside the existing GeoNames directory. The snapshot includes 46 railway stations, 282 bus stations and 375 hospitals. The rectangular source area includes neighbouring districts; these counts are not an exhaustive or deduplicated ASR register. Source timestamp, query, bounds, checksum and ODbL attribution are retained.
- Search now indexes landmark categories and station codes, handles railway/train/rly/stn variations and Latin accents while preserving Telugu marks, and displays up to 32 local plus 8 online candidates with a count when further local matches exist. Araku railway station, Araku train station, Araku rly stn, ARK and the Telugu station query are covered by regression checks.
- Passed 9 dataset-builder, 141 local-location, 27 Photon, 109 operator-contract, 35 live-search, 20 dynamic-pin and 19 optional Google adapter checks. Google remains unconfigured. Public health, auth and anonymous branding returned 200; anonymous current-user access returned 401.
- Deployed reviewed assets to both existing consoles and gracefully reloaded both API worker pools. In the actual public browser, typing `Araku railway station` returned Araku railway station (ARK) and Araku Valley railway station (ARKV). Selecting ARK filled the address and coordinates 18.332922 / 82.865258 and rendered an adjustable pickup pin. The confirmation guidance remained visible. Verification used an unsaved form; no call, review, assignment or dispatch was submitted.
- Unmapped hamlets, recent buildings and exact entrances still require operator-confirmed pins and maintained local source data. The source offer includes the builder, sanitized index, provenance and tests.

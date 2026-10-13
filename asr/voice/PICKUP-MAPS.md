# ASR pickup search — no API key required

## Regional landmark coverage update — 2026-09-29

The local search now combines the existing 7,457 GeoNames records with a separate **11,074-record OpenStreetMap regional index**: 9,061 settlements plus mapped stations and other named facilities/landmarks. The same ASR-area rectangle includes neighbouring districts; these counts are not counts of ASR-only places or unique villages across both sources. The OSM snapshot was fetched at source timestamp `2026-09-29T10:45:00Z`.

This includes 46 railway stations, 282 bus stations, 375 hospitals and 198 schools across the regional rectangle. Search aliases combine the recorded place name with its mapped category and railway code. **Araku railway station**, **Araku train station**, **Araku rly stn**, **ARK** and **అరకు రైల్వే** all resolve locally to [OSM node 2523199054](https://www.openstreetmap.org/node/2523199054), using its mapped coordinates rather than a town-centre guess. Latin accents are normalized while Telugu vowel marks are preserved.

Up to 32 local results plus eight online candidates are returned, deduplicated by source identifier. Local match counts are displayed when there are more results, with a prompt to add a village or landmark name. No map provider call is needed for local typeahead. Selecting a station/landmark still fills the address and pin, never creates a call or confirms the entrance.

`asr-landmarks.json` contains names, categories, public addresses, source identifiers, coordinates and provenance only. It excludes phone/contact tags. Ways and relations use the supplied feature centre, labelled as a mapped candidate rather than a surveyed entrance. `build-asr-landmarks.py SNAPSHOT.json --download` refreshes the fixed bounded public Overpass query; incomplete responses are rejected. The index is available in the reviewed MAPA source offer under OpenStreetMap's ODbL attribution. It is separate from the GeoNames CC BY4.0 index.

After replacing the index, gracefully reload the API's Octane workers to refresh their in-memory search cache. Test the missing place explicitly before reporting a coverage fix. Missing or outdated map features still require caller-confirmed pin placement; this is not an exhaustive district register.

The operator desk searches 7,457 GeoNames records in a regional rectangle (17.40–18.75 north, 81.80–83.35 east). These include settlements, administrative areas, facilities, roads, terrain and water features, where recorded by the source. The rectangle includes neighbouring locations; it is not an official district boundary or an exhaustive register. `asr-directory.json` adds five selected public-place names/localities and English/Telugu aliases for all 11 mandals listed by the current ASR district website. The directory does not supply building coordinates.

Typing searches the local dataset after a short debounce. Pressing **Search map** or Enter also searches OpenStreetMap through Photon, with no provider key required. Mapped results appear before address-only directory entries. Results remain candidates: none confirms a pickup or dispatches a crew automatically.

**Search area** defaults to **ASR district and nearby areas**. **Anywhere in India** removes the regional rectangle from the online request while retaining the India country filter. Choosing another area reruns a valid query; late responses from the previous area are ignored. Local typeahead remains the ASR directory in either mode. Include a town or village name to distinguish similarly named landmarks. This broadens coverage; it is not a promise that every building or hamlet is mapped.

Selecting a mapped result fills its clean pickup address, latitude and longitude together, replacing any previous selection or address. Village/locality is filled only when supplied by the source; an old locality and mandal are cleared when they cannot be supported by the new selection. District/county labels are never guessed to be a mandal. Source/candidate labels remain on search results rather than in the address field. Manual edits after selection remain possible, and passive nearby lookups preserve them. No call is saved or dispatched by selecting a location.

Searching **ITDA Paderu** online returns mapped candidates including **ITDA Paderu** and **I.T.D.A Quarters**. The local directory also returns **ITDA Office, Paderu**. Selecting an address-only result clears any previous pin. Operators must locate and confirm the entrance before reviewing and dispatching. Hospital directory results do not create native hospital resources or imply a facility can receive patients.

## Online search and fallback

The authenticated location endpoint accepts `online=1` only for an explicit search and `scope=asr|india` (default `asr`). It sends only the typed public-place query to the fixed Photon HTTPS endpoint, with India country filtering and optional regional bounds, an eight-result limit, a two-second connection timeout and six-second total timeout. Returned coordinates, source IDs and country are validated. Invalid results are excluded, as are out-of-region results in ASR mode. Cache keys include the scope to prevent mixing regional and India results. The browser shows locality/state and source information to help distinguish similar names.

Positive queries are cached for 24 hours and empty results for five minutes. A shared cache gate allows at most one uncached upstream request every two seconds. Busy, unavailable or malformed provider responses preserve local search and manual pin entry. `MAPA_PHOTON_ENABLED=false` disables online lookup. Photon’s public service has no availability guarantee; a larger deployment should provision its own geocoder instead of relying on this community endpoint.

GeoNames and OpenStreetMap attribution is retained in the interface. Search is not a complete directory of every ASR hamlet, building or road, and map coordinates are not verified entrance locations. Caller confirmation is required before dispatch.

## In-app pin workflow

The native sidebar’s **Create new ambulance call** button and the floating **NEW CALL** action both open `/call-intake/` in a new browser tab, preserving Operations. The operator desk NEW CALL button also opens a new desk tab without discarding the current draft. They use the same embedded pickup map, draft fields and review/dispatch progress. Disabled native actions remain disabled; the operator desk still enforces its existing administrator sign-in and backend permissions.

The operator can select a pin inside MAPA or choose **Open Google Maps**. The key-free browser link preserves `authuser=0` and sends only validated place search text, without the ASR district filter. Google authentication remains in each operator’s own browser; the VPS stores no Google password or session. Copy the exact pin’s decimal latitude and longitude and use **Paste Google Maps coordinates**. Ambiguous URLs and viewport centres are rejected. Enter applies the pin without submitting the call form. A manually typed pickup address is preserved for operator review. Click the embedded map or drag its pickup marker: latitude, longitude and the draft summary update immediately. A short-debounced authenticated `POST /locations/nearby` adds nearby GeoNames settlement context from MAPA's local index. Selected pin coordinates are never sent to an external reverse-geocoding provider or snapped to a settlement centre.

An empty or previously auto-filled address becomes `Pinned pickup · …` with coordinates and, when available, a nearby place/distance. An address typed by the operator is preserved. Nearby settlement names are approximate context, not a verified street address or mandal assignment. Village, mandal, access directions and the actual entrance must be confirmed with the caller.

The new-call and review screens show pickup, saved call, reviewed call, dispatch and crew acknowledgement progress. Saving adds the selected pickup to the call queue; the queue displays the saved pickup and coordinates. Native orders are created by the existing confirmed dispatch transaction. Crew acknowledgement is shown only when the backend reports it. Dropping or moving a pin does not create a call, confirm a review or dispatch a crew.

Late lookup responses cannot overwrite a newer pin, detached form or operator-written address. Lookup failure retains the chosen coordinates and manual address entry. A changed review must be confirmed again.

## Optional Google map and Places search

The current key-free workflow is active without Google. A separate Google adapter is available only if a suitable Maps Platform project is configured later:

1. Create/select a Google Cloud project and enable billing directly in Google Cloud.
2. Enable **Maps JavaScript API** and **Places API (New)**.
3. Create a Maps Platform browser API key. Restrict websites to `https://mapa-asr-health.pages.dev/*` and, if local use is required, `http://localhost:4200/*`. Restrict APIs to Maps JavaScript API and Places API (New). Set appropriate project quotas and billing alerts.
4. Configure the key in MAPA's existing system Services → Google Maps setting (`services.google_maps.api_key` / `GOOGLE_MAPS_API_KEY`). Do not put a Gemini authorization key or a server-only secret there. The native map already uses this same setting. Save credentials privately; they must not be added to the source offer.
5. Refresh/re-sign in to Call Intake. The authenticated `/mapa/voice/v1/calls` response supplies `pickup_map`; a configured browser key enables the Google map and Places text search automatically. The operator desk does not change the main native console's company map-provider preference.
6. Verify Google attribution, ITDA Paderu search, explicit result selection, map clicks, coordinate read-back and the absence of browser API errors on the actual public origin. Restriction, billing, quota and disabled-API failures must be resolved in Google Cloud. No activation claim is made until this live check passes.

No Google credential was persisted by this integration. Map loading failure falls back to the OpenStreetMap overview, with an explicit status message.

Google search requests use `Place.searchByText` with eight results maximum, India region, ASR location bias, and only display name, address and location fields. Results are not bulk imported. Typing is debounced; stale results and detached forms cannot overwrite a new pickup. No search result confirms the pickup or dispatches a crew automatically.

## Data and verification

- GeoNames: https://download.geonames.org/export/dump/IN.zip — CC BY4.0; provenance is in `asr-places.json`.
- Photon: https://github.com/komoot/photon and https://github.com/komoot/photon/blob/master/docs/api-v1.md . OpenStreetMap data attribution and licence: https://www.openstreetmap.org/copyright .
- ASR place-name/locality facts: https://allurisitharamaraju.ap.gov.in/about-district/whos-who/ and https://allurisitharamaraju.ap.gov.in/health/ . Only selected place names and localities were added; no employee/contact list was copied.
- Current mandal scope: https://allurisitharamaraju.ap.gov.in/mandals/ and https://allurisitharamaraju.ap.gov.in/commercial-taxes-department/ . The current page lists 11 mandals; older district publications can describe earlier boundaries.
- Google setup: https://developers.google.com/maps/documentation/javascript/place-get-started
- Google text search: https://developers.google.com/maps/documentation/javascript/place-search
- Google Maps URLs: https://developers.google.com/maps/documentation/urls/get-started

Tests: `tests/locations.php`, `tests/photon.php`, `tests/pickup-map-config.php`, `console/scripts/test-mapa-live-search.cjs`, `console/scripts/test-mapa-dynamic-pin.cjs`, and `console/scripts/test-mapa-google-pickup.cjs`. Photon transport tests use mocked HTTP and a process-local cache; a separate read-only provider check returned the actual ITDA candidates. Google SDK tests are stubbed and are not evidence of an activated Google project.

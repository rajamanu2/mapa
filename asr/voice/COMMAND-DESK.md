# MAPA command desk read model

`CommandDeskService::read($context, $filters = [], $nowMs = null)` returns
`mapa-command-desk-v1`. The parent endpoint must authenticate current staff first;
the service additionally requires a tenant UUID and admin/superadmin binding role.
The company is taken only from that context, never from request filters.
Allowed filters are `days` (1–90, default 30), `page` (positive, default 1), and
`driver_id` (native public ID). No source connection, provider request or write occurs.

## Evidence and denominators

- Driver statistics are grouped by native driver UUID across vehicle changes.
  Offers are native MAPA automatic offers created within the window. Source-owned
  ASR calls and demo calls are excluded. Pending offers whose deadline passed count
  as expired observations without modifying their stored state.
- Acceptance percentage = accepted / (accepted + declined + expired). Pending and
  cancelled offers are excluded. An empty denominator is null, never 100%.
- Average response time uses only valid nonnegative accepted/declined response
  intervals. Expiry worker timestamps are not driver response times. Its separate
  `response_samples` denominator is returned. Missing times produce null.
- Completed-trip attribution uses `mapa_mobile_trip.completed.driver_id` and
  `recorded_at`, the actual immutable crew completion audit fields. Reassignment
  of the order or vehicle does not move historical driver credit. Missing evidence
  is reported via `unattributed_completions`; affected driver totals are null and
  `known_completed_trips` preserves the observed count. This is not an all-time or
  source-ASR trip total. History contains at most 100 attributable completed trips.
- Driver rows are paged at 50; offer and completed-order scans cap at 5,000 each.
  A truncated completion scan returns null exact totals with explicit lower-bound
  markers. Offer rows carry `offer_statistics_partial`; root `truncated` flags must
  remain visible when rendering partial totals or rates.

## Monitoring

The native stage remains verbatim. `started`/`enroute` never imply pickup or hospital
arrival. Closure review accepts explicit `TripArrivalService` crew-reported hospital
arrival metadata bound to the current tenant, order, driver, vehicle and acknowledgement,
or an explicit native `hospital_arrived` tracking event owned by the order. Crew arrival
reports do not independently prove physical arrival. Missing or superseded assignment
evidence stays unknown.
After a recorded arrival, 5/10/15-minute levels suggest human handover/closure review.

Movement review continues through dispatched/started until a current pickup-arrival
report or the native enroute stage. It requires the current assignment acknowledgement hash and
driver/vehicle match. It uses only scoped stored phone-GPS receipts: explicit non-mock
flag, no demo/simulation, accuracy at most 50 m, latest fix within 60 seconds,
acceptance baseline within 30 seconds. A movement observation requires displacement
minus both accuracy bounds of at least 200 m. A lack-of-movement review requires
continuous evidence with no gap over 90 seconds and no truncated receipt history.
The 2/3/4/5-minute thresholds are review prompts, never clinical or punitive decisions.
Stale, inaccurate, incomplete or reassigned evidence is unknown. The service never
changes status, dispatches notifications, contacts crew or closes a trip automatically.

Active orders cap at 100, fleet descriptions at 200, and GPS receipts at 200 per
monitored assignment within 30 minutes. Global dispatchability is not fabricated:
`dispatchable_now` remains null; authoritative dispatch eligibility is the existing
per-call candidate check. For a complete tenant fleet, `gps_coverage` counts recent
(at most 180 seconds), stale and missing verified GPS projections, and
`gps_stale_offline` is the sum of stale and missing. These are coverage counts,
not proof that a device is electrically offline or that a crew can be dispatched.
When the 200-row fleet cap is exceeded, these totals remain null. `on_trip` counts
distinct observed assigned vehicles outside the created stage. `active_calls` counts
open undispatched intake records, excluding dismissed and terminal calls.

## Local verification

`tests/command-desk.php` uses a fresh in-memory SQLite database and the local PHP
runtime. It does not load D:/app settings, its database, or the MAPA app environment.
Tests exercise denominators, same driver across vehicles, tenant isolation,
immutable completion attribution, missing data, stale/mock/inaccurate/gapped GPS,
assignment changes, closure thresholds, bounded scans and captured read-only SQL.
This does not replace deployed MySQL schema, endpoint-auth or real-phone acceptance.

# ASR records inside MAPA

These are local source changes. They have not been published to the VPS.

| MAPA screen | ASR records shown beside MAPA records | Ownership |
| --- | --- | --- |
| Operations | Active CAD incidents, dispatch records, status timeline and unfinished source trip rows | Original ASR dispatch remains source-owned; viewing a row does not create or dispatch a MAPA order. |
| Resources | ASR ambulances, drivers and hospitals/bases; reviewed native identity links | Native account approval, duty and vehicle assignment remain explicit MAPA operations. |
| Ambulance details | Linked source vehicle details, sessions, repairs, allocations and source totals | Administrative coordinates are not GPS telemetry. |
| Driver details | Linked source driver/base/category, attendance, allocations and source totals | Attendance from ASR is its PHC/day report, not MAPA mobile duty. |
| Hospital/base details | Linked source hospital, associated source fleet and drivers | Native hospital permissions continue to determine patient/trip access. |
| Maintenance | Source repairs, repair components and daily road sessions | A source road/session record does not automatically mark a MAPA ambulance ready. |
| Analytics | Source vehicle/driver totals and attendance | Source patient/trip-row totals and MAPA journey totals have different units and must not be added together. |
| ASR Health Integration | Full paginated source datasets, Fetch latest data, reviewed identity links and durable source-change review | Transfer review acknowledges a source observation; it does not start an ambulance journey. |

The component is installed within the current native screen layout. Dashboard data refreshes every 30 seconds while the page is visible, and after a successful manual source fetch. The server snapshot must itself be refreshed by its configured receiver/schedule. Browser polling alone does not fetch the original database.

All successful responses are scoped to the signed-in native user and company. A changed identity, selected resource, revoked permission or removed link clears prior displayed data. The views use the same verified generation, escape displayed text and label snapshots stale after 120 seconds. No credentials, source patient records or private configuration are exposed in these read views.

## Deployment prerequisites

1. Compare the read-only export schema against the actual original website database; the archived source is a reference, not proof of live parity.
2. Install the expanded `mapa-asr-source-v2` private export and its narrow read-only database grants. Keep its configuration outside the public directory.
3. Publish the matching MAPA API module/migrations and compiled console together. The source-only review package is not a complete running deployment.
4. Configure the receiver, its independent schedule, and private `connections.json` read/review grants for approved native accounts. `source_connections_enabled` defaults false; training mode remains false in production.
5. Fetch, review source/native identity pairs, and validate names, registrations, hospital/base links and stale behavior with actual approved staff.
6. Two-way operational dispatch must remain disabled until every original writer participates in coordination, original transaction/retry behavior is covered, and the MAPA reservation/outbox workflow passes acceptance. See `WORKFLOW-WRITER-HANDOFF.md`.

The authoritative VPS remains the running system. Do not start the retired PC application, API, queue or scheduler writers to test or deploy these changes. Include `compose.override.yml` in explicit VPS compose commands.

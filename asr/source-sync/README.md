# ASR Health integration in MAPA

Status: local implementation; production sync and write-back are disabled.
The existing ASR forms, transfer rules and attendance rules remain in place.
Read synchronization does not change source records. Optional authorized write-back
updates only the reviewed profile/transfer fields described in WRITEBACK.md.
It does not create native crew accounts, assign ambulances,
approve registrations, move a pickup or change an active trip.

## Data flow

The default v1 PHP exporter reads allowlisted fields from eight original MySQL
tables in a read-only consistent transaction. A separate SELECT-only database
account is required. It never includes the original site's config/bootstrap or
self-healing code. Drivers retain their original source IDs, including multiple
drivers sharing one phone number. Trip statistics are aggregated; individual
patient names, phone numbers and trip details are not exported by v1.

The optional [v2 read contract](SOURCE-READ-V2.md) adds administrative placement,
session closure, repair components and non-patient manual-trip/CAD activity from
twelve original tables. It requires an explicit private source configuration
opt-in and a compatible receiver; v1 remains the default. Source activity is an
advisory view, not a cross-system dispatch reservation.

The VPS CLI receiver makes signed HTTPS requests and accepts only complete,
fresh, correctly signed snapshots for the configured ASR organization. It replaces
a private state file atomically and retains the last verified snapshot on failure.
The native API requires an active, approved ASR administrator on every request.
Browser responses use no-store; no extra mobile credentials or browser storage
are created. Source records never appear in the caller tracking page.

The native console's **ASR Health Integration** sidebar entry opens inside the existing Operations content area and provides an overview,
Fetch latest data, drivers, ambulances, hospitals,
source attendance, daily status, driver allocation IDs, repairs, aggregate trip
statistics, categories and observed roster changes. Authorized profile edits and
driver/ambulance transfers use a separate signed command API, revision checks and
idempotent receipts. Unknown save outcomes keep the same request ID and values
for retry; the UI never replaces a source record optimistically. The edit dialog
pauses background view refresh so form values are not replaced while typing.
Original create/delete, session, repair and patient/trip editing remains on the
original website; these write workflows are not implemented by this module.
Attendance preserves its PHC/day rule: an existing PHC daily record
counts as present, including an Off Road record. It is not proof of individual
driver duty or GPS attendance. A driver transfer changes the current PHC used by
that original attendance rule. Change timestamps mean first observed by MAPA,
not the time an original administrator performed the change.

## User controls and synchronization

- The inline workspace keeps the native top bar/sidebar visible. The previous route DOM stays mounted; returning restores its scroll position and draft values. Sidebar navigation and router transitions close the integration view. No browser tab or modal is opened for the integration overview.
- The workspace uses the existing native console session. Every API request
  rechecks approved administrator membership; logout or company changes clear it.
- GET source-inventory/status reports setup, last verified time and counts. Missing
  connection/data is shown explicitly, never as zero vehicles or a successful sync.
- POST source-inventory/refresh takes no body or query. The private receiver is
  shared with the scheduler, uses a lock and 30-second cooldown, validates the
  signed complete snapshot, and retains old data on failure.
- GET/POST source-commands implements the three actions documented in WRITEBACK.md.
  Enable MAPA_SOURCE_COMMANDS_ENABLED only alongside MAPA_SOURCE_SYNC_ENABLED and
  a private API storage/app/mapa-source-sync/command.json containing enabled, the
  fixed HTTPS command URL and a separate secret. Each source-side actor permission
  must be reviewed; a native administrator is not implicitly an ASR editor.
- On successful save, the backend attempts a verified source refresh. If it is
  busy/offline, the UI says the source saved and the local view awaits refresh.
- Imported roster history and source allocations remain distinct from verified
  MAPA crew bindings, active orders, duty and GPS. The overview explains these
  boundaries; there is no automatic bidirectional dispatch mirroring.

## Live commissioning gate

1. Verify the actual production hosting origin, PHP version (8.1+), eight table
   schemas and InnoDB engines against the inspected source. Confirm the original
   PHP application's timezone and set source_timezone explicitly: its attendance
   report uses PHP dates, which may differ from the database timezone. Inspect indexes and
   query plans read-only. Back up affected deployment files.
2. Review source/native permissions and provision a dedicated local SELECT-only
   source account for amb_drivers, ambulances, phc, type_ambulance, institute_type,
   amb_daily_status, amb_repairs and amb_trips. Never reuse the website's writer
   credential or expose a MySQL port. Create a random 32-byte-or-longer shared
   secret; keep it and both private JSON configurations outside public roots.
3. Deploy source-export.php as /mapa-source-export.php with SourceSync.php
   alongside it. Set MAPA_SOURCE_EXPORT_CONFIG explicitly to the private JSON.
   Reject unauthenticated requests and check the source site's existing functions
   still work. Do not modify its original forms, routes or bootstrap.
4. Deploy the MAPA controller/helper and native assets, keeping the feature off.
   Run sync-once.php against the real source once and compare counts and sampled
   source IDs in a private review. Measure full-export time and database load
   before choosing a polling interval. The prototype fetches a full snapshot;
   50,000 rows per dataset and 8 MiB are fail-closed limits. Large histories need
   incremental export or a separate statistics schedule before activation.
5. Enable MAPA_SOURCE_SYNC_ENABLED only after the above passes. A proposed
   30-second receiver schedule plus 30-second visible-browser refresh gives
   approximately 60-second updates, not instantaneous synchronization. An age
   above 120 seconds is labelled delayed. Configure the receiver as an independent
   existing VPS service/timer with a private writable state directory shared with
   the API; no scheduler has been installed by this local build.
6. Verify administrator access, crew/anonymous denials, source transfer/deletion
   handling, preserved native trip/account counts, source load and browser mobile
   layout. Test changes using approved test records only. Never test by moving
   a real driver or deleting a production vehicle.

VPS is authoritative. Include compose.override.yml in every explicit compose
command. Never start the retired PC writers, restore stale PC data or change
asrhealth.in/www/mail DNS as part of this integration. Existing API configuration
contains commissioned routing/provider settings: do not blindly rebuild its cache.

## Local verification

- `node test-transport.cjs`: browser transport/session isolation.
- `python run-tests.py --docker <docker-path>`: disposable internal network and
  tmpfs MySQL; SELECT-only exporter, HMAC, tampering, attendance and unchanged
  source data. Uses cached images; no production mounts, ports or connections.
- `python ../mobile/tests/run-dedicated-native.py --docker <docker-path>`:
  disposable native schema with administrator/crew/tenant/stale-state cases.

Do not claim deployment or a passing database test from static checks. Latest
results are recorded separately after execution. Disabling the feature and
receiver stops the integration without reverting the original ASR application.

### Native Maintenance Hub appearance
The integration overview uses the same `fleet-ops-control-hub-*` components and engine styles as Maintenance Hub: native header, four record-count cards, grouped record links, and an Action Queue with usage guidance. It remains inside the existing Operations section. The source sync and write-back behavior is unchanged. Local synthetic browser verification covers navigation, refresh failure, revoked access, preserved drafts, and 390px layout; 29 transport checks pass. This visual update is not deployed.

# Original ASR source read contract v2

Local implementation and isolated database verification; this document is not a production activation receipt. The original ASR website remains the source of these records. No original forms, bootstrap, session rules or database rows are changed by export.

## Compatibility and activation

`SourceSync::CONTRACT` and the default exporter remain `mapa-asr-source-v1`. Existing v1 dataset fields and signed request/response envelope remain unchanged. The expanded read contract is explicitly selected in the private exporter configuration with `"contract":"mapa-asr-source-v2"`. `supportedContracts()`, `fieldsFor($contract)` and `capabilitiesFor($contract)` let receivers and authenticated API projections select the exact contract. Deploy compatible receivers/API consumers before opting the source exporter into v2. Do not infer v2 support from a successful v1 fetch.

V2 includes `source_timezone` and exact capabilities: `administrative_mapping`, `daily_sessions`, `repair_details`, `repair_items`, `active_trip_state`, `cad_summary` and `trip_activity` are true; `patient_records` and `dispatch_reservation` are false. The entire snapshot, including these fields, is covered by the response signature. This read capability does not grant a write permission.

Legacy profile/transfer command revisions intentionally continue to use `SourceSync::FIELDS` (the v1 roster fields). Adding administrative fields to a read row does not silently change the existing command revision protocol. Workflow commands have their own separately reviewed contract.

## Expanded datasets

| Dataset | Added fields or new allowlist | Meaning |
|---|---|---|
| ambulances | mandal_id, secretariat_id, latitude, longitude | Administrative placement and base coordinates, not live GPS. |
| hospitals | mandal_id, latitude, longitude | Original PHC location and hierarchy. |
| daily_status | is_closed, closing_odo | Original daily session closure and recorded closing odometer. |
| repairs | daily_status_id, status, place_of_repair, estimated_cost | Repair linkage, state, workshop and estimate alongside existing repair details. |
| repair_items | id, repair_id, component_name, estimated_cost | Components for original repair records. |
| trip_activity | id, daily_status_id, driver_id, trip_type, distance_kms, pickup_date, pickup_time, drop_date, drop_time | Non-patient activity rows; retains original IDs and open/completed timestamps. |
| cad_incidents | id, incident_number, incident_type, priority, from_phc_id, from_secretariat_id, from_village_id, to_phc_id, to_secretariat_id, status, dispatch_time, completion_time, created_at, updated_at | Original source-owned dispatch incident summary. |
| cad_dispatches | id, incident_id, trip_id, ambulance_id, driver_id, daily_status_id, dispatched_at, eta_minutes, status, created_at | Original source incident-to-vehicle/driver/session/trip linkage. |
| cad_status_updates | id, incident_id, dispatch_id, status, updated_at | Original CAD status timeline, without notes or actor identity. |

The original ten datasets remain present; v2 adds five, for fifteen in total. No patient records, patient/caller names or phone numbers, pickup/drop addresses, clinical notes, transcripts, death details or CAD free-text notes are exported. The pre-existing authorized driver roster still includes driver contact/profile fields. These snapshots belong only in private server storage and scoped authenticated operations views, never in caller tracking or an unrestricted crew payload.

## Workflow interpretation

- `ambulance_running.php` records a completed manual trip with both pickup and drop times. It does not persist the same Start Trip state machine as CAD. A multi-patient journey can create multiple trip rows, with mileage only on the first row. Therefore source `trip_count` is a row count, not a guaranteed count of distinct journeys.
- CAD can persist an open trip row, then update dispatch/incident/timeline on progress or completion. Use `cad_dispatches.trip_id` to associate its `trip_activity` row; do not count that row again as a second journey. Source-owned records remain distinct from native MAPA orders.
- Original availability checks require today's On Road session, `is_closed=0`, and no open trip. Repairs can also keep an ambulance off road. A periodic read is only an advisory observation: it is not a lock, reservation, proof of native driver duty, or a guarantee that another dispatcher has not acted since export.
- Original attendance is PHC/day based, including Off Road daily records; it is not individual driver attendance. Transfers affect the current PHC used by that original report.
- Source date and timestamps must be interpreted with the explicitly configured original PHP timezone. Administrative coordinates are not live GPS, and original timestamps are not translated into invented MAPA stages.

## Fail-closed read and deployment gates

V2 requires the existing eight v1 tables plus `amb_repair_items`, `cad_incidents`, `cad_dispatch_log` and `cad_status_updates`, all InnoDB. A SELECT-only account must have access to all twelve; it must not reuse the website writer account. The exporter never creates missing columns/tables or invokes original bootstrap/self-healing code. Missing tables/columns, unexpected fields, duplicate IDs, malformed closure/coordinates/numeric details, incomplete datasets, invalid signature, stale snapshot or unsupported contract fail the fetch and preserve the previous verified snapshot.

Exports use one read-only repeatable-read transaction. Limits remain 50,000 rows per dataset and 8 MiB for the whole snapshot; exceeding them rejects the complete fetch instead of silently truncating history. Review actual production schemas, column nullability/types, indexes, record counts, PHP timezone and full export cost before enabling v2. If history exceeds these limits, implement a separately versioned incremental/history strategy before activation.

Evidence was inspected directly in the local `C:/Users/roysh/Downloads/ambulance.zip` archive, including `ambulance_index.php` (administrative placement), `ambulance_running.php` (daily sessions and manual trip lifecycle), `off_road_ambulances.php` (repair components/completion), `setup_running_db.php` (session/trip/repair schema), `cad_db_init.php` (CAD tables), and `cad_get_available_ambulances.php` (availability). The archive is an implementation reference, not proof that live source code/schema is identical today.

## Verification

`run-tests.py` runs a fresh internal-network MySQL database with tmpfs data storage and synthetic DEMO fixtures. `test-v2.php`, included by `test.php`, verifies read-only digests across all twelve tables; private patient/CAD fields excluded; exact version/capabilities/fields; original IDs; open/completed source activity; closure/repair/admin detail; stable legacy revisions; v1 compatibility; signed v2 HTTP export and receipt; malformed and partial responses; and preservation of the last verified snapshot after schema failure. The existing profile/transfer command suite also runs unchanged. No production database, website operation, SMS, push delivery or dispatch is exercised by these tests.

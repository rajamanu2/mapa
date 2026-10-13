# MAPA source inventory import

This importer stages the official ASR Health mapping export into native MAPA inventory. Its apply entry point creates only missing ambulances and hospitals inside one scoped transaction. It does not import source running status, patient or trip logs, create drivers/accounts, assign drivers, approve enrollment, send notifications, or write live positions.

The target company is fixed to `29eff7bc-7b9b-4d8a-b1b4-55f33681bd2d`. Keep all source rows, snapshots and plans in `private/asr-import`; do not serve them as downloads or commit them as fixtures.

## Reviewed data

The final October 1 plan uses 93 source mappings and 151 private driver rows. It proposes 93 vehicles and 39 hospitals; four hospitals are deferred because their source coordinates are invalid/N/A: AH Narsipatnam, PHC K.D.PETA, PHC Nathavaram and KGH. All 151 driver rows remain staged for later identity and fresh-GPS onboarding. Twenty-two driver rows share contact phones across different source identities and require review.

Vehicle source types remain exactly `Special` and `Feeder/Bike`; the importer does not infer clinical capabilities. Vehicles start offline with SQL NULL location and no driver assignment. The native compatibility status is `available`, but metadata explicitly records `import_scope=inventory_only` and `operational_status_verified=false`. A source mapping does not verify readiness. Native mobile duty changes only the online flag; importing an arbitrary inactive status would not automatically clear it at subsequent duty start.

Hospital identity uses institution type plus base name, displayed as a typed name such as `PHC Madagada`, without doubling an existing prefix. The hospital point is the source hospital/parking-base coordinate, never a live vehicle/driver fix. Missing, invalid, zero-origin or conflicting points are deferred. Secretariat, Mandal and Date Mapped remain source metadata. No street address is invented.

## Preservation and retry

Apply locks the company and re-reads current company vehicles/places, including deleted records. A normalized plate or an existing typed source hospital identity preserves the complete existing native row. Deleted, training, duplicate or ambiguous untyped hospital collisions abort the entire transaction. Records created manually after the snapshot are preserved; fixed table counts are not a prerequisite. Separate institution tiers sharing a base name remain distinct typed identities.

There are no User, Driver, binding, order or position writes. The same reviewed plan can be retried after an unconfirmed response: the transaction preserves existing matching resources and creates no duplicates. Do not replace an ambiguous plan with changed evidence until native state has been inspected.

## Workflow

Use the bundled Python executable and installed Docker executable. `capture-snapshot.py` uses the normal native runtime internally, fixes the company scope, starts a SQL read-only transaction and saves only allowlisted rows privately. It prints counts, never database credentials.

1. Capture a fresh authorized snapshot with `capture-snapshot.py --docker <docker.exe> --output private/asr-import/native-snapshot-2026-10-01-final.json`.
2. Run `stage.py --csv <official.csv> --drivers private/asr-import/asr-drivers-2026-10-01.json --snapshot <snapshot.json> --output private/asr-import/resource-plan-2026-10-01-final.json`.
3. Review the private plan and exact SHA256, preserve a current consistent database backup, and run the isolated checks below. Review any collision/deferred list; do not silently infer missing data.
4. Run `apply.py --plan <plan.json> --snapshot <snapshot.json> --expected-plan-sha <reviewed-sha>` to verify fingerprints without a database connection or writes.
5. Only the root task performs the reviewed production apply by adding `--apply --docker <docker.exe>`. It uses existing `mapa-live-access-api-1` and emits a counts-only receipt. No import schema migration, deployment or container file copy is required.
6. Inspect native lists and confirm offline/unknown location rendering, preserved existing rows and deferred identities. Resource creation is separate from crew approval and operational onboarding.

The current final plan SHA256 is `ce53af09b138807dbefb68de562d360b0ab0659726f0a628bfa1517e53912c2d`. Any staging-source or private input change requires a fresh reviewed plan. The apply command rechecks both plan and snapshot fingerprints.

Post-import verification uses `verify.py --plan <reviewed-plan> --snapshot <original-snapshot> --expected-plan-sha <reviewed-sha> --docker <docker.exe> --output <private-report>`. For the immutable October 1 final snapshot only, add `--legacy-snapshot-windows1252`: its original capture used Windows cp1252 to decode native UTF-8 output, turning em dashes and middle dots into mojibake. The explicit verifier flag validates the original hash first and reverses those exact bytes before strict field comparison. It does not ignore identity changes or rewrite the snapshot/plan. New snapshot captures and verifier output use strict UTF-8 and require no legacy flag. Run import preservation verification before a separately authorized demo-retirement operation changes the baseline's deleted fields.

## Isolated evidence

`python -B -m unittest -v test_stage.py` from this directory runs 26 synthetic DEMO fixture cases. `tests/offline.py --docker <docker.exe>` performs PHP syntax checks, 23 pure reviewed-plan policy checks and syntax-checks the exact generated CLI body using offline cached containers with stdin enabled. Its report is `results/offline.json`. `tests/validate-private-plan.py --plan <private-plan.json> --expected-plan-sha <reviewed-sha> --docker <docker.exe>` runs the same pure policy against the exact private plan without bootstrapping an app or database or emitting private rows.

`tests/run-native.py --docker <docker.exe>` is the independent reviewer-owned native acceptance harness. It launches a fresh internal QA network and a new tmpfs MySQL database with dummy credentials, refuses an existing schema or cached image configuration, and tests the exact shared `ImportApply` implementation. It never mounts a production environment or volume. Its result is accepted only with a passing native marker; syntax or pure tests alone are not native acceptance.

The independent final native run passed 52 checks using actual native tables. It covers import/replay/collisions/rollback, inventory-only provenance and null GPS across internal, public, webhook, index/sidebar and operation-monitor serializers. Four exact runtime-derived API guards preserve SQL NULL as unknown, without altering real coordinates. No production data or service was used by these isolated tests.

## Separate researched hospital supplement

The original plan and staging fingerprint remain immutable. `stage-supplement.py` creates a separate evidence document and reviewed plan for three formerly deferred hospitals. It selects the named Google Maps facility point (`!3d/!4d`, not the map viewport center), with official sources corroborating identity or address. These points are not ground surveys, emergency-gate measurements, or live vehicle GPS.

The selected locations are AH Narsipatnam at `17.6727848,82.6107761`, PHC K.D.PETA/Krishnadevipeta at `17.6704188,82.3856109`, and KGH Visakhapatnam at `17.7087374,83.3060385`. Narsipatnam's Google building pin matches the police-station/Chintapalli Road address in the government-hosted NBEMS hospital list; a conflicting APMSIDC tender coordinate remains recorded in the evidence and native provenance. K.D.Peta's expansion to Krishnadevipeta is a corroborated identity inference. KGH is a Visakhapatnam referral/base campus from the source mapping, rather than an assertion that it is within ASR district.

PHC Nathavaram remains unresolved. Google explicitly warns its location may be incorrect, and the APMSIDC point is about3.6km away. The native Place schema requires a non-null point, so the supplement retains this identity privately without creating a zero-origin or guessed native location. A staff member must verify the facility building point before a separate reviewed addition.

Private artifacts are `private/asr-import/hospital-location-evidence-2026-10-01.json` and `hospital-supplement-plan-2026-10-01.json`. The reviewed supplement SHA256 is `99add5b34de835d5b6c0417fd7d1796d472e1ff0fe00635670931e60cc7eb731`. It proposes three Place rows and five exact source-inventory metadata links. `SupplementApply` locks the fixed company, rejects existing/deleted/demo alias collisions, and replays only unchanged source-tagged hospital receipts. For vehicles it requires the exact original CSV/source/key/inventory-only baseline, then uses one scoped `JSON_SET` to fill only `asr_import.source_parking_base_place_uuid`. It never saves a Vehicle model or overwrites GPS, driver, online, status, duty, assignment, timestamps, or other metadata.

Run `supplement.py --plan <supplement-plan> --evidence <evidence> --expected-plan-sha <reviewed-sha> --docker <docker.exe>` for a database-free dry run that also executes the exact PHP policy in a new offline, read-only cached container. Without `--docker`, the dry run checks fingerprints/counts only and reports `php_policy_validated=false`. `tests/supplement-offline.py` with the same arguments performs30 policy cases, syntax-checks the exact generated apply/policy bodies, and validates the exact private plan; its report is `results/supplement-offline.json`. Native transaction acceptance is a separate independent check against a fresh dummy database. Only the root task may add `--apply --output <private-receipt>` after reviewing that proof and preserving a current backup.

The independent supplement native run passed 55 checks on the actual cached native schema with frozen source hashes. It verifies create/replay, alias/tenant/deleted/training/duplicate/provenance/base-link rejection, late transactional rollback, offline SQL NULL, and byte-identical existing vehicle fields including live GPS, driver association, duty/status/type and native timestamps. Existing User, Driver and binding rows remain unchanged, with no new order, position, enrollment, session or evidence rows. The dedicated runner is `tests/run-supplement-native.py`; proof is `tests/supplement-native-result.txt`, SHA256 `c638e41bc01a793b1133dbc7dda5193fa28c0e622c3baafa2732135be7de2f8e`. Its disposable QA containers/network were removed, and it made no production connection.

# Hardware and phone GPS projection

The additive `2026_10_04_000007_create_device_position_history.php` migration creates source-specific hardware history and a primary-keyed `(company_uuid, vehicle_uuid)` shared projection state. Hardware ingestion requires both tables. Phone-only deployments retain their previous behavior until the projection table exists.

Both writers already lock the native vehicle row inside their transaction. `VehiclePositionProjection` then accepts only a timestamp strictly newer than the shared projection. Equal timestamps leave the current source intact. Tracker replacements cannot reset this ordering. Initial state bootstraps existing phone receipts/device fixes once per vehicle; normal writes use indexed state instead of repeatedly searching JSON receipts.

An older phone fix can still advance that driver's own valid GPS state and immutable receipt without moving or broadcasting the ambulance. Hardware updates neither driver coordinates nor duty/availability. Raw hardware fixes remain in their separate timestamped history even when the shared map does not advance. Broadcast failures occur after commit and do not discard accepted fixes.

Every hardware credential must first be linked to an actual ambulance by an authorized operator. Supplier protocol adaptation and real device verification are still required. The timestamp is device-reported, not an attestation of physical presence. Native map subscription authorization retains the existing gateway boundary.

## Local GT06 adapter — 4 October 2026

`../gps/` now supplies a pinned Traccar receiver, a private forwarding bridge, and isolated binary/native tests. This is a local candidate; production ingestion remains disabled. The additional `2026_10_04_000009_add_hardware_fix_quality.php` migration preserves GT06 satellite/validity evidence and permits unknown hardware accuracy (`null`). Phone/caller accuracy rules remain unchanged. GT06 fixes require matching external ID and per-device credential, a valid fix and at least four satellites. Exact retries cannot duplicate history or broadcasts. See `../gps/README.md` for startup, trust limits and physical commissioning.

## Retention gate before sustained rollout

No automatic deletion or pruning was introduced. The owner must define an approved GPS retention period, backup retention and deletion policy before enabling sustained fleet ingestion. At 75 devices sending every 10 seconds, hardware history alone grows by approximately 648,000 rows per day; native projected-position history adds further rows. Size actual records/indexes on the target server, monitor database/disk growth, and implement separately reviewed, bounded archival/pruning after the policy is agreed. Do not silently discard operational history to claim readiness.

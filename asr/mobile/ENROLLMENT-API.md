# MAPA crew enrollment and camera evidence

This additive module stays disabled until a reviewed migration and explicit cutover. It never migrates, seeds, changes passwords or creates a native account at boot. Existing accounts remain intact. An active same-company driver without mobile access may submit a pending signup using the existing email for staff review. Deleted, foreign or already connected accounts return 409; signup never replaces an identity or bypasses native verification, MFA or reset requirements.

## Cutover

Run the additive `2026_10_01_000004` migration through the controlled native migration process after backup and QA. It adds four module tables: enrollments, private media, trip reports and enrollment audit. Set `MAPA_MOBILE_ENROLLMENT_ENABLED=true` and `MAPA_MOBILE_COMPLETION_EVIDENCE_ENABLED=true` deliberately with the existing company/mobile configuration. Private media requires PHP GD, writable persistent `storage/app/mapa-mobile-private`, and no public storage symlink to that directory. Back up this directory with its database rows; restoring just one side loses evidence integrity. The camera JSON limit is 3 MiB at proxy, middleware and controller; JPEG input is at most 2 MiB and 4096 pixels per side/12 MP. All other JSON stays at 16 KiB. EXIF/comments/trailing content are rejected or stripped by JPEG decoding and re-encoding.

## Phone registration

`POST /mapa/mobile/v1/enrollments` JSON:

```
{action_id,connect_token,name,email,phone,ambulance_registration,ambulance_name,
 hospital_name,device_name,consent:true,selfie_base64,selfie_captured_at,
 latitude,longitude,recorded_at,accuracy_m,mock_location:false}
```

The phone generates a 32-byte SecureRandom token encoded as 64 lowercase hex characters and stores it and the exact pending body in encrypted app storage. The server stores only SHA-256. Photo and GPS must be captured within two minutes, no more than 30 seconds apart, GPS accuracy at most 100 m and explicitly not mock. `(0,0)` is rejected. Names/phone/location are device submissions and operator review inputs, not identity or travel attestation. Registration creates only a pending module record and private selfie, with no native User/Driver, native GPS samples, duty or dispatch. Unreviewed connections expire after 30 days.

Response: `{contract:"mapa-native-mobile-v1",ok:true,enrollment_id,status:"pending",revision:1,connect_expires_at,replayed:false}`. Exact body/action/token replay returns the same enrollment, including after capture timestamps become old. A different body using the same action ID returns 409. An uncommitted stale photo/fix returns 422 before media/account creation. After a definite rejection, preserve the typed details and recapture with a new action. Network ambiguity requires an exact retry; do not silently change the immutable body.

`GET /enrollment` uses the retained connect token as bearer and returns only minimal status/ID/revision/expiry fields (no profile or media identifiers). Expired connections use `status:"revoked",reason:"expired"` for the released APK contract. `POST /connect` uses that bearer after approval and returns the usual scoped mobile login response `{contract,token,expires_at,user,capabilities}`. New sessions last at most 12 hours; the retained connection lasts 90 days after approval and remains bound to native credentials, binding revision and actual Driver/Vehicle/Place scope. Native MFA, password reset/change, disabled membership/binding, expiry or reassignment blocks connection. An expired/revoked connection or existing email requires control assistance; self-registration is not a password recovery route. `POST /enrollment/revoke` invalidates this retained connection and its sessions without deleting native history or silently ending duty.

## Command-center review

All operator endpoints require an existing same-company admin/superadmin mobile session and revalidate its native membership/security policy. Server config owns the tenant; body/query fields cannot select another company.

- `GET /enrollments`: `{contract,enrollments:[{id,status,revision,name,email,phone,ambulance_registration,ambulance_name,hospital_name,created_at,approved_at,driver_id,vehicle_id,place_id,selfie_media_id,latitude,longitude,accuracy_m,recorded_at,selfie_captured_at,review_reason}],truncated}`; latest 200.
- `GET /enrollments/options`: `{contract,vehicles:[{id,name,registration}],places:[{id,name,address}],truncated}`; real native records only. No synthetic vehicle/hospital is created by the form.
- Pending review rows include `crew_matches:[{id,name,phone,matched_on}]`, using normalized exact name, email or phone matches against this company's MAPA driver roster. Suggestions are private to authorized reviewers and are not an identity decision.
- `POST /enrollments/{id}/approve`: `{action_id,expected_revision,vehicle_id,place_id,identity_confirmed:true,driver_id?,create_new_crew?}`. Select a matching `driver_id` to link the reviewed signup to its existing Driver/User. Existing credentials, name, email, history and driver metadata remain intact; the approved phone receives a scoped binding. Already connected, inactive, simulated, on-duty or actively dispatched crew cannot be linked. Existing ambulance assignment must match or be explicitly resolved in control. A submitted email owned by another account cannot be claimed.
- If staff selects creation, a matching roster requires `create_new_crew:true` after confirming that this is a different person. Without matches, the original new Driver/User creation remains available. The selected offline native ambulance registration must match the form and the base must belong to this company. New driver registration coordinates remain historical and offline; no live Position or travel is fabricated. Approval rechecks the roster, revision and resource ownership in the transaction and retains idempotent replay protection.
- `POST /enrollments/{id}/reject` or `/revoke`: `{action_id,expected_revision,reason}`. Reject a pending enrollment or revoke an approved connection. No account, ambulance or history deletion occurs. Security revocation does not imply a physical ambulance stopped work; review active calls in control.

Review actions return `{contract,ok:true,enrollment,replayed}`. Action/revision conflicts fail closed. These writes do not send invitations, email, calls, push messages or automatic dispatch.

## Trip end

With completion evidence enabled, the existing `/orders/{id}/activity` completion needs `{action_id,expected_revision,activity_code:"completed",confirmed:true,handover_confirmed:true,completion_note,photo_base64,photo_captured_at}`. Optional GPS fields must match the already accepted fix. The authoritative coordinates come from an accepted non-mock, accurate current-session GPS receipt after acknowledgement; fix/camera/receipt must be within two minutes, with fix and receipt no earlier than 30 seconds before the photo. Missing note/photo, stale GPS, changed assignment/revision or unconfirmed handover cannot complete the native order.

Camera bytes, immutable report, native tracking/order state and action receipt commit together. Photos have request-local rollback ownership so Octane workers cannot clean up another request's files. Exact committed completion replay runs before freshness/terminal revision checks, while still requiring current authenticated resource ownership. It cannot duplicate a report/photo. If an uncommitted offline draft becomes stale, a definite 422 permits a new capture/action while preserving its note. An uncertain timeout keeps the original immutable retry. Keep duty active until confirmed completion receipt. Ending duty is a separate explicit action and remains blocked by any active dispatched call.

`GET /trip-reports`: `{contract,reports:[{id,order_id,driver_id,driver_name,vehicle_id,vehicle_registration,note,photo_media_id,latitude,longitude,accuracy_m,recorded_at,photo_captured_at,received_at}],truncated}`. Assigned crew sees its own current scope, authorized command staff sees this company; latest 200. `GET /media/{id}` authenticates first, then returns only authorized private JPEG with `no-store`/`nosniff`. Pending tokens can read only their own selfie. Media IDs never grant access on their own. Photograph a safe ambulance/handover point; exclude patients, faces of bystanders and clinical records.

## Safe QA

Pure tests: `tests/enrollment-policy.php`, `tests/private-media.php`, plus existing policy/trip-cache suites, in an offline read-only disposable PHP container. Dedicated native QA: `python tests/run-dedicated-native.py --docker <installed Docker executable>`. It requires cached images, creates a fresh internal network and tmpfs MySQL with fixed dummy credentials, reads no live `.env`, mounts only this module read-only, initializes native schemas from cached source, runs synthetic temporary-table cases, requires an explicit acceptance marker, and removes only verified tagged QA resources. `tests/dedicated-native-result.txt` contains synthetic test diagnostics. Do not use the legacy native harness with a live database for this release.

Successful source/backend QA is not a 25-phone or locked-phone acceptance claim. Physical camera/GPS, background ringing, permission/force-stop behavior, current server availability and actual real fleet/base records must be commissioned separately.


## Staff creation of missing native review records

POST `/mapa/mobile/v1/enrollments/{id}/ambulance` accepts only
`{action_id, expected_revision, name, registration, details_confirmed:true}`.
POST `/mapa/mobile/v1/enrollments/{id}/hospital` accepts only
`{action_id, expected_revision, name, address, latitude, longitude, location_confirmed:true}`.
Both require a current same-company admin/superadmin session and an unchanged,
unexpired pending enrollment. Names are at most 150 characters, registration 50,
address 250. The ambulance registration must match the submitted registration
when ASCII letters/digits are uppercased and spacing/punctuation removed.
Other JSON keys are rejected. Coordinates must be finite valid latitude/longitude,
never `(0,0)`, and must be explicitly confirmed by the operator from the actual
hospital/base point; registration selfie GPS is not copied.

Responses are `{contract, ok:true, enrollment, resource, replayed}`.
Ambulance resource is `{kind:"ambulance", id, name, registration}` and has no
coordinates; the native record remains offline with location NULL. Hospital/base
resource is `{kind:"hospital", id, name, address, latitude, longitude}`.
The enrollment remains pending with the same revision. Refresh scoped options,
select the returned native IDs, then perform separate identity approval.

The company row lock serializes this route's catalog collision checks. Normalized
plates already present in the company, including soft-deleted records, return 409;
no reassignment or reactivation occurs. Hospital/base duplicate name+address is
compared case-insensitively with collapsed whitespace, excluding voice pickup
rows. Exact committed action retries return the immutable saved receipt even
following later approval; changed data under an existing action returns 409.
Native catalog inserts and private review receipts share one transaction.
Creation adds no user, driver, device binding, position, duty or order.

Native resource creation is covered by `tests/enrollment-resource-native-cases.php`
inside the dedicated empty-database harness; the tests are synthetic and never
point to a production connection.


## Native console review proxy

Native console requests use `/api/int/v1/mapa/crew-review` (the Laravel route path
is `int/v1/mapa/crew-review`) with the existing native bearer credential.
GET methods: `/identity`, `/enrollments`, `/enrollments/options`, `/trip-reports`,
`/media/{id}`. POST methods: `/enrollments/{id}/ambulance`, `/hospital`,
`/approve`, `/reject`, `/revoke`. Bodies/results match the scoped mobile reviewer
contracts. No crew registration, login, GPS, duty or trip activity proxy is exposed.
`GET /enrollments` additionally returns the company-wide `pending_count`, including
pending rows outside the latest 200 returned entries.

Canonical Sanctum middleware and a fresh request-local native-token check require
an unexpired native personal access token with completed console sign-in name and
console abilities, the current active user in the fixed company, an enabled explicit
admin/superadmin mobile binding, active company membership, and existing native
verification/MFA/reset/superadmin restrictions. A proxy call never exchanges the
native credential, creates a mobile session, grants a new role or trusts client
company/role fields. Token deletion, expiry and current membership/binding changes
are checked on every request. Private media retains no-store/nosniff authorization.

The upstream console logout does not delete its existing personal access token.
The console integration discards its closure and stops polling when its native
session is invalidated; server-side bearer revocation still requires native token
deletion or expiry. Do not claim this proxy strengthens upstream logout semantics.
Acceptance cases live in `tests/console-review-native-cases.php` and execute only
inside the dedicated disposable database harness.

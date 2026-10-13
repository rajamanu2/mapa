# MAPA Android pilot: native console connection

The Android app connects to the same native MAPA API and MySQL identities used by the console at `http://localhost:4200/fleet-ops/connectivity/telematics`. It does not connect to the stopped interface on port 8791. A handset needs a reachable HTTPS **API origin**; its own `localhost` is not the Windows computer.

## What this pilot provisions

`provision-mapa-mobile-pilot.php` creates three clearly fictional accounts: `crew.demo@mapa.invalid`, `hospital.demo@mapa.invalid`, and `admin.demo@mapa.invalid`. Each has a separate generated password supplied through private JSON on stdin. Store this JSON outside the source tree and delivery package, under the current Windows user's private application-data directory. Do not paste passwords into shell commands, logs or presentation slides.

All three users have active native company membership. Their email verification timestamp denotes **offline provisioning of fictional `.invalid` identities**, documented in `meta`; it does not claim that any real address was verified. No mail, invitation, webhook, model observer, notification or dispatch event is triggered. The module's native account/MFA checks still apply. The script does not disable MFA or modify company policy.

The crew gets a dedicated `DEMO Mobile Ambulance — Paderu`, native driver, pickup and hospital places, and one fictional `DEMO-MOBILE-CALL-01` dispatch with a native payload and tracking status. It uses the company's existing `transport` configuration. Records have `meta.mapa_demo=true`, `simulated=true`, and dataset `mapa-mobile-pilot-v1` where metadata is supported; native memberships/tracking numbers use the same private `_key` ownership marker. The previous five presentation drivers remain no-login fixtures. No patient data is created.

The mobile **admin** is a company-scoped read role; the script never creates a native platform administrator. To test **superadmin**, explicitly allowlist an already-authorized native `type=admin` user UUID in `MAPA_MOBILE_SUPERADMIN_UUIDS`, with active membership in the configured company, then supply `superadmin_user_uuid` in private input. That adds only the reviewed mobile binding. Without an existing authorized identity, the pilot honestly has three configured roles. Four-role coverage is never claimed from a fabricated platform promotion.

## Private input and controlled execution

The input shape is below. Generate three independent random passwords of at least 24 bytes locally; the strings shown are placeholders and must not be used.

```json
{
  "dataset": "mapa-mobile-pilot-v1",
  "company_uuid": "<configured MAPA_COMPANY_UUID>",
  "accounts": {
    "crew": {"email": "crew.demo@mapa.invalid", "password": "<generated private password>"},
    "hospital": {"email": "hospital.demo@mapa.invalid", "password": "<different generated private password>"},
    "admin": {"email": "admin.demo@mapa.invalid", "password": "<different generated private password>"}
  }
}
```

The integration owner first validates the module, installs its additive migration and reviews company configuration. Copy the provisioning PHP script into a temporary path in the API container, then stream the private JSON into its stdin:

```text
php /tmp/provision-mapa-mobile-pilot.php --apply < /private/path/mobile-pilot.json
php /tmp/provision-mapa-mobile-pilot.php --verify < /private/path/mobile-pilot.json
```

These are container-side examples. From Windows, pipe file contents directly into `docker compose exec -T application php /tmp/provision-mapa-mobile-pilot.php --apply`; do not copy the private JSON into the image or source tree. The script accepts only local/testing CLI environments and the exact configured company. `--apply` is explicit; there is no automatic provisioning on server boot. Output contains only public fixture IDs, counts and roles.

Execution is transactional and idempotent: a second run checks original ownership, assignments, passwords and identities without resetting duty, GPS, acknowledgement or credentials. A conflicting email, changed binding, retired record, changed dispatch assignment or completed workflow stops the operation for review. The script never deletes or reassigns another record. Rotate credentials, retire fixtures and revoke access through a separately reviewed operator action.

## HTTP acceptance and phone demonstration

`verify-mapa-mobile.py` reads that same private file with `--credentials`. The default run checks login, role-bound snapshots and access denial, then revokes every session it created. Add `--exercise-demo` only for the dedicated synthetic fixture; it starts duty, acknowledges the fictional dispatch, submits one simulated GPS fix, verifies retries/rejections, and reads the native result back. It deliberately leaves the fictional crew online and acknowledged so the console can show the demo. It does not reset or complete the dispatch. GPS history from this check is **simulated**, not evidence of a real Android device.

```text
python verify-mapa-mobile.py --origin http://127.0.0.1:8000 --allow-localhost --credentials <private-json> --exercise-demo
python verify-mapa-mobile.py --origin https://<reachable-api-host> --credentials <private-json> --exercise-demo
```

For an authorized existing superadmin, add its email/password as `accounts.superadmin` in the private verifier input and pass `--require-four-roles`. `--credentials -` accepts JSON from stdin, so an operator can merge an existing protected credential in memory without writing it into the pilot file. Credentials stay in memory and output is redacted. Without that account, the report explicitly lists only the three roles it tested. Native cross-company isolation is covered by `mobile/tests/native.php` using temporary schema shadows, separately from these checks. Neither suite substitutes for a handset test.

On a real phone, configure the reachable API origin, sign in as the fictional crew, grant location access, start duty, refresh the assigned call and acknowledge it. Verify the same ambulance and call in the native console. Test no network, retry, screen off, permission denial, sign out and reassignment. Report successful real GPS separately from seeded/HTTP simulated points. Use fictional records until the complete deployment is commissioned.

The reviewed pilot advertises duty, GPS, assigned calls and acknowledgement. Native workflow progression, completion, photo/proof upload, hospital response writes and admin writes remain unavailable. The console handles the remaining workflow. No UI should imply that acknowledging a call starts or completes transport.

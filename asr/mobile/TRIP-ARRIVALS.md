# Explicit crew arrival reporting

POST `/mapa/mobile/v1/orders/{native-public-id}/arrivals` uses the existing native
mobile bearer session. The request contains only:

```json
{
  "action_id": "UUID",
  "expected_revision": "current 64-character order revision",
  "arrival_code": "pickup_arrived",
  "confirmed": true,
  "reported_at": 1791300000000
}
```

`pickup_arrived` is allowed only at native `started`; `hospital_arrived` only at
native `enroute`. The current crew must be on duty, assigned to that native order
and vehicle, and have acknowledged its current revision. The action requires a
received phone GPS fix bound to this user/session/driver/vehicle, at most two minutes
old and within 50 m reported accuracy, with an explicit non-mock flag. Demo and
simulated resources/fixes are rejected. This is crew-reported evidence, not an
independently verified arrival or a geofence assertion.

The phone captures `reported_at` once when confirming. It must be no more than two
minutes old (or 30 seconds ahead); the matching latest GPS must be within one minute
before/30 seconds after that confirmation. A delayed offline action is blocked for
fresh confirmation instead of silently using a later unrelated location. A successful
receipt can still be recovered after this deadline or trip completion using the same
immutable action ID and body, provided the assignment/acknowledgement identity matches.

The transaction locks current authentication scope, order and acknowledgement. It
stores `meta.mapa_mobile_arrivals.{arrival_code}`, updates the native assignment hash
after JSON persistence, and inserts the existing idempotency receipt together. A failed
receipt insert rolls all changes back. New action IDs cannot duplicate an existing
arrival; an old assignment cannot replay or inherit an earlier arrival report.

This does not change native trip status, create a tracking transition, write ASR
records, complete handover, send alerts, or change assignments. Existing completion
rules remain unchanged. Other order metadata is preserved.

`TripArrivalService::nextArrivals($order,$context)` returns advertised code/label
options. Parent snapshot wiring exposes `capabilities.arrivals` and `next_arrivals`.
The Android app shows only the server-advertised action at the matching native stage.
It uses the existing private outbox, action UUID, scope and expected revision. Errors
remain pending/blocked for review; they never appear as confirmed arrival.

The command desk consumes hospital reports only when company, order, driver, vehicle,
acknowledgement timestamp, code and originating native stage match. It records the
server receipt time separately from phone-reported time and starts 5/10/15-minute
human closure-review reminders. Missing or superseded evidence remains unknown.

Local checks use fresh SQLite with Eloquent fixture model definitions and an explicit
fake authentication boundary. They do not replace native MySQL/HTTP authentication
integration or real-phone GPS/network testing. The complete active Android Java source
set compiles locally; no APK was signed or published by this change.

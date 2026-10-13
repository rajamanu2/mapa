"""MAPA native mobile HTTP acceptance. Private credentials never appear in output.

Default checks are reads plus login/logout. --exercise-demo explicitly writes only
the three named synthetic pilot resources. Native tenant-isolation regression is
tested separately in mobile/tests/native.php using temporary schema shadows.
"""
from __future__ import annotations

import argparse
import json
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from pathlib import Path

CONTRACT = "mapa-native-mobile-v1"
ORDER = "order_mapa_mobile_demo_01"
DRIVER = "driver_mapa_mobile_demo_01"
VEHICLE = "vehicle_mapa_mobile_demo_01"
HOSPITAL = "place_mapa_mobile_demo_hospital"


class Failure(Exception):
    pass


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None  # Never forward bearer credentials to a redirect destination.


class Client:
    def __init__(self, origin: str):
        self.base = origin + "/mapa/mobile/v1"
        # Respect the host's configured network proxy while still refusing redirects.
        self.opener = urllib.request.build_opener(NoRedirect())
        self.checks = 0

    def check(self, condition: bool, label: str):
        if not condition:
            raise Failure(label)
        self.checks += 1

    def call(self, path: str, token: str | None = None, body: dict | None = None,
             expected: int = 200):
        headers = {"Accept": "application/json", "User-Agent": "MAPA-Mobile-Acceptance/1.9"}
        if token:
            headers["Authorization"] = "Bearer " + token
        raw = None if body is None else json.dumps(body, separators=(",", ":")).encode()
        if raw is not None:
            headers["Content-Type"] = "application/json"
        req = urllib.request.Request(self.base + path, data=raw, headers=headers,
                                     method="GET" if body is None else "POST")
        try:
            response = self.opener.open(req, timeout=30)
        except urllib.error.HTTPError as error:
            response = error
        except Exception:
            raise Failure("Connection or TLS failed; private request details suppressed.") from None
        with response:
            status, cache = response.code, response.headers.get("Cache-Control", "")
            raw_response = response.read(1024 * 1024 + 1)
        self.check(status == expected, f"HTTP status mismatch for {path}: expected {expected}, received {status}")
        self.check("no-store" in cache.lower(), f"Sensitive response must be no-store: {path}")
        self.check(len(raw_response) <= 1024 * 1024, "Response too large")
        try:
            data = json.loads(raw_response)
        except Exception:
            raise Failure("API returned a non-JSON response; content suppressed.") from None
        self.check(isinstance(data, dict), "API must return a JSON object")
        if status >= 400:
            self.check(isinstance(data.get("error"), str), "Errors must have a stable error field")
        return data


def run(args):
    origin = args.origin.rstrip("/")
    parsed = urllib.parse.urlsplit(origin)
    if parsed.username or parsed.password or parsed.query or parsed.fragment or parsed.path:
        raise Failure("Supply a plain API origin without credentials, path, query or fragment.")
    if not parsed.hostname or parsed.scheme not in ("http", "https"):
        raise Failure("A valid API origin is required.")
    loopback = parsed.hostname in ("127.0.0.1", "localhost", "::1")
    if parsed.scheme != "https" and not (args.allow_localhost and loopback):
        raise Failure("HTTPS is required; --allow-localhost permits exact loopback HTTP for host-only checks.")
    try:
        raw_private = sys.stdin.read(16385) if args.credentials == "-" else Path(args.credentials).read_text(encoding="utf-8-sig")
        if len(raw_private) > 16384:
            raise ValueError("input size")
        private = json.loads(raw_private.lstrip("\ufeff"))
    except Exception:
        raise Failure("Cannot read the private credentials JSON; no content was printed.") from None
    if private.get("dataset") != "mapa-mobile-pilot-v1" or not private.get("company_uuid"):
        raise Failure("Private input must identify the exact synthetic mobile pilot dataset/company.")
    accounts = private.get("accounts", {})
    roles = ["crew", "hospital", "admin"]
    if "superadmin" in accounts:
        roles.append("superadmin")
    elif args.require_four_roles:
        raise Failure("Four-role acceptance requires an existing explicitly authorized superadmin account.")
    for role in roles:
        entry = accounts.get(role, {})
        if not entry.get("email") or not entry.get("password"):
            raise Failure("Missing private account credentials.")
        if role != "superadmin" and entry["email"] != role + ".demo@mapa.invalid":
            raise Failure("Verification only accepts designated .invalid synthetic pilot users.")
    client = Client(origin)
    sessions = {}
    cleanup_ok = True
    try:
        client.call("/capabilities", expected=401)
        client.call("/snapshot", token="0" * 64, expected=401)
        for role in roles:
            login = client.call("/login", body={**accounts[role], "device_name": "MAPA synthetic acceptance"})
            token = login.get("token", "")
            client.check(isinstance(token, str) and len(token) == 64, "Opaque module token required")
            sessions[role] = token
            user = login.get("user", {})
            client.check(login.get("contract") == CONTRACT, "Native contract marker required")
            client.check(user.get("role") == role and user.get("company_id") == private["company_uuid"], "Server-owned role/company mismatch")
            if role != "superadmin":
                client.check(user.get("id") == "user_mapa_mobile_demo_" + role and user.get("name", "").startswith("DEMO "), "Refusing a non-pilot identity")
            caps = client.call("/capabilities", token)
            client.check(caps.get("user") == user, "Stable native identity")
            for unsupported in ("photo_upload", "hospital_response", "admin_writes", "activities"):
                client.check(caps["capabilities"].get(unsupported) is False, "Unsupported feature must not be advertised")
            snap = client.call("/snapshot", token)
            client.check(snap.get("contract") == CONTRACT, "Snapshot native contract required")
            if role == "crew":
                client.check(user.get("driver_id") == DRIVER and user.get("vehicle_id") == VEHICLE, "Refusing to exercise an unrelated native crew/vehicle")
                client.check(snap.get("driver", {}).get("id") == DRIVER and snap.get("vehicle", {}).get("id") == VEHICLE, "Native crew/vehicle linkage")
                client.check(snap["vehicle"].get("name", "").startswith("DEMO "), "Synthetic vehicle label required")
                client.check([o["id"] for o in snap.get("orders", [])] == [ORDER], "Crew must see exactly its designated pilot dispatch")
            elif role == "hospital":
                client.check(user.get("place_id") == HOSPITAL, "Hospital native place binding")
                client.check(snap.get("orders") == [] and snap.get("vehicle") is None and snap.get("driver") is None, "Hospital must not expose fleet or crew orders")
                client.check([p["id"] for p in snap.get("places", [])] == [HOSPITAL], "Hospital sees only its bound place")
            if role != "crew":
                client.call("/duty", token, {"action_id": str(uuid.uuid4()), "online": True}, expected=403)
        crew = sessions["crew"]
        if args.exercise_demo:
            duty = {"action_id": str(uuid.uuid4()), "online": True}
            client.check(client.call("/duty", crew, duty).get("ok") is True, "Start synthetic duty")
            client.check(client.call("/duty", crew, duty).get("replayed") is True, "Duty replay is idempotent")
            client.call("/duty", crew, {**duty, "online": False}, expected=409)
            client.call("/duty", crew, {"action_id": str(uuid.uuid4()), "online": False}, expected=409)
            order = client.call("/snapshot", crew)["orders"][0]
            client.check(order["id"] == ORDER and order.get("can_acknowledge") is True, "Pilot order is an eligible native dispatch")
            ack = {"action_id": str(uuid.uuid4()), "expected_revision": order["revision"]}
            path = "/orders/" + ORDER + "/ack"
            client.check(client.call(path, crew, ack).get("ok") is True, "Explicit native dispatch acknowledgement")
            client.check(client.call(path, crew, ack).get("replayed") is True, "Acknowledgement replay is idempotent")
            client.call(path, crew, {**ack, "expected_revision": "changed"}, expected=409)
            client.call("/orders/order_mapa_mobile_demo_missing/ack", crew, ack, expected=404)
            changed = client.call("/snapshot", crew)["orders"][0]
            client.check(changed.get("acknowledged") is True and changed.get("status") == "dispatched", "ACK persists without inventing a workflow transition")
            client.check(changed.get("next_activities") == [], "Unsafe native progression stays unavailable")
            client.call("/orders/" + ORDER + "/activity", crew,
                        {"action_id": str(uuid.uuid4()), "expected_revision": changed["revision"], "activity_code": "completed"}, expected=409)
            fix = {"action_id": str(uuid.uuid4()), "latitude": 18.083, "longitude": 82.669,
                   "recorded_at": int(time.time() * 1000), "accuracy_m": 5, "heading": 0, "speed_mps": 0}
            client.check(client.call("/gps", crew, fix).get("ok") is True, "Synthetic GPS accepted for designated ambulance")
            client.check(client.call("/gps", crew, fix).get("replayed") is True, "GPS replay is idempotent")
            client.call("/gps", crew, {**fix, "latitude": 18.084}, expected=409)
            for change in ({"recorded_at": fix["recorded_at"] - 1}, {"recorded_at": fix["recorded_at"] + 600000}, {"accuracy_m": 101}):
                client.call("/gps", crew, {**fix, **change, "action_id": str(uuid.uuid4())}, expected=422)
            vehicle = client.call("/snapshot", crew)["vehicle"]
            client.check(abs(float(vehicle["latitude"]) - fix["latitude"]) < 0.000001 and abs(float(vehicle["longitude"]) - fix["longitude"]) < 0.000001, "Native coordinates read back correctly")
    finally:
        for token in sessions.values():
            try:
                client.call("/logout", token, {})
                client.call("/capabilities", token, expected=401)
            except Exception:
                cleanup_ok = False
        if not cleanup_ok:
            raise Failure("Session cleanup could not be confirmed; revoke MAPA synthetic acceptance sessions before retrying.")
    print(json.dumps({"ok": True, "checks": client.checks, "roles_tested": roles,
                      "demo_mutations": args.exercise_demo, "sessions_revoked": True,
                      "physical_android_test": False, "cross_company_regression": "separate temporary-schema suite",
                      "notice": "Only designated synthetic pilot data was exercised; no credentials printed."}, indent=2))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--origin", required=True)
    parser.add_argument("--credentials", required=True, help="Private JSON outside the repository/release, or - for stdin")
    parser.add_argument("--allow-localhost", action="store_true")
    parser.add_argument("--exercise-demo", action="store_true")
    parser.add_argument("--require-four-roles", action="store_true")
    try:
        run(parser.parse_args())
    except Failure as error:
        print("FAILED: " + str(error), file=sys.stderr)
        sys.exit(1)
    except Exception:
        print("FAILED: Unexpected verification error; private response/input details suppressed.", file=sys.stderr)
        sys.exit(1)

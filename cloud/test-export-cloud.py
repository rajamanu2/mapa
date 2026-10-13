#!/usr/bin/env python3
"""Synthetic exporter checks; no live Docker operations are performed."""
import contextlib
import copy
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch


spec = importlib.util.spec_from_file_location("mapa_export", Path(__file__).with_name("export-cloud.py"))
export = importlib.util.module_from_spec(spec)
spec.loader.exec_module(export)


def fixture():
    result = {}
    for service in export.REQUIRED:
        result[service] = {
            "Id": service + "-id", "Image": "sha256:" + service,
            "State": {"Running": True}, "Config": {"Env": []}, "Mounts": [],
        }
    result["application"]["Config"]["Env"] = [
        "APP_KEY=base64:synthetic-only", "MAPA_COMPANY_UUID=synthetic-company",
        "MAPA_MOBILE_ENABLED=true", "MAPA_MOBILE_SUPERADMIN_UUIDS=synthetic-admin",
        "MAPA_VOICE_ENABLED=true", "MAPA_VOICE_DEMO_ENABLED=true",
        "MAPA_VOICE_LIVE_ENABLED=true", "MAPA_VOICE_HUMAN_FALLBACK_CONFIGURED=true",
        "MAPA_VOICE_BRIDGE_SECRET=old-secret", "MYSQL_ROOT_PASSWORD=old-db-password",
        "UNRELATED_PRIVATE_SETTING=must-not-copy",
    ]
    result["voice-bridge"]["Config"]["Env"] = [
        "SARVAM_API_KEY=synthetic-provider-key", "EXOTEL_ACCOUNT_SID=synthetic-sid",
    ]
    result["application"]["Mounts"] = [{"Type": "volume", "Name": "synthetic_uploads", "Destination": "/fleetbase/api/storage/app"}]
    result["voice-bridge"]["Mounts"] = [{"Type": "volume", "Name": "synthetic_journal", "Destination": "/data"}]
    return result


class FakeDocker:
    def __init__(self, services, fail=None, queue=0):
        self.services = services
        self.fail = fail
        self.queue = queue
        self.calls = []

    def inventory(self, project):
        return copy.deepcopy(self.services)

    def run(self, args, *, destination=None, timeout=180):
        self.calls.append(args)
        if self.fail and self.fail(args):
            raise export.ExportError("Synthetic operation failed.")
        if destination:
            destination.write_bytes(b"SYNTHETIC DATA ONLY\x00\xff")
        elif args[0] == "cp":
            (Path(args[-1]) / "index.html").write_text("synthetic console", encoding="utf-8")
        elif "--scan" in args:
            return "synthetic_queues:default" if self.queue else ""
        elif "TYPE" in args:
            return "list"
        elif "LLEN" in args:
            return str(self.queue)
        return ""


class ExportTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.output = Path(self.temp.name) / "private-capture"
        self.services = fixture()
        self.stdout = io.StringIO()
        self.private = patch.object(export, "secure_directory", side_effect=lambda path: path.mkdir(mode=0o700))
        self.private.start()
        self.addCleanup(self.private.stop)
        self.addCleanup(self.temp.cleanup)

    def capture(self, docker):
        with contextlib.redirect_stdout(self.stdout):
            export.capture(docker, self.services, self.output, "https://mapa.example.com", export.PROJECT)

    def started(self, docker):
        return [call[1] for call in docker.calls if call[0] == "start"]

    def test_https_origin_canonical(self):
        self.assertEqual(export.origin_parts("https://MAPA.Example.com:443/"), ("https://mapa.example.com", "mapa.example.com"))

    def test_unsafe_origins_rejected(self):
        for origin in ("http://mapa.example.com", "https://user:pass@mapa.example.com", "https://mapa.example.com/path", "https://mapa.example.com?x=y", "https://mapa.example.com#fragment", "https://mapa.example.com:4200", "https://127.0.0.1", "https://localhost", "https://192.168.1.2", "https://bad..example.com"):
            with self.subTest(origin=origin), self.assertRaises(export.ExportError):
                export.origin_parts(origin)

    def test_environment_preserved_and_isolated(self):
        values = export.cloud_environment(self.services, "https://mapa.example.com")
        self.assertEqual(values["APP_KEY"], "base64:synthetic-only")
        self.assertEqual(values["MAPA_COMPANY_UUID"], "synthetic-company")
        self.assertEqual(values["MAPA_MOBILE_SUPERADMIN_UUIDS"], "synthetic-admin")
        self.assertEqual(values["SARVAM_API_KEY"], "synthetic-provider-key")
        self.assertEqual(values["MAPA_EXOTEL_ACCOUNT_SID"], "synthetic-sid")
        self.assertEqual(values["MAPA_VOICE_DEMO_ENABLED"], "true")
        self.assertEqual(values["MAPA_VOICE_LIVE_ENABLED"], "false")
        self.assertEqual(values["MAPA_VOICE_HUMAN_FALLBACK_CONFIGURED"], "false")
        self.assertEqual(values["MAPA_VOICE_PUBLIC_WSS"], "")
        self.assertEqual(values["MAIL_MAILER"], "log")
        self.assertNotIn("UNRELATED_PRIVATE_SETTING", values)
        generated = [values[key] for key in ("MYSQL_ROOT_PASSWORD", "MYSQL_PASSWORD", "MAPA_ADAPTER_TOKEN", "MAPA_VOICE_BRIDGE_SECRET", "MAPA_VOICE_STREAM_PASSWORD")]
        self.assertTrue(all(len(value) == 64 for value in generated))
        self.assertEqual(len(set(generated)), 5)
        self.assertNotIn("old-secret", generated)

    def test_missing_encryption_key_fails(self):
        self.services["application"]["Config"]["Env"] = ["MAPA_COMPANY_UUID=company"]
        with self.assertRaises(export.ExportError):
            export.cloud_environment(self.services, "https://mapa.example.com")

    def test_dotenv_has_literal_dollars_and_rejects_newlines(self):
        rendered = export.dotenv_text({"KEY": "a$b\\c"})
        self.assertIn("KEY='a$b\\c'", rendered)
        with self.assertRaises(export.ExportError):
            export.dotenv_text({"KEY": "bad\nsetting=true"})

    def test_private_output_rejects_existing_and_source(self):
        with self.assertRaises(export.ExportError):
            export.choose_output(self.temp.name)
        with self.assertRaises(export.ExportError):
            export.choose_output(str(Path(__file__).parent / "private-export"))
        self.assertEqual(export.choose_output(str(self.output)), self.output.resolve())

    def test_private_output_rejects_sync_and_public(self):
        with patch.dict(os.environ, {"OneDrive": self.temp.name}):
            with self.assertRaises(export.ExportError):
                export.choose_output(str(self.output))
        with self.assertRaises(export.ExportError):
            export.choose_output(str(Path(self.temp.name) / "public" / "capture"))

    def test_bind_mount_rejected(self):
        self.services["application"]["Mounts"][0]["Type"] = "bind"
        with self.assertRaises(export.ExportError):
            self.capture(FakeDocker(self.services))
        self.assertFalse(self.output.exists())

    def test_capture_complete_and_manifest_verified(self):
        docker = FakeDocker(self.services)
        self.capture(docker)
        self.assertFalse((self.output / "INCOMPLETE").exists())
        manifest = json.loads((self.output / "manifest.json").read_text())
        self.assertTrue(manifest["complete"])
        self.assertEqual(manifest["format"], "mapa-cloud-export-v1")
        self.assertIn("raw-console/index.html", manifest["files"])
        for name, metadata in manifest["files"].items():
            self.assertEqual(export.hash_file(self.output / name), metadata["sha256"])
            self.assertEqual((self.output / name).stat().st_size, metadata["bytes"])
        self.assertEqual(self.started(docker), [service + "-id" for service in export.WRITERS])
        first_stop = next(index for index, call in enumerate(docker.calls) if call[0] == "stop")
        self.assertTrue(all(index < first_stop for index, call in enumerate(docker.calls) if call[:2] == ["image", "save"] or call[0] == "cp"))
        self.assertFalse(any(call[0] == "stop" and call[-1] == "database-id" for call in docker.calls))
        for secret in ("synthetic-provider-key", "synthetic-only", "old-db-password"):
            self.assertNotIn(secret, self.stdout.getvalue())
            self.assertNotIn(secret, json.dumps(docker.calls))

    def test_dump_failure_restarts_writers_leaves_incomplete(self):
        docker = FakeDocker(self.services, fail=lambda args: any("mysqldump" in arg for arg in args))
        with self.assertRaises(export.ExportError):
            self.capture(docker)
        self.assertEqual(len(self.started(docker)), 4)
        self.assertTrue((self.output / "INCOMPLETE").exists())
        self.assertFalse((self.output / "manifest.json").exists())

    def test_stop_failure_still_restarts_attempted_service(self):
        docker = FakeDocker(self.services, fail=lambda args: args[0] == "stop" and args[-1] == "scheduler-id")
        with self.assertRaises(export.ExportError):
            self.capture(docker)
        self.assertEqual(self.started(docker), ["application-id", "scheduler-id"])

    def test_restart_failure_restarts_remaining_and_marks_incomplete(self):
        docker = FakeDocker(self.services, fail=lambda args: args == ["start", "queue-id"])
        with self.assertRaisesRegex(export.ExportError, "queue"):
            self.capture(docker)
        self.assertEqual(len(self.started(docker)), 4)
        self.assertTrue((self.output / "INCOMPLETE").exists())

    def test_nonempty_queue_aborts_before_dump(self):
        docker = FakeDocker(self.services, queue=2)
        with self.assertRaisesRegex(export.ExportError, "2 pending"):
            self.capture(docker)
        self.assertEqual(len(self.started(docker)), 4)
        self.assertFalse((self.output / "database.sql").exists())

    def test_initially_stopped_writers_stay_stopped(self):
        self.services["queue"]["State"]["Running"] = False
        docker = FakeDocker(self.services)
        self.capture(docker)
        self.assertNotIn("queue-id", self.started(docker))
        self.assertFalse(any(call[0] == "stop" and call[-1] == "queue-id" for call in docker.calls))

    def test_container_replacement_aborts_before_stop(self):
        docker = FakeDocker(copy.deepcopy(self.services))
        docker.services["application"]["Id"] = "replacement"
        with self.assertRaisesRegex(export.ExportError, "stack changed"):
            self.capture(docker)
        self.assertFalse(any(call[0] == "stop" for call in docker.calls))


if __name__ == "__main__":
    unittest.main(verbosity=2)

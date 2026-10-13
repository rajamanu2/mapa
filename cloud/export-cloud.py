#!/usr/bin/env python3
"""Privately capture the running MAPA pilot for an independent Linux host.

Without --capture this prints a read-only inventory and migration plan. No
credentials, records, container environment, or subprocess stderr are printed.
"""

import argparse
import csv
import hashlib
import io
import ipaddress
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import sys
from datetime import datetime, timezone
from urllib.parse import urlsplit


PROJECT = "asr-fleetbase-pilot"
WRITERS = ("application", "queue", "scheduler", "voice-bridge")
REQUIRED = (*WRITERS, "database", "cache", "console")
IMAGE_TAGS = {
    "application": "mapa-cloud-api:transfer",
    "console": "mapa-cloud-console:transfer",
    "voice-bridge": "mapa-cloud-voice-bridge:transfer",
}
SAFE_ENV = (
    "APP_KEY", "MAPA_COMPANY_UUID", "MAPA_MOBILE_ENABLED",
    "MAPA_MOBILE_SUPERADMIN_UUIDS", "MAPA_VOICE_ENABLED",
    "MAPA_VOICE_DEMO_ENABLED", "MAPA_EXOTEL_ACCOUNT_SID",
    "OPENAI_API_KEY", "MAPA_VOICE_EXTRACTION_MODEL",
    "SARVAM_API_KEY", "MAPA_VOICE_DEFAULT_LANGUAGE",
)


class ExportError(Exception):
    pass


def origin_parts(value):
    parsed = urlsplit(value)
    if (parsed.scheme != "https" or not parsed.hostname or parsed.username
            or parsed.password or parsed.query or parsed.fragment
            or parsed.path not in ("", "/") or parsed.port not in (None, 443)):
        raise ExportError("--origin must be an HTTPS origin without credentials, path, or a nonstandard port.")
    host = parsed.hostname.lower()
    if (not re.fullmatch(r"[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?", host)
            or "." not in host or ".." in host or host.endswith(".localhost")
            or host in ("localhost", "127.0.0.1", "0.0.0.0")):
        raise ExportError("--origin must use a public DNS hostname.")
    try:
        ipaddress.ip_address(host)
    except ValueError:
        pass
    else:
        raise ExportError("--origin must use a public DNS hostname, not an IP address.")
    return "https://" + host, host


def find_docker(explicit=None):
    if explicit:
        candidate = Path(explicit)
        if candidate.is_file():
            return str(candidate)
        raise ExportError("The supplied Docker executable does not exist.")
    located = shutil.which("docker")
    if located:
        return located
    local = os.environ.get("LOCALAPPDATA", "")
    for candidate in (
        Path(local) / "Programs/DockerDesktop/resources/bin/docker.exe",
        Path(os.environ.get("ProgramFiles", "C:/Program Files")) / "Docker/Docker/resources/bin/docker.exe",
    ):
        if candidate.is_file():
            return str(candidate)
    raise ExportError("Docker CLI was not found. Pass --docker with its executable path.")


class Docker:
    def __init__(self, executable):
        self.executable = executable

    def run(self, args, *, destination=None, timeout=180):
        # Binary output goes directly to an open file, including on Windows.
        # Never pass secrets as arguments, and never echo arbitrary stderr.
        target = destination.open("xb") if destination is not None else subprocess.PIPE
        try:
            result = subprocess.run(
                [self.executable, *args], stdout=target, stderr=subprocess.PIPE,
                check=False, timeout=timeout,
            )
        except subprocess.TimeoutExpired as exc:
            raise ExportError("A Docker operation timed out; inspect Docker privately before retrying.") from exc
        except OSError as exc:
            raise ExportError("A Docker operation could not be started.") from exc
        finally:
            if destination is not None:
                target.close()
        if result.returncode:
            raise ExportError(f"A Docker operation failed (exit {result.returncode}); details were withheld to protect private data.")
        return (result.stdout or b"").decode("utf-8", errors="strict").strip() if destination is None else None

    def inventory(self, project):
        ids = self.run(["ps", "-aq", "--filter", f"label=com.docker.compose.project={project}"]).splitlines()
        if not ids:
            raise ExportError("No containers were found for the selected Docker Compose project.")
        items = json.loads(self.run(["inspect", *ids]))
        services = {}
        for item in items:
            labels = item.get("Config", {}).get("Labels", {}) or {}
            service = labels.get("com.docker.compose.service")
            if service not in REQUIRED:
                continue
            if service in services:
                raise ExportError("More than one container exists for a required service; remove the ambiguity before export.")
            services[service] = item
        if set(REQUIRED) - set(services):
            raise ExportError("A required MAPA service is missing; start the complete local stack before export.")
        return services


def container_env(container):
    return dict(item.split("=", 1) for item in container.get("Config", {}).get("Env", []) if "=" in item)


def cloud_environment(services, origin):
    public_origin, host = origin_parts(origin)
    api = container_env(services["application"])
    bridge = container_env(services["voice-bridge"])
    result = {key: api.get(key, "") for key in SAFE_ENV}
    result["MAPA_COMPANY_UUID"] = result["MAPA_COMPANY_UUID"] or api.get("ASR_COMPANY_UUID", "")
    result["SARVAM_API_KEY"] = bridge.get("SARVAM_API_KEY") or result["SARVAM_API_KEY"]
    result["MAPA_EXOTEL_ACCOUNT_SID"] = bridge.get("EXOTEL_ACCOUNT_SID") or result["MAPA_EXOTEL_ACCOUNT_SID"]
    if not result["APP_KEY"] or result["APP_KEY"].startswith("generate-"):
        raise ExportError("The running API does not have an application encryption key; export stopped.")
    if not result["MAPA_COMPANY_UUID"]:
        raise ExportError("The running API does not have a MAPA company UUID; export stopped.")
    result.update({
        "MAPA_PUBLIC_ORIGIN": public_origin, "MAPA_PUBLIC_HOST": host,
        "MAPA_API_BASE_IMAGE": IMAGE_TAGS["application"],
        "MAPA_VOICE_IMAGE": IMAGE_TAGS["voice-bridge"],
        "MYSQL_ROOT_PASSWORD": secrets.token_hex(32),
        "MYSQL_PASSWORD": secrets.token_hex(32),
        "MAPA_ADAPTER_TOKEN": secrets.token_hex(32),
        "MAPA_VOICE_BRIDGE_SECRET": secrets.token_hex(32),
        "MAPA_VOICE_STREAM_USER": "mapa-stream",
        "MAPA_VOICE_STREAM_PASSWORD": secrets.token_hex(32),
        "MAPA_VOICE_LIVE_ENABLED": "false",
        "MAPA_VOICE_HUMAN_FALLBACK_CONFIGURED": "false",
        "MAPA_VOICE_PUBLIC_WSS": "",
        "MAIL_MAILER": "log",
    })
    return result


def dotenv_text(values):
    # Compose single-quoted values suppress $ interpolation. Newlines cannot be
    # represented safely by this exporter and are rejected instead of altered.
    lines = ["# PRIVATE MAPA cloud configuration. Never publish or commit this file."]
    for key, value in values.items():
        if any(char in value for char in ("\r", "\n", "\0")):
            raise ExportError("A required setting contains unsupported control characters.")
        escaped = value.replace("'", "\\'")
        lines.append(f"{key}='{escaped}'")
    return "\n".join(lines) + "\n"


def is_within(path, parent):
    try:
        path.relative_to(parent)
        return True
    except ValueError:
        return False


def choose_output(explicit=None):
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    base = Path(os.environ.get("LOCALAPPDATA", str(Path.home() / ".local/share"))) / "MAPA/CloudExports"
    destination = Path(explicit).expanduser().resolve() if explicit else (base / stamp).resolve()
    repo = Path(__file__).resolve().parents[2]
    forbidden = [repo]
    for name in ("OneDrive", "OneDriveConsumer", "OneDriveCommercial"):
        if os.environ.get(name):
            forbidden.append(Path(os.environ[name]).resolve())
    if any(is_within(destination, folder) for folder in forbidden):
        raise ExportError("The export must be outside the repository and OneDrive; use a private local directory.")
    if any(part.lower() in ("public", "wwwroot", "htdocs", "public_html") for part in destination.parts):
        raise ExportError("The export cannot be placed in a public or web-served directory.")
    if destination.exists():
        raise ExportError("The export directory already exists; choose a new private directory.")
    return destination


def secure_directory(destination):
    destination.mkdir(mode=0o700, parents=True, exist_ok=False)
    if os.name == "nt":
        identity = subprocess.run(["whoami", "/user", "/fo", "csv", "/nh"], capture_output=True, check=True, text=True)
        sid = next(csv.reader(io.StringIO(identity.stdout)))[1]
        if not re.fullmatch(r"S-1-[0-9-]+", sid):
            raise ExportError("Unable to establish the Windows account for private export permissions.")
        result = subprocess.run([
            "icacls", str(destination), "/inheritance:r", "/grant:r", f"*{sid}:(OI)(CI)F",
        ], capture_output=True, check=False)
        if result.returncode:
            raise ExportError("Unable to restrict export directory permissions. No data was captured.")
    else:
        destination.chmod(0o700)


def volume_name(container, mountpoint):
    mounts = [mount for mount in container.get("Mounts", []) if mount.get("Destination") == mountpoint]
    if len(mounts) != 1 or mounts[0].get("Type") != "volume":
        raise ExportError("A required persistent named volume could not be identified safely.")
    name = mounts[0].get("Name", "")
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]*", name):
        raise ExportError("An unexpected Docker volume name was encountered.")
    return name


def assert_queue_empty(docker, cache_id):
    keys = docker.run(["exec", cache_id, "redis-cli", "--raw", "--scan", "--pattern", "*queues:*"]).splitlines()
    pending = 0
    for key in keys:
        kind = docker.run(["exec", cache_id, "redis-cli", "--raw", "TYPE", key])
        if kind in ("list", "zset"):
            operation = "LLEN" if kind == "list" else "ZCARD"
            count = docker.run(["exec", cache_id, "redis-cli", "--raw", operation, key])
            if not count.isdigit():
                raise ExportError("Redis queue state could not be checked safely.")
            pending += int(count)
        elif kind not in ("none", "string"):
            raise ExportError("An unknown queue representation requires review before migration.")
    if pending:
        raise ExportError(f"Redis contains {pending} pending or reserved queue entries. Drain or review them before capture; no queue data is discarded.")


def hash_file(path):
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def capture(docker, services, output, origin, project):
    if not services["database"]["State"].get("Running") or not services["cache"]["State"].get("Running"):
        raise ExportError("The database and cache must be running before capture.")
    environment = dotenv_text(cloud_environment(services, origin))
    uploads = volume_name(services["application"], "/fleetbase/api/storage/app")
    journal = volume_name(services["voice-bridge"], "/data")
    secure_directory(output)
    marker = output / "INCOMPLETE"
    marker.write_text("Do not restore this export until manifest.json declares complete.\n", encoding="utf-8")
    (output / ".env").write_text(environment, encoding="utf-8")
    print("Saving the current application images and served console to the private export directory.")
    for service, tag in IMAGE_TAGS.items():
        docker.run(["image", "tag", services[service]["Image"], tag])
    docker.run(["image", "save", *IMAGE_TAGS.values()], destination=output / "images.tar", timeout=1800)
    raw_console = output / "raw-console"
    raw_console.mkdir(mode=0o700)
    docker.run(["cp", services["console"]["Id"] + ":/usr/share/nginx/html/.", str(raw_console)], timeout=600)
    # Recheck identities/state immediately before the interruption. A concurrent
    # rebuild/restart must never mix one version's image with another's database.
    current = docker.inventory(project)
    if any(current[key]["Id"] != services[key]["Id"] or current[key]["Image"] != services[key]["Image"] for key in REQUIRED):
        raise ExportError("The stack changed during preparation. Export stopped; use a new directory to retry.")
    running = [key for key in WRITERS if current[key]["State"].get("Running")]
    attempted = []
    restart_failures = []
    try:
        print("Briefly pausing the API, workers, scheduler and voice bridge for a consistent capture.")
        # Stop accepting new work before waiting for workers to finish. Remember
        # attempted stops as well: timeout can occur after Docker stopped a service.
        for service in ("application", "scheduler", "voice-bridge", "queue"):
            if service in running:
                attempted.append(service)
                docker.run(["stop", "--time", "120", current[service]["Id"]], timeout=180)
        assert_queue_empty(docker, current["cache"]["Id"])
        dump_command = ('export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysqldump --user=root '
                        '--single-transaction --routines --events --triggers --hex-blob '
                        '--set-gtid-purged=OFF --no-tablespaces fleetbase')
        docker.run(["exec", current["database"]["Id"], "sh", "-c", dump_command],
                   destination=output / "database.sql", timeout=1800)
        for name, image_id, filename in (
            (uploads, services["application"]["Image"], "uploads.tar"),
            (journal, services["voice-bridge"]["Image"], "voice-journal.tar"),
        ):
            docker.run(["run", "--rm", "--network", "none", "--read-only", "--cap-drop", "ALL",
                        "--security-opt", "no-new-privileges", "--user", "0",
                        "--mount", f"type=volume,src={name},dst=/archive,readonly",
                        "--entrypoint", "tar", image_id, "-C", "/archive", "-cpf", "-", "."],
                       destination=output / filename, timeout=1800)
    finally:
        print("Restoring the local services to their previous running state.")
        for service in WRITERS:
            if service in attempted:
                try:
                    docker.run(["start", current[service]["Id"]], timeout=180)
                except ExportError:
                    restart_failures.append(service)
        if restart_failures:
            raise ExportError("Local service restart needs attention: " + ", ".join(restart_failures) + ". The export remains incomplete.")
    files = {}
    for path in sorted(output.rglob("*")):
        if path.is_file() and path.name != "INCOMPLETE":
            files[path.relative_to(output).as_posix()] = {"bytes": path.stat().st_size, "sha256": hash_file(path)}
    manifest = {
        "format": "mapa-cloud-export-v1", "complete": True,
        "created_at": datetime.now(timezone.utc).isoformat(),
        "source_project": project, "public_origin": origin_parts(origin)[0],
        "images": {tag: services[service]["Image"] for service, tag in IMAGE_TAGS.items()},
        "files": files, "live_voice_enabled": False,
        "notes": ["Private full database and provider credentials; never publish.",
                  "Redis sessions/cache/queues are not migrated. Queues were checked empty.",
                  "Revoke copied native/mobile sessions during isolated restore before public traffic.",
                  "Reconfigure the copied console for the public HTTPS origin before serving."],
    }
    (output / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    marker.unlink()
    print("Private migration capture completed. The local application has been restarted.")
    print("Export directory: " + str(output))


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--origin", required=True, help="Planned public HTTPS origin; no path or credentials")
    parser.add_argument("--capture", action="store_true", help="Actually export private data and briefly pause writer services")
    parser.add_argument("--output", help="New PRIVATE export directory outside source, OneDrive and served folders")
    parser.add_argument("--project", default=PROJECT)
    parser.add_argument("--docker", help="Docker CLI executable path")
    args = parser.parse_args(argv)
    try:
        origin, _ = origin_parts(args.origin)
        output = choose_output(args.output)
        docker = Docker(find_docker(args.docker))
        services = docker.inventory(args.project)
        print("MAPA cloud migration " + ("CAPTURE" if args.capture else "PLAN (read-only)"))
        print("Planned public origin: " + origin)
        for key in REQUIRED:
            print(key + ": " + ("running" if services[key]["State"].get("Running") else "stopped"))
        if not args.capture:
            print("No services or files were changed. --capture saves images, served HTML, database, uploads, voice journal and private configuration.")
            print("Capture pauses only running writer services, checks queues are empty and restarts those services even after an error.")
            print("Destination: " + str(output))
            return 0
        capture(docker, services, output, origin, args.project)
        return 0
    except (ExportError, ValueError, json.JSONDecodeError, OSError, subprocess.SubprocessError) as exc:
        if isinstance(exc, ExportError):
            print("Export stopped: " + str(exc), file=sys.stderr)
        else:
            print("Export stopped because a local operation failed. No credential or record details are printed.", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())

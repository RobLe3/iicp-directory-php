#!/usr/bin/env python3
"""Admit caller-pinned, already built PHP upgrade images; never build or pull."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import tempfile

SCHEMA = "iicp.directory.operator-prebuilt-upgrade.v1"
ROOT = Path(__file__).resolve().parents[1]
HEX = r"[0-9a-f]{64}"
IMAGE = "sha256:" + HEX
FIELDS = {"source_commit", "version", "archive_sha256", "app_image", "web_image"}


def require_pattern(value, pattern):
    if not isinstance(value, str) or re.fullmatch(pattern, value) is None:
        raise ValueError("invalid pinned input")


def read_manifest(path, digest):
    require_pattern(digest, HEX)
    path = Path(os.path.abspath(path))
    if any(part.is_symlink() for part in (path, *path.parents)):
        raise ValueError("symlink input refused")
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, "rb") as stream:
        info = os.fstat(stream.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_size > 65536:
            raise ValueError("manifest type or size differs")
        raw = stream.read(65537)
    if len(raw) > 65536 or hashlib.sha256(raw).hexdigest() != digest:
        raise ValueError("manifest digest differs")
    return json.loads(raw)


def validate_component(component, source):
    require_pattern(source, r"[0-9a-f]{40}")
    if not isinstance(component, dict) or set(component) != FIELDS:
        raise ValueError("component fields differ")
    if component["source_commit"] != source:
        raise ValueError("source differs from caller pin")
    require_pattern(component["version"], r"[0-9]+\.[0-9]+\.[0-9]+(?:\.[0-9]+)?")
    require_pattern(component["archive_sha256"], HEX)
    for key in ("app_image", "web_image"):
        require_pattern(component[key], IMAGE)


def validate(value, previous_source, next_source, compose):
    if not isinstance(value, dict) or set(value) != {"schema", "platform", "compose_sha256", "previous", "next"}:
        raise ValueError("manifest fields differ")
    if value["schema"] != SCHEMA or value["platform"] not in ("linux/amd64", "linux/arm64"):
        raise ValueError("schema or platform differs")
    if value["compose_sha256"] != hashlib.sha256(compose.read_bytes()).hexdigest():
        raise ValueError("Compose contract differs")
    validate_component(value["previous"], previous_source)
    validate_component(value["next"], next_source)
    if previous_source == next_source or value["previous"]["version"] == value["next"]["version"]:
        raise ValueError("upgrade identities must differ")


def docker_output(args):
    # Inspect only selected metadata, never image environment or command output.
    with tempfile.TemporaryFile() as stream:
        result = subprocess.run(["docker", *args], stdout=stream, stderr=subprocess.DEVNULL, timeout=30)
        stream.seek(0)
        raw = stream.read(65537)
    if result.returncode or len(raw) > 65536:
        raise ValueError("Docker preflight failed")
    return raw.decode().strip()


def check_image(image, platform, component=None):
    actual = docker_output(["image", "inspect", "--format", "{{.Os}}/{{.Architecture}}", image])
    if actual != platform:
        raise ValueError("image platform differs")
    if component is None:
        return
    if docker_output(["image", "inspect", "--format", "{{.Id}}", image]) != image:
        raise ValueError("image identity differs")
    labels = {"org.opencontainers.image.revision": component["source_commit"],
              "org.opencontainers.image.version": component["version"],
              "network.iicp.release-archive-sha256": component["archive_sha256"]}
    for label, expected in labels.items():
        actual = docker_output(["image", "inspect", "--format", '{{index .Config.Labels "' + label + '"}}', image])
        if actual != expected:
            raise ValueError("image provenance differs")


def verify(value, compose):
    host = docker_output(["info", "--format", "{{.OSType}}/{{.Architecture}}"])
    host = {"linux/x86_64": "linux/amd64", "linux/aarch64": "linux/arm64"}.get(host, host)
    if host != value["platform"]:
        raise ValueError("native Docker host platform differs")
    for name in ("previous", "next"):
        component = value[name]
        for key in ("app_image", "web_image"):
            check_image(component[key], value["platform"], component)
    dependencies = re.findall(r"^\s+image: ([^\s]+@sha256:[0-9a-f]{64})$", compose.read_text(), re.MULTILINE)
    if len(dependencies) != 2:
        raise ValueError("dependency image contract differs")
    for image in dependencies:
        check_image(image, value["platform"])


def write_overrides(work, value):
    # !reset removes inherited builds. Parse failure aborts before starting services.
    for name in ("previous", "next"):
        component = value[name]
        lines = ["services:"]
        for service in ("app", "scheduler", "migrate", "web"):
            image = component["web_image" if service == "web" else "app_image"]
            lines.extend([f"  {service}:", f'    image: "{image}"', "    build: !reset null", "    pull_policy: never"])
        for service in ("db", "secret-init"):
            lines.extend([f"  {service}:", "    pull_policy: never"])
        path = work / ("v" + component["version"] + ".yml")
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, "w") as output:
            output.write("\n".join(lines) + "\n")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--manifest", required=True, type=Path)
    parser.add_argument("--manifest-sha256", required=True)
    parser.add_argument("--previous-source", required=True)
    parser.add_argument("--next-source", required=True)
    args = parser.parse_args()
    compose = ROOT / "compose.operator.yml"
    value = read_manifest(args.manifest, args.manifest_sha256)
    validate(value, args.previous_source, args.next_source, compose)
    verify(value, compose)
    print(json.dumps({"manifest": value, "manifest_sha256": args.manifest_sha256,
                      "qualification_credit": 0, "deployment_authorized": False}, sort_keys=True))


if __name__ == "__main__":
    try:
        main()
    except (ValueError, OSError, TypeError, subprocess.TimeoutExpired) as error:
        print(f"prebuilt inputs refused: {type(error).__name__}", file=__import__('sys').stderr)
        raise SystemExit(3)

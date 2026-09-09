#!/usr/bin/env python3
"""Build native, source-bound operator images for transfer; no registry publication."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import signal
import subprocess
import tarfile
import tempfile
import time
import uuid

import operator_prebuilt_inputs as admission
from build_pre1_candidate_artifacts import safe_extract
from operator_rehearsal_evidence import safe_directory, write_json
from run_php83_local_ci import BUILDKIT, capture

ROOT = Path(__file__).resolve().parents[1]
SOURCES = {"previous": "bffb952919369758428895267efc5a79a2de657e",
           "next": "bf42a21ad49672f79728e89b9322698e631b1434"}


class TransferError(ValueError):
    """Static content-free failure reason safe for a retained receipt."""


def digest(path):
    value = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            value.update(block)
    return value.hexdigest()


def inspect(*args):
    return subprocess.check_output(list(args), text=True, timeout=30).strip()


def archive_files(stream):
    rows, total = {}, 0
    try:
        with tarfile.open(fileobj=stream, mode="r:*") as archive:
            for member in archive:
                if member.isdir():
                    continue
                total += member.size
                if not member.isfile() or member.name in rows or total > 64 * 1024**2 or len(rows) >= 5000:
                    raise TransferError("archive member bounds differ")
                rows[member.name] = hashlib.sha256(archive.extractfile(member).read()).hexdigest()
    except tarfile.TarError as error:
        raise TransferError("invalid source archive") from error
    return rows


def verify_archive_source(archive, source, version):
    # Compression/header bytes can vary across platforms. Never reuse the old
    # artifact digest: compare every file to a fresh archive of the pinned Git
    # source, then bind the actual emitted archive bytes in the transfer.
    with tempfile.TemporaryFile() as expected:
        subprocess.run(["git", "-C", str(ROOT), "archive", "--format=tar",
                        "--prefix=iicp-directory-php-v" + version + "/", source],
                       stdout=expected, stderr=subprocess.DEVNULL, check=True, timeout=30)
        expected.seek(0)
        with archive.open("rb") as actual:
            if archive_files(actual) != archive_files(expected):
                raise TransferError("archive content differs from pinned source")


def validate_transfer_identity(value):
    fields = {"schema", "platform", "driver_source", "dependencies", "files", "qualification_credit", "non_authorizing"}
    if (not isinstance(value, dict) or set(value) != fields
            or value["schema"] != "iicp.directory.operator-transfer.v1"
            or value["platform"] != "linux/amd64"
            or type(value["qualification_credit"]) is not int or value["qualification_credit"] != 0
            or value["non_authorizing"] is not True
            or not re.fullmatch(r"[0-9a-f]{40}", str(value["driver_source"]))):
        raise TransferError("transfer identity differs")


def verify_transfer_files(root, rows):
    expected = {"inputs.json", "images.tar.gz"}
    for role, version in (("previous", "1.10.93"), ("next", "1.10.94")):
        expected.update(role + "-release/" + name for name in (
            "iicp-directory-php-v" + version + ".tar.gz", "RELEASE-MANIFEST.json", "SHA256SUMS"))
    if (not isinstance(rows, list) or any(not isinstance(r, dict) or set(r) != {"name", "sha256", "size_bytes"} for r in rows)
            or len(rows) != len(expected) or {r["name"] for r in rows} != expected):
        raise TransferError("transfer inventory differs")
    for row in rows:
        path = root / row["name"]
        safe_directory(path.parent)
        if (path.is_symlink() or not path.is_file() or type(row["size_bytes"]) is not int
                or not 0 < row["size_bytes"] <= 1024**3
                or path.stat().st_size != row["size_bytes"] or digest(path) != row["sha256"]):
            raise TransferError("transfer file differs")


def verify_transfer(root, expected_digest):
    root = safe_directory(root)
    value = admission.read_manifest(root / "transfer.json", expected_digest)
    validate_transfer_identity(value)
    rows = value["files"]
    verify_transfer_files(root, rows)
    inputs = admission.read_manifest(root / "inputs.json", next(r["sha256"] for r in rows if r["name"] == "inputs.json"))
    admission.validate(inputs, SOURCES["previous"], SOURCES["next"], ROOT / "compose.operator.yml")
    for role, source in SOURCES.items():
        archive = root / (role + "-release") / ("iicp-directory-php-v" + inputs[role]["version"] + ".tar.gz")
        metadata = root / (role + "-release") / "RELEASE-MANIFEST.json"
        release = admission.read_manifest(metadata, next(r["sha256"] for r in rows if r["name"] == str(metadata.relative_to(root))))
        if (digest(archive) != inputs[role]["archive_sha256"]
                or release.get("commit") != source or release.get("version") != inputs[role]["version"]
                or release.get("source_archive_sha256") != inputs[role]["archive_sha256"]):
            raise TransferError("source archive identity differs")
    dependencies = re.findall(r"^\s+image: ([^\s]+@sha256:[0-9a-f]{64})$", (ROOT / "compose.operator.yml").read_text(), re.M)
    if value["dependencies"] != dependencies or len(dependencies) != 2:
        raise TransferError("dependency identity differs")
    return value


def preflight(output):
    output = Path(os.path.abspath(output))
    parent = safe_directory(output.parent, allow_sticky=True)
    output = (parent / output.name).resolve()
    if parent.stat().st_mode & 0o022 and not parent.stat().st_mode & 0o1000:
        raise TransferError("private output parent required")
    if output.exists() or output.is_symlink() or output.is_relative_to(ROOT):
        raise TransferError("new output outside checkout required")
    if shutil.disk_usage(parent).free < 8 * 1024**3:
        raise TransferError("native build needs eight GiB free scratch space")
    if inspect("docker", "info", "--format", "{{.OSType}}/{{.Architecture}}") not in ("linux/x86_64", "linux/amd64"):
        raise TransferError("native Linux x86-64 required")
    if inspect("git", "-C", str(ROOT), "status", "--porcelain"):
        raise TransferError("clean committed driver required")
    for source in SOURCES.values():
        if inspect("git", "-C", str(ROOT), "rev-parse", source + "^{commit}") != source:
            raise TransferError("source unavailable")
    return output


class Builder:
    def __init__(self, output):
        self.output = output
        self.name = "iicp-php-transfer-" + uuid.uuid4().hex[:16]
        self.images = []
        self.receipt = {"schema": "iicp.directory.operator-transfer-result.v1",
                        "status": "FAIL", "qualification_credit": 0,
                        "non_authorizing": True, "phases": [], "buildkit": BUILDKIT,
                        "driver_source": inspect("git", "-C", str(ROOT), "rev-parse", "HEAD")}
        self.receipt["free_bytes_before"] = shutil.disk_usage(output).free

    def phase(self, name, command, timeout=900):
        started = time.monotonic()
        code = capture(command, self.output / (name + ".log"), timeout)
        self.receipt["phases"].append({"phase": name, "exit_code": code,
                                       "seconds": round(time.monotonic() - started, 2)})
        print(json.dumps(self.receipt["phases"][-1]), flush=True)
        if code:
            raise RuntimeError(name + " failed")

    def component(self, role):
        source = SOURCES[role]
        clone, context = self.output / role, self.output / (role + "-context")
        self.phase(role + "-clone", ["git", "clone", "--no-hardlinks", "--no-checkout", str(ROOT), str(clone)], 120)
        self.phase(role + "-checkout", ["git", "-C", str(clone), "checkout", "--detach", source], 60)
        version = (clone / "VERSION").read_text().strip()
        if not re.fullmatch(r"\d+\.\d+\.\d+", version):
            raise TransferError("invalid version")
        release = self.output / (role + "-release")
        self.phase(role + "-archive", ["env", "IICP_RELEASE_ALLOW_UNTAGGED=1", "bash",
                   str(clone / "scripts/build_release_artifacts.sh"), version, str(release)], 120)
        archive = release / ("iicp-directory-php-v" + version + ".tar.gz")
        archive_digest = digest(archive)
        verify_archive_source(archive, source, version)
        extracted = safe_extract(archive, context)
        row = {"source_commit": source, "version": version, "archive_sha256": archive_digest}
        for flavor, dockerfile in (("app", "Dockerfile.operator"), ("web", "Dockerfile.operator-nginx")):
            tag = self.name + "-" + role + "-" + flavor
            self.images.append(tag)  # Track before a command that could time out.
            self.phase(role + "-" + flavor, ["docker", "buildx", "build", "--builder", self.name,
                       "--load", "--platform", "linux/amd64", "--tag", tag, "--progress", "plain",
                       "--label", "org.opencontainers.image.revision=" + source,
                       "--label", "org.opencontainers.image.version=" + version,
                       "--label", "network.iicp.release-archive-sha256=" + archive_digest,
                       "-f", str(extracted / dockerfile), str(extracted)])
            row[flavor + "_image"] = admission.docker_output(["image", "inspect", "--format", "{{.Id}}", tag])
            admission.check_image(row[flavor + "_image"], "linux/amd64", row)
        return row

    def build(self):
        self.phase("builder", ["docker", "buildx", "create", "--name", self.name, "--driver", "docker-container",
                   "--driver-opt", "image=" + BUILDKIT], 120)
        manifest = {"schema": admission.SCHEMA, "platform": "linux/amd64",
                    "compose_sha256": digest(ROOT / "compose.operator.yml")}
        for role in SOURCES:
            manifest[role] = self.component(role)
        admission.validate(manifest, SOURCES["previous"], SOURCES["next"], ROOT / "compose.operator.yml")
        write_json(self.output / "inputs.json", manifest)
        # Runtime dependencies are pulled by digest during future guest bootstrap,
        # not saved by ID: docker load does not preserve repository digests.
        dependencies = re.findall(r"^\s+image: ([^\s]+@sha256:[0-9a-f]{64})$",
                                  (ROOT / "compose.operator.yml").read_text(), re.M)
        if len(dependencies) != 2:
            raise TransferError("dependency contract differs")
        self.phase("export", ["docker", "image", "save", "-o", str(self.output / "images.tar"),
                   *[manifest[r][k] for r in SOURCES for k in ("app_image", "web_image")]], 300)
        self.phase("compress", ["gzip", "-n", str(self.output / "images.tar")], 300)
        if (self.output / "images.tar.gz").stat().st_size > 1024**3:
            raise TransferError("image transfer exceeds one GiB")
        files = [self.output / "images.tar.gz", self.output / "inputs.json"]
        files += sorted(self.output.glob("*-release/*"))
        write_json(self.output / "transfer.json", {
            "schema": "iicp.directory.operator-transfer.v1", "platform": "linux/amd64",
            "driver_source": self.receipt["driver_source"], "dependencies": dependencies,
            "files": [{"name": str(p.relative_to(self.output)), "sha256": digest(p),
                       "size_bytes": p.stat().st_size} for p in files],
            "qualification_credit": 0, "non_authorizing": True})
        self.receipt["status"] = "PASS"

    def cleanup(self):
        errors = []
        for index, command in enumerate([
                ["docker", "buildx", "rm", "--force", self.name],
                *[["docker", "image", "rm", tag] for tag in self.images]]):
            code = capture(command, self.output / ("cleanup-" + str(index) + ".log"), 120)
            if code:
                errors.append(index)
        try:
            present = inspect("docker", "volume", "ls", "--quiet", "--filter", "name=buildx_buildkit_" + self.name)
            present += inspect("docker", "container", "ls", "--all", "--quiet", "--filter", "name=buildx_buildkit_" + self.name)
            present += "".join(inspect("docker", "image", "ls", "--quiet", tag) for tag in self.images)
            self.receipt["resources_absent"] = not present
        except (OSError, subprocess.SubprocessError):
            self.receipt["resources_absent"] = False
        self.receipt["cleanup_failures"] = errors
        if errors or not self.receipt["resources_absent"]:
            self.receipt["status"] = "FAIL"
        if self.receipt["status"] == "PASS":
            for role in SOURCES:
                shutil.rmtree(self.output / role)
                shutil.rmtree(self.output / (role + "-context"))
        self.receipt["free_bytes_after"] = shutil.disk_usage(self.output).free
        write_json(self.output / "result.json", self.receipt)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--output", type=Path)
    group.add_argument("--verify", type=Path)
    parser.add_argument("--manifest-sha256")
    args = parser.parse_args()
    os.umask(0o077)
    if args.verify:
        verify_transfer(args.verify, args.manifest_sha256)
        print(json.dumps({"status": "VERIFIED", "qualification_credit": 0, "non_authorizing": True}))
        return 0
    if args.manifest_sha256:
        parser.error("manifest digest is only accepted with --verify")
    signal.signal(signal.SIGTERM, lambda *_: (_ for _ in ()).throw(KeyboardInterrupt()))
    output = preflight(args.output)
    output.mkdir(mode=0o700)
    builder = Builder(output)
    try:
        builder.build()
    except (OSError, ValueError, RuntimeError, subprocess.SubprocessError) as error:
        builder.receipt["failure_type"] = type(error).__name__
        builder.receipt["reason"] = str(error) if isinstance(error, TransferError) else type(error).__name__
    finally:
        previous = {sig: signal.signal(sig, signal.SIG_IGN) for sig in (signal.SIGINT, signal.SIGTERM)}
        try:
            builder.cleanup()
        finally:
            for sig, handler in previous.items():
                signal.signal(sig, handler)
    return 0 if builder.receipt["status"] == "PASS" else 1


if __name__ == "__main__":
    raise SystemExit(main())

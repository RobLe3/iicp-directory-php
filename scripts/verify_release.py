#!/usr/bin/env python3
"""Verify public-directory release truth and emit a content-free manifest."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
VERSION_PATTERN = re.compile(r"\d+\.\d+\.\d+(?:\.\d+)?")


def run(*args: str) -> str:
    return subprocess.check_output(args, cwd=ROOT, text=True).strip()


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def tracked_source_digest() -> str:
    digest = hashlib.sha256()
    files = run("git", "ls-files", "-z").split("\0")
    for relative in sorted(item for item in files if item):
        path = ROOT / relative
        if not path.is_file():
            raise RuntimeError(f"tracked file is missing: {relative}")
        digest.update(relative.encode())
        digest.update(b"\0")
        digest.update(sha256(path).encode())
        digest.update(b"\n")
    return "sha256:" + digest.hexdigest()


def verify(version: str, allow_untagged: bool) -> dict[str, object]:
    if VERSION_PATTERN.fullmatch(version) is None:
        raise RuntimeError(f"invalid release version: {version}")
    if (ROOT / "VERSION").read_text().strip() != version:
        raise RuntimeError("VERSION does not match requested release")
    config = (ROOT / "config/app.php").read_text()
    if f"'iicp_version' => 'v{version}'" not in config:
        raise RuntimeError("config/app.php runtime version does not match VERSION")
    if subprocess.run(["git", "diff", "--quiet", "HEAD", "--"], cwd=ROOT).returncode != 0:
        raise RuntimeError("release verification requires no tracked source changes")
    if subprocess.run(["git", "diff", "--cached", "--quiet", "HEAD", "--"], cwd=ROOT).returncode != 0:
        raise RuntimeError("release verification requires no staged source changes")

    head = run("git", "rev-parse", "HEAD")
    tag = f"v{version}"
    if not allow_untagged:
        exact = run("git", "describe", "--tags", "--exact-match", "HEAD")
        if exact != tag:
            raise RuntimeError(f"HEAD must be the immutable release tag {tag}")

    migrations = sorted(path.name for path in (ROOT / "database/migrations").glob("*.php"))
    if len(migrations) != len(set(migrations)) or migrations != sorted(migrations):
        raise RuntimeError("migration history is not uniquely ordered")
    if not migrations:
        raise RuntimeError("release has no migration history")
    for forbidden in [".env", "auth.json", "ruvector.db"]:
        if forbidden in run("git", "ls-files").splitlines():
            raise RuntimeError(f"forbidden generated or secret file is tracked: {forbidden}")

    return {
        "schema": "iicp.directory.release-manifest.v1",
        "version": version,
        "tag": tag,
        "commit": head,
        "source_tree_digest": tracked_source_digest(),
        "composer_lock_sha256": sha256(ROOT / "composer.lock"),
        "migration_count": len(migrations),
        "migration_head": migrations[-1],
        "historical_seed_manifest": "parity/seed-manifest-v1.10.76.2.json",
        "production_deployment_authorized": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--version", required=True)
    parser.add_argument("--allow-untagged", action="store_true")
    parser.add_argument("--archive", type=Path)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()

    manifest = verify(args.version, args.allow_untagged)
    if args.archive:
        manifest["source_archive"] = args.archive.name
        manifest["source_archive_sha256"] = sha256(args.archive)
    rendered = json.dumps(manifest, indent=2, sort_keys=True) + "\n"
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(rendered)
    print(rendered, end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

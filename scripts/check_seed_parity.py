#!/usr/bin/env python3
"""Verify that a dedicated PHP directory exactly matches a seed manifest."""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
import sys
from pathlib import Path


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def git_blob(php_dir: Path, revision: str, relative: str) -> bytes | None:
    result = subprocess.run(
        ["git", "-C", str(php_dir), "show", f"{revision}:{relative}"],
        capture_output=True,
        check=False,
    )

    return result.stdout if result.returncode == 0 else None


def verify(
    manifest_path: Path,
    php_dir: Path,
    seed_dir: Path | None = None,
    git_revision: str | None = None,
) -> list[str]:
    manifest = json.loads(manifest_path.read_text())
    if manifest.get("mirror_policy") != "strict_source_mirror":
        return ["manifest does not declare strict_source_mirror"]
    if git_revision is not None and re.fullmatch(r"[0-9a-f]{40}", git_revision) is None:
        return ["--git-revision must be an immutable 40-character lowercase commit SHA"]
    problems: list[str] = []
    for item in manifest.get("files", []):
        relative = item["path"]
        expected = item["sha256"]
        if git_revision is not None:
            content = git_blob(php_dir, git_revision, relative)
            if content is None:
                problems.append(f"PHP provenance revision missing: {relative}")
            elif hashlib.sha256(content).hexdigest() != expected:
                problems.append(f"PHP provenance digest mismatch: {relative}")
        else:
            target = php_dir / relative
            if not target.is_file():
                problems.append(f"PHP mirror missing: {relative}")
            elif sha256(target) != expected:
                problems.append(f"PHP mirror digest mismatch: {relative}")
        if seed_dir is not None:
            source = seed_dir / relative
            if not source.is_file():
                problems.append(f"seed manifest path missing: {relative}")
            elif sha256(source) != expected:
                problems.append(f"seed manifest stale: {relative}")
    return problems


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument("--php-dir", type=Path, required=True)
    parser.add_argument("--seed-dir", type=Path)
    parser.add_argument(
        "--git-revision",
        help="verify the historical public extraction at this immutable Git revision",
    )
    args = parser.parse_args()
    failures = verify(
        args.manifest,
        args.php_dir,
        args.seed_dir,
        args.git_revision,
    )
    if failures:
        print("PHP seed mirror check failed:", *failures, sep="\n- ")
        return 1
    if args.git_revision:
        print(f"PHP extraction provenance check passed at {args.git_revision}.")
    else:
        print("PHP seed mirror check passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

#!/usr/bin/env python3
"""Verify that a dedicated PHP directory exactly matches a seed manifest."""
from __future__ import annotations

import argparse
import hashlib
import json
import sys
from pathlib import Path


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def verify(manifest_path: Path, php_dir: Path, seed_dir: Path | None = None) -> list[str]:
    manifest = json.loads(manifest_path.read_text())
    if manifest.get("mirror_policy") != "strict_source_mirror":
        return ["manifest does not declare strict_source_mirror"]
    problems: list[str] = []
    for item in manifest.get("files", []):
        relative = item["path"]
        expected = item["sha256"]
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
    args = parser.parse_args()
    failures = verify(args.manifest, args.php_dir, args.seed_dir)
    if failures:
        print("PHP seed mirror check failed:", *failures, sep="\n- ")
        return 1
    print("PHP seed mirror check passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

#!/usr/bin/env python3
"""Reject tracked or archived Laravel keys that look like real credentials."""

from __future__ import annotations

import argparse
import re
import subprocess
import tarfile
from collections.abc import Iterable
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
LARAVEL_KEY = re.compile(rb"base64:[A-Za-z0-9+/]{43}=")
KEY_CONTEXT = re.compile(rb"(?:APP_KEY|app\.key)", re.IGNORECASE)
PRIVATE_KEY = re.compile(rb"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----")
FORBIDDEN_TRACKED = {".env", "auth.json", "ruvector.db"}


def tracked_files(root: Path) -> list[Path]:
    result = subprocess.run(
        ["git", "ls-files", "-z"],
        cwd=root,
        check=True,
        capture_output=True,
    )
    return [
        root / item.decode()
        for item in result.stdout.split(b"\0")
        if item
    ]


def findings(entries: Iterable[tuple[str, bytes]]) -> list[str]:
    found: list[str] = []
    for name, content in entries:
        if PRIVATE_KEY.search(content):
            found.append(f"{name}: private-key material")
        for line_number, line in enumerate(content.splitlines(), 1):
            if KEY_CONTEXT.search(line) and LARAVEL_KEY.search(line):
                found.append(f"{name}:{line_number}: credential-shaped Laravel key")
    return found


def scan_tree(root: Path) -> list[str]:
    files = tracked_files(root)
    found = [
        f"{path.relative_to(root)}: forbidden tracked file"
        for path in files
        if str(path.relative_to(root)) in FORBIDDEN_TRACKED
    ]
    entries = (
        (str(path.relative_to(root)), path.read_bytes())
        for path in files
        if path.is_file()
    )
    return found + findings(entries)


def scan_archive(archive: Path) -> list[str]:
    with tarfile.open(archive, "r:*") as bundle:
        entries = (
            (member.name, handle.read())
            for member in bundle.getmembers()
            if member.isfile()
            and (handle := bundle.extractfile(member)) is not None
        )
        return findings(entries)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=ROOT)
    parser.add_argument("--archive", type=Path)
    args = parser.parse_args()

    found = scan_archive(args.archive) if args.archive else scan_tree(args.root)
    if found:
        print("secret hygiene gate failed:")
        for item in found:
            print(f"- {item}")
        return 1

    target = "release archive" if args.archive else "tracked source"
    print(f"secret hygiene gate passed: {target}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

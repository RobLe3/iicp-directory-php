#!/usr/bin/env python3
"""Require accountable, issue-linked Semgrep suppressions."""

from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCANNED_SUFFIXES = {".php", ".yml", ".yaml"}
SUPPRESSION = re.compile(r"nosemgrep(?::[^\s]+)?", re.IGNORECASE)
RATIONALE = re.compile(r"reason=\S+")
ISSUE = re.compile(r"issue=(?:https://github\.com/[^\s]+/issues/\d+|#\d+)")


def main() -> int:
    failures: list[str] = []
    for path in sorted(ROOT.rglob("*")):
        if (
            not path.is_file()
            or path.suffix not in SCANNED_SUFFIXES
            or any(part in {"vendor", "storage"} for part in path.parts)
        ):
            continue
        for number, line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
            if SUPPRESSION.search(line) and not (RATIONALE.search(line) and ISSUE.search(line)):
                failures.append(f"{path.relative_to(ROOT)}:{number}: suppression needs reason=... and issue=#N")
    if failures:
        print("\n".join(failures), file=sys.stderr)
        return 1
    print("Semgrep suppression policy passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

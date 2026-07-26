#!/usr/bin/env python3
"""Enforce repository and changed-line coverage from PHPUnit Clover XML."""

from __future__ import annotations

import argparse
import subprocess
import sys
import xml.etree.ElementTree as ET
from pathlib import Path


def clover_totals(path: Path) -> tuple[int, int]:
    root = ET.parse(path).getroot()
    project = root.find("project")
    if project is None:
        raise ValueError("Clover report has no project element")
    metrics = project.find("metrics")
    if metrics is None:
        raise ValueError("Clover report has no project metrics")
    return int(metrics.attrib["statements"]), int(metrics.attrib["coveredstatements"])


def covered_lines(path: Path, root: Path) -> dict[str, dict[int, bool]]:
    report: dict[str, dict[int, bool]] = {}
    for file_node in ET.parse(path).getroot().findall(".//file"):
        name = Path(file_node.attrib["name"])
        try:
            relative = name.resolve().relative_to(root.resolve()).as_posix()
        except ValueError:
            continue
        lines: dict[int, bool] = {}
        for line in file_node.findall("line"):
            if line.attrib.get("type") not in {"stmt", "method"}:
                continue
            number = int(line.attrib["num"])
            lines[number] = lines.get(number, False) or int(line.attrib.get("count", "0")) > 0
        report[relative] = lines
    return report


def changed_lines(revision: str, root: Path) -> dict[str, set[int]]:
    result = subprocess.run(
        ["git", "diff", "--unified=0", "--no-ext-diff", revision, "--", "*.php"],
        cwd=root,
        text=True,
        capture_output=True,
        check=True,
    )
    changed: dict[str, set[int]] = {}
    current: str | None = None
    for line in result.stdout.splitlines():
        if line.startswith("+++ b/"):
            current = line[6:]
        elif current and line.startswith("@@"):
            added = line.split("+", 1)[1].split(" ", 1)[0]
            start_text, _, count_text = added.partition(",")
            start = int(start_text)
            count = int(count_text or "1")
            changed.setdefault(current, set()).update(range(start, start + count))
    return changed


def percentage(covered: int, total: int) -> float:
    return 100.0 if total == 0 else covered * 100.0 / total


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--clover", type=Path, required=True)
    parser.add_argument("--minimum", type=float, required=True)
    parser.add_argument("--changed-since")
    parser.add_argument("--changed-minimum", type=float, default=80.0)
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]

    total, covered = clover_totals(args.clover)
    overall = percentage(covered, total)
    print(f"Application line coverage: {overall:.2f}% ({covered}/{total}); floor {args.minimum:.2f}%")
    failed = overall + 1e-9 < args.minimum

    if args.changed_since:
        executable = covered_lines(args.clover, root)
        changed = changed_lines(args.changed_since, root)
        relevant = [
            executable[path][line]
            for path, lines in changed.items()
            for line in lines
            if path in executable and line in executable[path]
        ]
        changed_covered = sum(relevant)
        changed_pct = percentage(changed_covered, len(relevant))
        print(
            f"Changed executable PHP line coverage: {changed_pct:.2f}% "
            f"({changed_covered}/{len(relevant)}); floor {args.changed_minimum:.2f}%"
        )
        failed |= changed_pct + 1e-9 < args.changed_minimum

    if failed:
        print("Coverage floor failed.", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

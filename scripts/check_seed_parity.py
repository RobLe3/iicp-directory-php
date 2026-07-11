#!/usr/bin/env python3
"""Check the versioned seed-directory parity contract for PHP and Rust variants.

The Laravel directory is the current protocol/control-plane authority.  This
checker intentionally verifies observable entry points and mandatory safety
components instead of framework-specific implementation details.  It reports
known Rust gaps by default; `--strict` turns any gap into a CI failure.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8") if path.is_file() else ""


def version_of(directory: Path) -> str | None:
    match = re.search(r"'iicp_version'\s*=>\s*'([^']+)'", read(directory / "config/app.php"))
    return match.group(1) if match else None


def check_php(root: Path, spec: dict) -> list[str]:
    problems: list[str] = []
    for relative in spec["php"]["required_paths"]:
        if not (root / relative).is_file():
            problems.append(f"missing required PHP path: {relative}")
    routes = "\n".join(read(path) for path in (root / "routes").glob("*.php"))
    for fragment in spec["php"]["required_route_fragments"]:
        if fragment not in routes:
            problems.append(f"missing required PHP route fragment: {fragment}")
    return problems


def check_rust(root: Path, spec: dict) -> list[str]:
    source = "\n".join(read(path) for path in (root / "src").glob("*.rs"))
    problems: list[str] = []
    for fragment in spec["rust"]["required_route_fragments"]:
        if fragment not in source:
            problems.append(f"missing required Rust route: {fragment}")
    for marker in spec["rust"]["required_capability_markers"]:
        if marker not in source:
            problems.append(f"missing required Rust capability marker: {marker}")
    return problems


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--php-dir", type=Path, required=True)
    parser.add_argument("--rust-dir", type=Path)
    parser.add_argument("--contract", type=Path, default=Path("directory/parity/contract-v1.10.69.json"))
    parser.add_argument("--strict", action="store_true", help="fail when any variant has a parity gap")
    args = parser.parse_args()

    contract = json.loads(args.contract.read_text(encoding="utf-8"))
    expected = contract["contract_version"]
    php_version = version_of(args.php_dir)
    php_problems = ([] if php_version == expected else [f"PHP version is {php_version!r}, expected {expected}"])
    php_problems += check_php(args.php_dir, contract)
    rust_problems = check_rust(args.rust_dir, contract) if args.rust_dir else []

    report = {
        "contract": expected,
        "php": {"status": "aligned" if not php_problems else "gap", "problems": php_problems},
        "rust": {
            "status": "not_checked" if not args.rust_dir else ("aligned" if not rust_problems else "gap"),
            "problems": rust_problems,
        },
    }
    print(json.dumps(report, indent=2, sort_keys=True))
    return 1 if args.strict and (php_problems or rust_problems) else 0


if __name__ == "__main__":
    raise SystemExit(main())

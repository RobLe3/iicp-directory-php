#!/usr/bin/env python3
"""Validate blank or completed disposable registration-limit evidence."""

from __future__ import annotations

import argparse
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DEFAULT = ROOT / "evidence/registration-limit-measurement-v1.json"
SCENARIOS = {
    "fresh_registration",
    "authenticated_reregistration",
    "heartbeat",
    "malformed_registration",
}
RECOMMENDATIONS = {"retain_current_limits", "collect_representative_evidence"}


def validate(record: dict) -> list[str]:
    errors: list[str] = []
    present = record.get("result_present")
    if present not in {True, False}:
        return ["result_present must be boolean"]
    environment = record.get("environment", {})
    if environment.get("disposable_database") is not True or environment.get("loopback_only") is not True:
        errors.append("measurement must use a disposable loopback environment")
    if environment.get("production_endpoint_used") is not False or environment.get("production_database_used") is not False:
        errors.append("measurement cannot use production")
    for field in ("claim_boundary", "privacy"):
        values = record.get(field, {})
        if not values or any(value is not False for value in values.values()):
            errors.append(f"{field} must retain every content-free boundary")
    if set(record.get("scenarios", {})) != SCENARIOS:
        errors.append("measurement must contain the complete scenario set")
    if not present:
        if record.get("status") != "blank-disposable-measurement-template":
            errors.append("empty evidence must identify itself as a blank template")
        return errors

    if not record.get("measured_at_utc") or not record.get("source_commit"):
        errors.append("completed measurement requires time and source commit")
    for name, scenario in record.get("scenarios", {}).items():
        if not isinstance(scenario, dict):
            errors.append(f"{name}: completed scenario object required")
            continue
        if not isinstance(scenario.get("attempts"), int) or scenario["attempts"] <= 0:
            errors.append(f"{name}: positive attempt count required")
        if not isinstance(scenario.get("duration_ms"), int) or scenario["duration_ms"] < 0:
            errors.append(f"{name}: non-negative duration required")
        if not scenario.get("status_counts") or not scenario.get("expected_boundary_observed"):
            errors.append(f"{name}: status counts and boundary outcome required")
    if not record.get("observations"):
        errors.append("completed measurement requires bounded observations")
    if record.get("recommendation") not in RECOMMENDATIONS:
        errors.append("completed measurement requires a bounded recommendation")
    return errors


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("record", nargs="?", type=Path, default=DEFAULT)
    args = parser.parse_args()
    errors = validate(json.loads(args.record.read_text(encoding="utf-8")))
    if errors:
        for error in errors:
            print(f"ERROR: {error}")
        return 1
    print("registration-limit measurement record valid")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

#!/usr/bin/env python3
"""Unit tests for deterministic Clover coverage calculations."""

from __future__ import annotations

import importlib.util
import tempfile
import unittest
from pathlib import Path

MODULE_PATH = Path(__file__).with_name("check_coverage_floor.py")
SPEC = importlib.util.spec_from_file_location("check_coverage_floor", MODULE_PATH)
assert SPEC and SPEC.loader
COVERAGE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(COVERAGE)


class CoverageFloorTests(unittest.TestCase):
    def test_reads_project_statement_totals(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            report = Path(directory) / "coverage.xml"
            report.write_text(
                '<coverage><project><metrics statements="10" coveredstatements="8"/></project></coverage>',
                encoding="utf-8",
            )
            self.assertEqual(COVERAGE.clover_totals(report), (10, 8))
            self.assertEqual(COVERAGE.percentage(8, 10), 80.0)

    def test_empty_changed_executable_set_is_not_a_false_failure(self) -> None:
        self.assertEqual(COVERAGE.percentage(0, 0), 100.0)


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
"""Contract tests for accountable Semgrep suppressions."""

from __future__ import annotations

import importlib.util
import unittest
from pathlib import Path

MODULE_PATH = Path(__file__).with_name("check_semgrep_suppressions.py")
SPEC = importlib.util.spec_from_file_location("check_semgrep_suppressions", MODULE_PATH)
assert SPEC and SPEC.loader
POLICY = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(POLICY)


class SemgrepSuppressionPolicyTests(unittest.TestCase):
    def test_bare_suppression_is_not_accountable(self) -> None:
        line = "// nosemgrep: iicp.php.process-execution"
        self.assertIsNotNone(POLICY.SUPPRESSION.search(line))
        self.assertIsNone(POLICY.RATIONALE.search(line))
        self.assertIsNone(POLICY.ISSUE.search(line))

    def test_reason_and_issue_are_accountable(self) -> None:
        line = "// nosemgrep: rule reason=false-positive issue=#13"
        self.assertIsNotNone(POLICY.SUPPRESSION.search(line))
        self.assertIsNotNone(POLICY.RATIONALE.search(line))
        self.assertIsNotNone(POLICY.ISSUE.search(line))


if __name__ == "__main__":
    unittest.main()

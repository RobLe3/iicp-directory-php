#!/usr/bin/env python3

from __future__ import annotations

import importlib.util
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class OperatorCapacityContractTests(unittest.TestCase):
    def test_seed_is_deterministic_and_content_is_synthetic(self):
        command = [str(ROOT / "scripts/seed_operator_benchmark.py"), "--nodes", "2"]
        first = subprocess.check_output(command, text=True)
        second = subprocess.check_output(command, text=True)
        self.assertEqual(first, second)
        self.assertEqual(first.count("INSERT INTO nodes "), 2)
        self.assertIn(".invalid", first)
        self.assertNotIn("http://", first)

    def test_checked_in_report_is_content_free(self):
        report = json.loads((ROOT / "reports/operator-capacity-reference.json").read_text())
        self.assertEqual(report["schema"], "iicp.directory.operator-capacity.v1")
        self.assertTrue(report["content_free"])
        self.assertFalse(report["production_database_used"])
        self.assertFalse(report["production_endpoint_used"])
        self.assertTrue(report["not_an_sla"])
        rendered = json.dumps(report["workloads"])
        for forbidden in ("node_id", "endpoint", "payload", "credential", "response_body"):
            self.assertNotIn(forbidden, rendered)


if __name__ == "__main__":
    unittest.main()

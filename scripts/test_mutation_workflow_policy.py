#!/usr/bin/env python3
"""Structural contract for the resource-bounded mutation workflow."""

from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github" / "workflows" / "mutation-testing.yml"


class MutationWorkflowPolicyTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.source = WORKFLOW.read_text(encoding="utf-8")
        cls.jobs = cls.source.split("\njobs:\n", 1)[1]

    def test_stale_one_off_branch_condition_is_absent(self):
        self.assertNotIn("github.head_ref == 'quality/coverage-mutation-ratchets'", self.source)

    def test_every_mutation_job_uses_the_label_policy(self):
        names = [
            line.strip()[:-1]
            for line in self.jobs.splitlines()
            if line.startswith("  ") and not line.startswith("    ") and line.endswith(":")
        ]
        self.assertGreaterEqual(len(names), 8)
        condition = (
            "if: github.event_name != 'pull_request' || "
            "contains(github.event.pull_request.labels.*.name, 'mutation-required')"
        )
        for name in names:
            with self.subTest(job=name):
                self.assertIn(f"  {name}:\n    {condition}", self.jobs)

    def test_label_event_can_retrigger_a_qualifying_pull_request(self):
        self.assertIn("types: [opened, synchronize, reopened, labeled]", self.source)


if __name__ == "__main__":
    unittest.main()

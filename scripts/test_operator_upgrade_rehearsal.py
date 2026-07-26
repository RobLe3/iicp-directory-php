#!/usr/bin/env python3
"""Structural safety contract for the disposable previous/next rehearsal."""

from pathlib import Path
import unittest


class OperatorUpgradeRehearsalTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.script = Path(__file__).with_name("rehearse_operator_upgrade.sh").read_text()

    def test_requires_two_immutable_release_tags(self) -> None:
        self.assertIn('git -C "$ROOT" cat-file -e "$PREVIOUS_TAG^{commit}"', self.script)
        self.assertIn('git -C "$ROOT" cat-file -e "$NEXT_TAG^{commit}"', self.script)
        self.assertIn('"$PREVIOUS_TAG" != "$NEXT_TAG"', self.script)

    def test_upgrade_and_rollback_are_explicit(self) -> None:
        self.assertIn('compose "$NEXT_TAG" --profile tools run --rm migrate', self.script)
        self.assertIn('DROP DATABASE IF EXISTS', self.script)
        self.assertIn('<"$TMP/pre-upgrade.sql"', self.script)
        self.assertIn('compose "$PREVIOUS_TAG" up -d --no-deps --force-recreate', self.script)
        self.assertIn('"next_forward_recovery": True', self.script)

    def test_report_is_content_free_and_non_authorizing(self) -> None:
        self.assertIn('"content_free": True', self.script)
        self.assertIn('"production_database_used": False', self.script)
        self.assertIn('"deployment_authorized": False', self.script)


if __name__ == "__main__":
    unittest.main()

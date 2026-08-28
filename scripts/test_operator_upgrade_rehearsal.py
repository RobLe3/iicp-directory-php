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

    def test_backup_precedes_upgrade_and_restore_precedes_previous_runtime(self) -> None:
        backup = self.script.index('>"$TMP/pre-upgrade.sql"')
        first_next_migration = self.script.index(
            'compose "$NEXT_TAG" --profile tools run --rm migrate', backup
        )
        restore = self.script.index('<"$TMP/pre-upgrade.sql"', first_next_migration)
        previous_runtime = self.script.index(
            'compose "$PREVIOUS_TAG" up -d --no-deps --force-recreate app scheduler web',
            restore,
        )
        self.assertLess(backup, first_next_migration)
        self.assertLess(first_next_migration, restore)
        self.assertLess(restore, previous_runtime)

    def test_interrupted_upgrade_cleanup_removes_disposable_authority(self) -> None:
        cleanup = self.script.split("cleanup() {", 1)[1].split("}", 1)[0]
        self.assertIn("down --volumes --remove-orphans", cleanup)
        self.assertIn('git -C "$ROOT" worktree remove --force "$checkout"', cleanup)
        self.assertIn('trap cleanup EXIT', self.script)
        self.assertIn('set -euo pipefail', self.script)

    def test_report_is_content_free_and_non_authorizing(self) -> None:
        self.assertIn('"content_free": True', self.script)
        self.assertIn('"production_database_used": False', self.script)
        self.assertIn('"deployment_authorized": False', self.script)


if __name__ == "__main__":
    unittest.main()

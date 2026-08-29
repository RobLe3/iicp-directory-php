from __future__ import annotations

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from build_pre1_candidate_artifacts import (
    COMPOSER_VALIDATE_ARGUMENTS,
    LARAVEL_RUNTIME_DIRECTORIES,
    materialize_laravel_runtime_directories,
)


ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/build_pre1_candidate_artifacts.py"


class Pre1CandidateArtifactBuilderTest(unittest.TestCase):
    def test_description_is_content_free_and_complete(self) -> None:
        value = json.loads(
            subprocess.check_output([sys.executable, str(SCRIPT), "--describe"], text=True)
        )
        self.assertEqual(value["component"], "directory-php")
        self.assertEqual(len(value["artifact_identities"]), 2)
        self.assertTrue(value["requires_clean_source"])
        self.assertTrue(value["non_authorizing"])

    def test_composer_validation_preserves_the_intentional_exact_pin(self) -> None:
        self.assertIn("validate", COMPOSER_VALIDATE_ARGUMENTS)
        self.assertNotIn("--strict", COMPOSER_VALIDATE_ARGUMENTS)

    def test_clean_checkout_runtime_directories_are_materialized_idempotently(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            materialize_laravel_runtime_directories(root)
            materialize_laravel_runtime_directories(root)
            runtime_paths = tuple(root / relative for relative in LARAVEL_RUNTIME_DIRECTORIES)
            self.assertTrue(all(path.is_dir() for path in runtime_paths))
            self.assertFalse(any(path.is_symlink() for path in runtime_paths))

    def test_runtime_directory_materialization_rejects_symlinks(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            outside = root / "outside"
            outside.mkdir()
            (root / "bootstrap").symlink_to(outside, target_is_directory=True)
            with self.assertRaisesRegex(ValueError, "traverses a symlink"):
                materialize_laravel_runtime_directories(root)


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
"""Tests for the content-free secret hygiene gate."""

from __future__ import annotations

import importlib.util
import io
import tarfile
import tempfile
import unittest
from pathlib import Path


def load_module():
    path = Path(__file__).with_name("check_secret_hygiene.py")
    spec = importlib.util.spec_from_file_location("check_secret_hygiene", path)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


CHECK = load_module()
SCANNER_SHAPED = b"APP_KEY=" + b"base64:" + (b"A" * 43) + b"="


class SecretHygieneTests(unittest.TestCase):
    def test_rejects_scanner_shaped_laravel_key_without_echoing_it(self):
        result = CHECK.findings([(".env.testing", SCANNER_SHAPED)])
        self.assertEqual(
            result,
            [".env.testing:1: credential-shaped Laravel key"],
        )
        self.assertNotIn("base64:", " ".join(result))

    def test_allows_explicit_non_secret_test_sentinel(self):
        result = CHECK.findings([(".env.testing", b"APP_KEY=" + (b"0" * 32))])
        self.assertEqual(result, [])

    def test_scans_release_archive(self):
        with tempfile.TemporaryDirectory() as directory:
            archive = Path(directory) / "release.tar.gz"
            with tarfile.open(archive, "w:gz") as bundle:
                info = tarfile.TarInfo("release/.env.testing")
                info.size = len(SCANNER_SHAPED)
                bundle.addfile(info, io.BytesIO(SCANNER_SHAPED))
            self.assertEqual(
                CHECK.scan_archive(archive),
                ["release/.env.testing:1: credential-shaped Laravel key"],
            )


if __name__ == "__main__":
    unittest.main()

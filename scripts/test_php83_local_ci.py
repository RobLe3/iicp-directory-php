import hashlib
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import run_php83_local_ci as ci


class LocalCiTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name).resolve()

    def command(self, value):
        path = self.base / "command.json"
        raw = json.dumps(value).encode()
        path.write_bytes(raw)
        return path, hashlib.sha256(raw).hexdigest()

    def test_pinned_argv(self):
        path, digest = self.command(["bash", "-c", "exit 0"])
        self.assertEqual(["bash", "-c", "exit 0"], ci.read_command(path, digest))
        with self.assertRaises(ValueError): ci.read_command(path, "0" * 64)

    def test_invalid_argv(self):
        for value in ([], "bash", [1], [""], ["a\0b"]):
            with self.subTest(value=value):
                path, digest = self.command(value)
                with self.assertRaises(ValueError): ci.read_command(path, digest)

    def test_symlink_refused(self):
        path, digest = self.command(["true"])
        link = self.base / "link"
        link.symlink_to(path)
        with self.assertRaises(OSError): ci.read_command(link, digest)

    def test_bounded_capture_and_exit_propagation(self):
        import sys
        with patch.object(ci, "LOG_LIMIT", 17):
            code = ci.capture([sys.executable, "-c", "print('x'*10000); raise SystemExit(42)"], self.base/"out", 10)
        self.assertEqual(42, code)
        self.assertEqual(17, (self.base/"out").stat().st_size)

    def test_timeout(self):
        import sys
        self.assertEqual(124, ci.capture([sys.executable, "-c", "import time; time.sleep(10)"], self.base/"out", 0.1))

    def exercise(self, failed_phase=None):
        def output(args, **kwargs):
            if "status" in args: return ""
            if "ls" in args: return ""
            if "rev-parse" in args: return "a"*40
            return "sha256:"+"b"*64
        def capture(args, log, timeout):
            log.write_text("")
            if log.stem == "clone": (log.parent/"source").mkdir()
            return 42 if log.stem == failed_phase else 0
        with patch.object(ci.subprocess, "check_output", side_effect=output), patch.object(ci, "capture", side_effect=capture):
            code = ci.run(["false"], self.base/"attempt", "a"*64)
        return code, json.loads((self.base/"attempt/result.json").read_text())

    def test_success_returns_only_owned_workspace(self):
        other = self.base/"unrelated"; other.write_text("preserve")
        code, receipt = self.exercise()
        self.assertEqual(0, code)
        self.assertTrue(receipt["workspace_returned"])
        self.assertEqual("preserve", other.read_text())
        self.assertEqual(0, receipt["qualification_credit"])

    def test_failure_preserves_evidence_and_cleans_resources(self):
        code, receipt = self.exercise("checks")
        self.assertEqual(1, code)
        self.assertEqual("FAIL", receipt["status"])
        self.assertFalse(receipt["workspace_returned"])
        self.assertEqual(3, len(receipt["cleanup"]))

    def test_cleanup_failure_cannot_pass(self):
        code, receipt = self.exercise("cleanup-container")
        self.assertEqual(1, code)
        self.assertEqual("FAIL", receipt["status"])


if __name__ == "__main__": unittest.main()

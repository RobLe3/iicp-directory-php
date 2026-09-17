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

    def test_source_output_refused_before_allocation(self):
        with self.assertRaises(ValueError):
            ci.run(["true"], ci.ROOT / "forbidden-ci-output", "a" * 64)

    def test_fifo_refused_without_blocking(self):
        import os
        path = self.base / "fifo"
        os.mkfifo(path)
        with self.assertRaises(ValueError): ci.read_command(path, "a" * 64)

    def test_progress_has_no_command_or_environment_values(self):
        import sys
        from contextlib import redirect_stdout
        from io import StringIO
        console = StringIO()
        with redirect_stdout(console):
            code = ci.capture([sys.executable, "-c", "print('synthetic-private-value')"], self.base/"probe.log", 10)
        self.assertEqual(0, code)
        self.assertNotIn("synthetic-private-value", console.getvalue())
        events = [json.loads(x) for x in (self.base/"progress.jsonl").read_text().splitlines()]
        self.assertEqual(["started", "completed"], [x["state"] for x in events])
        self.assertEqual(0, events[-1]["exit_code"])

    def test_optional_progress_failure_does_not_fail_capture(self):
        import sys
        (self.base/"progress.jsonl").mkdir()
        self.assertEqual(0, ci.capture([sys.executable, "-c", "pass"], self.base/"out", 10))

    def test_invalid_limits_refused_before_allocation(self):
        for settings in ({"image_timeout": 0}, {"image_timeout": 7201},
                         {"progress_interval": 0}, {"progress_interval": 301}):
            with self.assertRaises(ValueError):
                ci.run(["true"], self.base/"attempt", "a"*64, **settings)
        self.assertFalse((self.base/"attempt").exists())

    def test_heartbeat_is_not_claimed_as_useful_progress(self):
        import sys
        code = ci.capture([sys.executable, "-c", "import time; time.sleep(.1)"],
                          self.base/"quiet", 10, progress_interval=.03)
        self.assertEqual(0, code)
        events = [json.loads(x) for x in (self.base/"progress.jsonl").read_text().splitlines()]
        self.assertTrue(any(x["state"] == "heartbeat" for x in events))
        self.assertTrue(all("last_progress_at" not in x for x in events))

    def test_interruption_kills_child_and_preserves_log(self):
        import sys
        with patch.object(ci, "wait_with_progress", side_effect=KeyboardInterrupt):
            with self.assertRaises(KeyboardInterrupt):
                ci.capture([sys.executable, "-c", "import time; time.sleep(10)"], self.base/"out", 10)
        self.assertTrue((self.base/"out").exists())


    def exercise(self, failed_phase=None, *, never_created_image=False):
        def output(args, **kwargs):
            if "status" in args: return ""
            if "ls" in args: return "still-present" if failed_phase and failed_phase.startswith("cleanup-") else ""
            if "rev-parse" in args: return "a"*40
            return "sha256:"+"b"*64
        def capture(args, log, timeout, **kwargs):
            log.write_text("")
            if log.stem == "clone": (log.parent/"source").mkdir()
            if never_created_image and log.stem == "cleanup-image": return 1
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
        self.assertEqual(1800, receipt["image_timeout_seconds"])
        self.assertEqual(30, receipt["progress_interval_seconds"])

    def test_failure_preserves_evidence_and_cleans_resources(self):
        code, receipt = self.exercise("checks")
        self.assertEqual(1, code)
        self.assertEqual("FAIL", receipt["status"])
        self.assertFalse(receipt["workspace_returned"])
        self.assertEqual(3, len(receipt["cleanup"]))

    def test_never_created_image_cleanup_is_idempotent_but_run_stays_failed(self):
        code, receipt = self.exercise("image", never_created_image=True)
        self.assertEqual(1, code)
        self.assertTrue(receipt["resources_absent"])
        self.assertEqual(0, receipt["cleanup"][-1]["exit_code"])
        self.assertEqual(1, receipt["cleanup"][-1]["raw_exit_code"])
        self.assertFalse(receipt["workspace_returned"])


    def test_cleanup_failure_cannot_pass(self):
        code, receipt = self.exercise("cleanup-container")
        self.assertEqual(1, code)
        self.assertEqual("FAIL", receipt["status"])


if __name__ == "__main__": unittest.main()

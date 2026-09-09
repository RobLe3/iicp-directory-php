#!/usr/bin/env python3
"""No Docker allocation: exercise evidence/cleanup with fake external commands."""
import contextlib
import io
import json
import os
from pathlib import Path
import tempfile
import subprocess
import stat
from types import SimpleNamespace
import unittest
from unittest.mock import patch
import operator_rehearsal_evidence as evidence


class EvidenceTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.base = Path(self.temp.name).resolve()
        self.project = "iicp-operator-rehearsal-test"
        with patch.object(evidence, "resources_absent", return_value=True):
            self.work = evidence.prepare(self.base, self.project, "stack")
        self.output = Path(str(self.work) + ".evidence")
        (self.work / "phase").write_text("database_recovery")
        self.summary = {"schema": "iicp.directory.operator-rehearsal.v1", "backup_sha256": "a"*64,
                       "checks": {key: True for key in ["clean_migration", "liveness", "readiness", "bad_candidate_rejected",
                       "database_failure_not_ready", "database_recovery", "backup_restore", "restored_migration_status"]}}
        (self.work / "result.json").write_text(json.dumps(self.summary))

    def tearDown(self):
        self.temp.cleanup()

    def finish(self, code, cleanup=True, absent=True, keep=False):
        def run(_, **kwargs):
            self.assertTrue((self.output / "workload.json").exists())
            return cleanup
        with patch.object(evidence, "command", side_effect=run), \
             patch.object(evidence, "resources_absent", return_value=absent), \
             contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
            return evidence.finish(self.work, self.project, "stack", self.base, code, keep)

    def test_success_retains_checks_but_returns_owned_workspace(self):
        self.assertEqual(0, self.finish(0))
        self.assertFalse(self.work.exists())
        self.assertTrue(self.base.exists())
        self.assertEqual(self.summary, json.loads((self.output / "checks.json").read_text()))
        self.assertEqual(0o600, (self.output / "closure.json").stat().st_mode & 0o777)

    def test_failure_captured_before_cleanup_and_original_exit_preserved(self):
        self.assertEqual(101, self.finish(101))
        record = json.loads((self.output / "closure.json").read_text())
        self.assertEqual("database_recovery", record["phase"])
        self.assertEqual("FAIL", record["status"])
        self.assertTrue(self.work.exists())

    def test_cleanup_error_and_leftovers_are_not_success(self):
        self.assertEqual(3, self.finish(0, absent=False))
        self.assertTrue(self.work.exists())
        self.assertEqual("FAIL", json.loads((self.output / "closure.json").read_text())["cleanup"])

    def test_cleanup_command_failure_is_not_hidden_by_empty_inventory(self):
        self.assertEqual(3, self.finish(0, cleanup=False))
        self.assertEqual("FAIL", json.loads((self.output / "closure.json").read_text())["status"])

    def test_interruption_exit_codes_are_preserved(self):
        self.assertEqual(143, self.finish(143))
        self.assertTrue(self.work.exists())

    def test_symlink_result_is_not_followed(self):
        (self.work / "result.json").unlink()
        (self.work / "result.json").symlink_to(self.base / "private-secret")
        self.assertEqual(3, self.finish(0))

    def test_export_refuses_existing_file_and_symlink(self):
        target = self.base / "existing"
        target.write_text("keep")
        with self.assertRaises(FileExistsError): evidence.write_json(target, {})
        link = self.base / "linked"
        link.symlink_to(target)
        with self.assertRaises(FileExistsError): evidence.write_json(link, {})
        self.assertEqual("keep", target.read_text())

    def test_cleanup_reconstructs_disposable_compose_environment(self):
        with patch.dict(os.environ, {}, clear=True), \
             patch.object(evidence, "command", return_value=True) as run, \
             patch.object(evidence, "resources_absent", return_value=True):
            self.assertTrue(evidence.cleanup_resources(self.work, self.project, "stack", self.base))
        env = run.call_args.kwargs["env"]
        self.assertEqual("http://127.0.0.1", env["IICP_APP_URL"])
        self.assertEqual("iicp_directory", env["IICP_DB_DATABASE"])
        self.assertEqual("iicp_operator", env["IICP_DB_USERNAME"])
        self.assertEqual(str(self.work / "db_root_password"), env["IICP_DB_ROOT_PASSWORD_FILE"])

    def test_command_timeout_fails_closed(self):
        with patch.object(evidence.subprocess, "run", side_effect=subprocess.TimeoutExpired("docker", 60)):
            self.assertFalse(evidence.command(["docker"]))
            self.assertFalse(evidence.resources_absent(self.project))

    def test_keep_cannot_count_as_clean(self):
        self.assertEqual(3, self.finish(0, keep=True))
        self.assertTrue(self.work.exists())

    def test_missing_result_cannot_pass(self):
        (self.work / "result.json").unlink()
        self.assertEqual(3, self.finish(0))

    def test_secret_fields_never_exported(self):
        self.summary["request"] = "secret-user-content"
        (self.work / "result.json").write_text(json.dumps(self.summary))
        (self.work / "phase").write_text("secret-endpoint-token")
        self.assertEqual(0, self.finish(0))
        for path in self.output.iterdir():
            self.assertNotIn("secret-", path.read_text())

    def test_root_owned_sticky_parent_is_safe_for_unprivileged_operator(self):
        info = SimpleNamespace(st_uid=0, st_mode=stat.S_IFDIR | stat.S_ISVTX | 0o777)
        with patch.object(Path, "stat", return_value=info), patch.object(Path, "is_symlink", return_value=False), \
             patch.object(os, "getuid", return_value=1000):
            self.assertEqual(self.base, evidence.safe_directory(self.base, allow_sticky=True))
            with self.assertRaises(ValueError): evidence.safe_directory(self.base)
        info.st_mode = stat.S_IFDIR | 0o777
        with patch.object(Path, "stat", return_value=info), patch.object(Path, "is_symlink", return_value=False), \
             patch.object(os, "getuid", return_value=1000):
            with self.assertRaises(ValueError): evidence.safe_directory(self.base, allow_sticky=True)

    def test_symlinks_and_foreign_owner_refused(self):
        link = self.base / "link"
        link.symlink_to(self.work, target_is_directory=True)
        with self.assertRaises(ValueError): evidence.safe_directory(link)
        (self.work / "owner.json").write_text('{}')
        with self.assertRaises(ValueError): self.finish(1)

    def test_retained_capacity_continuation_is_verified_and_closed(self):
        self.assertEqual(3, self.finish(0, keep=True))
        self.assertEqual(self.work, evidence.retained_work(self.base))
        (self.work / "phase").write_text("capacity")
        with patch.object(evidence, "command", return_value=True), \
             patch.object(evidence, "resources_absent", return_value=True), \
             contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
            self.assertEqual(0, evidence.finish(self.work, self.project, "stack", self.base, 0, continuation=True))
        self.assertEqual("RETAINED", json.loads((self.output / "closure.json").read_text())["cleanup"])
        final = json.loads((self.output / "capacity/closure.json").read_text())
        self.assertEqual("capacity", final["phase"])
        self.assertEqual("PASS", final["status"])
        self.assertFalse(self.work.exists())

    def test_failed_workload_cannot_transfer_to_capacity(self):
        self.assertEqual(42, self.finish(42, keep=True))
        with self.assertRaises(ValueError): evidence.retained_work(self.base)

    def test_capacity_caller_consumes_new_retained_contract(self):
        source = Path(__file__).with_name("run_operator_capacity_reference.sh").read_text()
        self.assertIn('retained --base "$TMP"', source)
        self.assertIn('finish --continuation', source)
        self.assertIn('export IICP_APP_KEY_FILE="$WORK/app_key"', source)
        self.assertIn('export IICP_DB_PASSWORD_FILE="$WORK/db_password"', source)
        self.assertNotIn('rm -rf', source)

    def test_existing_project_refused_without_creating_workspace(self):
        before = set(self.base.iterdir())
        with patch.object(evidence, "resources_absent", return_value=False):
            with self.assertRaises(ValueError): evidence.prepare(self.base, self.project, "stack")
        self.assertEqual(before, set(self.base.iterdir()))

    def test_failed_evidence_write_still_cleans_compute(self):
        with patch.object(evidence, "write_json", side_effect=OSError("unavailable")), \
             patch.object(evidence, "command", return_value=True) as cleanup, \
             patch.object(evidence, "resources_absent", return_value=True), \
             contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
            self.assertEqual(3, evidence.finish(self.work, self.project, "stack", self.base, 0))
        cleanup.assert_called_once()
        self.assertTrue(self.work.exists())

    def test_shell_failure_invokes_guarded_cleanup_without_real_docker(self):
        binary = self.base / "bin"
        binary.mkdir()
        fake = binary / "docker"
        fake.write_text('#!/bin/sh\ncase "$1" in container|volume|network) exit 0;; esac\ncase " $* " in *" down "*) exit 0;; esac\nexit 42\n')
        fake.chmod(0o700)
        env = {**os.environ, "PATH": str(binary) + os.pathsep + os.environ["PATH"],
               "IICP_OPERATOR_REHEARSAL_DIR": str(self.base),
               "IICP_OPERATOR_PROJECT": "iicp-operator-rehearsal-shell"}
        run = subprocess.run(["bash", str(Path(__file__).with_name("rehearse_operator_stack.sh"))],
                             env=env, capture_output=True, text=True, timeout=15)
        self.assertEqual(42, run.returncode, run.stderr)
        records = [json.loads(p.read_text()) for p in self.base.glob("*.evidence/closure.json")]
        self.assertEqual(1, len(records), run.stderr)
        self.assertEqual("build", records[0]["phase"])
        self.assertEqual("PASS", records[0]["cleanup"])
        self.assertEqual("FAIL", records[0]["status"])



if __name__ == "__main__": unittest.main()

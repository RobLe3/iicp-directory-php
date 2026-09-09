#!/usr/bin/env python3
"""Native preparation contracts; no Docker allocation or network access."""
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import build_operator_transfer as transfer


class TransferTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name).resolve()

    def inspect(self, *args):
        if args[:2] == ("docker", "info"):
            return "linux/x86_64"
        if "rev-parse" in args:
            return args[-1].removesuffix("^{commit}")
        return ""

    def test_preflight_native_clean_source_and_no_output_mutation(self):
        with patch.object(transfer, "inspect", side_effect=self.inspect):
            self.assertEqual(self.root / "new", transfer.preflight(self.root / "new"))
        self.assertEqual([], list(self.root.iterdir()))

    def test_non_native_dirty_and_missing_source_refused(self):
        for mode in ("host", "dirty", "source"):
            def inspect(*args):
                if mode == "host" and args[0] == "docker": return "linux/aarch64"
                if mode == "dirty" and "status" in args: return " M file"
                if mode == "source" and "rev-parse" in args: return "wrong"
                return self.inspect(*args)
            with self.subTest(mode=mode), patch.object(transfer, "inspect", side_effect=inspect):
                with self.assertRaises(ValueError): transfer.preflight(self.root / "new")

    def test_existing_symlink_and_checkout_outputs_refused(self):
        (self.root / "link").symlink_to(self.root, target_is_directory=True)
        for path in (self.root, self.root / "link" / "new", transfer.ROOT / "new",
                     transfer.ROOT / "scripts" / ".." / "new"):
            with self.subTest(path=path), self.assertRaises(ValueError): transfer.preflight(path)

    def builder(self):
        with patch.object(transfer, "inspect", return_value="a" * 40):
            return transfer.Builder(self.root)

    def test_phase_failure_is_recorded_and_propagated(self):
        b = self.builder()
        with patch.object(transfer, "capture", return_value=124), self.assertRaises(RuntimeError):
            b.phase("build", ["unused"])
        self.assertEqual(124, b.receipt["phases"][0]["exit_code"])

    def test_cleanup_failure_cannot_be_pass(self):
        for code, present in ((1, ""), (0, "owned-resource")):
            with self.subTest(code=code, present=present):
                output = self.root / (str(code) + str(bool(present))); output.mkdir()
                b = self.builder(); b.output = output; b.receipt["status"] = "PASS"
                with patch.object(transfer, "capture", return_value=code), patch.object(transfer, "inspect", return_value=present):
                    b.cleanup()
                self.assertEqual("FAIL", json.loads((output / "result.json").read_text())["status"])

    def test_success_returns_only_created_build_trees(self):
        b = self.builder(); b.receipt["status"] = "PASS"
        for role in transfer.SOURCES:
            (self.root / role).mkdir(); (self.root / (role + "-context")).mkdir()
        (self.root / "images.tar.gz").write_bytes(b"retained")
        with patch.object(transfer, "capture", return_value=0), patch.object(transfer, "inspect", return_value=""):
            b.cleanup()
        self.assertEqual(b"retained", (self.root / "images.tar.gz").read_bytes())
        self.assertTrue(b.receipt["resources_absent"])
        self.assertFalse((self.root / "previous").exists())

    def test_workflow_is_manual_main_bound_and_uploads_even_on_failure(self):
        text = (transfer.ROOT / ".github/workflows/operator-upgrade-rehearsal.yml").read_text()
        self.assertIn('test "$GITHUB_REF" = refs/heads/main || exit 2', text)
        self.assertIn("always() && inputs.prepare_native", text)
        self.assertIn("steps.transfer.outputs.output", text)
        self.assertNotIn("'${{ inputs.previous_tag }}'", text)

    def transfer_fixture(self):
        value = {"schema": "iicp.directory.operator-transfer.v1", "platform": "linux/amd64",
                 "driver_source": "a" * 40, "dependencies": [], "files": [],
                 "qualification_credit": 0, "non_authorizing": True}
        for name in ("inputs.json", "images.tar.gz", *[role + "-release/" + name
                for role, version in (("previous", "1.10.93"), ("next", "1.10.94"))
                for name in ("iicp-directory-php-v" + version + ".tar.gz", "RELEASE-MANIFEST.json", "SHA256SUMS")]):
            path = self.root / name; path.parent.mkdir(exist_ok=True); path.write_bytes(b"fixture")
            value["files"].append({"name": name, "size_bytes": 7, "sha256": hashlib.sha256(b"fixture").hexdigest()})
        return value

    def test_transfer_refuses_inventory_traversal_duplicates_and_bad_hash(self):
        value = self.transfer_fixture()
        for failure in ("traversal", "duplicate", "hash", "credit"):
            import copy
            bad = copy.deepcopy(value)
            if failure == "traversal": bad["files"][0]["name"] = "../outside"
            if failure == "duplicate": bad["files"].append(bad["files"][0])
            if failure == "hash": bad["files"][0]["sha256"] = "0" * 64
            if failure == "credit": bad["qualification_credit"] = True
            path = self.root / "transfer.json"; path.write_text(json.dumps(bad))
            with self.subTest(failure=failure), self.assertRaises(ValueError):
                transfer.verify_transfer(self.root, transfer.digest(path))

    def test_transfer_digest_and_symlink_refused(self):
        value = self.transfer_fixture(); path = self.root / "transfer.json"
        path.write_text(json.dumps(value))
        with self.assertRaises(ValueError): transfer.verify_transfer(self.root, "0" * 64)
        image = self.root / "images.tar.gz"; image.unlink(); image.symlink_to(self.root / "inputs.json")
        with self.assertRaises(ValueError): transfer.verify_transfer(self.root, transfer.digest(path))

    def test_source_and_archive_pins_match_existing_native_evidence(self):
        report = json.loads((transfer.ROOT / "reports/operator-native-arm64-2026-09-09.json").read_text())
        for role in transfer.SOURCES:
            self.assertEqual(transfer.SOURCES[role], report["inputs"][role]["source_commit"])
            self.assertEqual(transfer.ARCHIVES[role], report["inputs"][role]["archive_sha256"])


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
"""Admission and shell-path regression tests; no Docker resources allocated."""
import copy
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import operator_prebuilt_inputs as inputs


class PrebuiltTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name).resolve()
        self.compose = inputs.ROOT / "compose.operator.yml"
        self.value = {"schema": inputs.SCHEMA, "platform": "linux/arm64",
                      "compose_sha256": hashlib.sha256(self.compose.read_bytes()).hexdigest()}
        for name, char, version in (("previous", "a", "1.10.93"), ("next", "b", "1.10.94")):
            self.value[name] = {"source_commit": char*40, "version": version, "archive_sha256": char*64,
                                "app_image": "sha256:"+char*64, "web_image": "sha256:"+char*63+"1"}
        self.path = self.base / "inputs.json"
        self.path.write_text(json.dumps(self.value))
        self.digest = hashlib.sha256(self.path.read_bytes()).hexdigest()

    def validate(self, value=None):
        inputs.validate(self.value if value is None else value, "a"*40, "b"*40, self.compose)

    def test_digest_and_exact_sources(self):
        self.assertEqual(self.value, inputs.read_manifest(self.path, self.digest))
        self.validate()
        with self.assertRaises(ValueError): inputs.read_manifest(self.path, "f"*64)
        with self.assertRaises(ValueError): inputs.validate(self.value, "c"*40, "b"*40, self.compose)

    def test_rejects_field_widening_mutable_images_and_contract_drift(self):
        for key, bad in (("schema", "other"), ("platform", "linux/386"), ("compose_sha256", "f"*64), ("secret", "must-not-escape")):
            value = copy.deepcopy(self.value); value[key] = bad
            with self.subTest(key=key), self.assertRaises(ValueError): self.validate(value)
        for key in ("app_image", "web_image", "version", "archive_sha256", "source_commit"):
            value = copy.deepcopy(self.value); value["next"][key] = "../../unsafe"
            with self.subTest(key=key), self.assertRaises(ValueError): self.validate(value)

    def test_rejects_same_version_and_invalid_structures(self):
        for bad in (None, [], "string", {**self.value["next"], "extra": True}):
            value = copy.deepcopy(self.value); value["next"] = bad
            with self.subTest(bad=bad), self.assertRaises(ValueError): self.validate(value)
        self.value["next"]["version"] = self.value["previous"]["version"]
        with self.assertRaises(ValueError): self.validate()

    def test_rejects_symlink_fifo_oversize_without_blocking(self):
        link = self.base / "link"; link.symlink_to(self.path)
        with self.assertRaises(ValueError): inputs.read_manifest(link, self.digest)
        fifo = self.base / "fifo"; os.mkfifo(fifo)
        with self.assertRaises(ValueError): inputs.read_manifest(fifo, self.digest)
        big = self.base / "big"; big.write_bytes(b" "*65537)
        with self.assertRaises(ValueError): inputs.read_manifest(big, self.digest)

    def image_output(self, args):
        if args[0] == "info": return "linux/aarch64"
        fmt, image = args[-2:]
        if fmt == "{{.Os}}/{{.Architecture}}": return "linux/arm64"
        if fmt == "{{.Id}}": return image
        component = next(c for c in (self.value["previous"], self.value["next"]) if image in (c["app_image"], c["web_image"]))
        for label, key in (("revision", "source_commit"), ("version", "version"), ("archive-sha256", "archive_sha256")):
            if label in fmt: return component[key]
        raise AssertionError(args)

    def test_loaded_images_platform_labels_and_dependencies_verified(self):
        with patch.object(inputs, "docker_output", side_effect=self.image_output) as run:
            inputs.verify(self.value, self.compose)
        self.assertEqual(2, len([c for c in run.call_args_list if "@sha256:" in c.args[0][-1]]))

    def test_wrong_host_and_wrong_provenance_rejected(self):
        for bad in ("linux/amd64", "wrong-revision"):
            def output(args):
                if bad == "linux/amd64" and args[0] == "info": return bad
                if bad == "wrong-revision" and "revision" in args[-2]: return bad
                return self.image_output(args)
            with self.subTest(bad=bad), patch.object(inputs, "docker_output", side_effect=output), self.assertRaises(ValueError):
                inputs.verify(self.value, self.compose)

    def test_overrides_forbid_builds_pulls_and_overwrite(self):
        inputs.write_overrides(self.base, self.value)
        path = self.base / "v1.10.93.yml"
        text = path.read_text()
        self.assertEqual(4, text.count("build: !reset null"))
        self.assertEqual(6, text.count("pull_policy: never"))
        self.assertEqual(0o600, path.stat().st_mode & 0o777)
        with self.assertRaises(FileExistsError): inputs.write_overrides(self.base, self.value)

    def test_docker_timeout_and_missing_dependency_fail_closed(self):
        with patch.object(inputs.subprocess, "run", side_effect=subprocess.TimeoutExpired("docker", 30)):
            with self.assertRaises(subprocess.TimeoutExpired): inputs.docker_output(["info"])
        def output(args):
            if "@sha256:" in args[-1]: raise ValueError("missing dependency")
            return self.image_output(args)
        with patch.object(inputs, "docker_output", side_effect=output), self.assertRaises(ValueError):
            inputs.verify(self.value, self.compose)

    def test_bad_pin_shell_exits_before_docker_or_workspace(self):
        before = set(self.base.iterdir())
        args = ["bash", str(inputs.ROOT/"scripts/rehearse_operator_upgrade.sh"),
                "--prebuilt-manifest", str(self.path), "--manifest-sha256", "f"*64,
                "--previous-source", "a"*40, "--next-source", "b"*40]
        result = subprocess.run(args, capture_output=True, text=True, timeout=10,
                                env={**os.environ, "IICP_OPERATOR_UPGRADE_DIR": str(self.base)})
        self.assertEqual(3, result.returncode)
        self.assertEqual(before, set(self.base.iterdir()))
        self.assertNotIn(str(self.path), result.stderr)

    def fake_shell(self, fail=""):
        binary = self.base / "bin"; binary.mkdir()
        docker = binary / "docker"
        docker.write_text('''#!/usr/bin/env python3
import json,os,sys
from pathlib import Path
args=sys.argv[1:]
with open(os.environ["FAKE_LOG"], "a") as out: out.write(json.dumps(args)+"\\n")
value=json.loads(Path(os.environ["FAKE_MANIFEST"]).read_text())
if args[0]=="info": print("linux/aarch64")
elif args[:2]==["image","inspect"]:
    fmt,image=args[-2:]
    if fmt=="{{.Os}}/{{.Architecture}}": print("linux/arm64")
    elif fmt=="{{.Id}}": print(image)
    else:
        c=next(c for c in (value["previous"],value["next"]) if image in (c["app_image"],c["web_image"]))
        key="source_commit" if "revision" in fmt else "version" if "version" in fmt else "archive_sha256"
        print(c[key])
elif args[0] in ("container","volume","network"): pass
elif args[0]=="compose":
    tag=os.environ.get("IICP_IMAGE_TAG","")
    if "down" in args: sys.exit(55 if os.environ.get("FAKE_FAIL")=="cleanup" else 0)
    if "cat" in args and "/app/VERSION" in args: print(tag[1:])
    if "mariadb-dump" in " ".join(args): print("synthetic-backup")
    if "run" in args and tag=="v1.10.94" and os.environ.get("FAKE_FAIL")=="migration": sys.exit(42)
else: sys.exit(99)
''')
        docker.chmod(0o700)
        curl = binary / "curl"; curl.write_text('#!/bin/sh\necho \'{"ok":true,"role":"directory","ready":true}\'\n'); curl.chmod(0o700)
        env = {**os.environ, "PATH": str(binary)+os.pathsep+os.environ["PATH"],
               "FAKE_LOG": str(self.base/"commands.jsonl"), "FAKE_MANIFEST": str(self.path), "FAKE_FAIL": fail,
               "IICP_OPERATOR_UPGRADE_DIR": str(self.base), "IICP_OPERATOR_UPGRADE_PROJECT": "iicp-operator-upgrade-test"}
        args = ["bash", str(inputs.ROOT/"scripts/rehearse_operator_upgrade.sh"), "--prebuilt-manifest", str(self.path),
                "--manifest-sha256", self.digest, "--previous-source", "a"*40, "--next-source", "b"*40]
        result = subprocess.run(args, env=env, capture_output=True, text=True, timeout=30)
        evidence = next(self.base.glob("*.evidence"))
        calls = [json.loads(line) for line in (self.base/"commands.jsonl").read_text().splitlines()]
        self.assertFalse(any(call[0] in ("build", "pull", "run") for call in calls))
        self.assertEqual(self.digest, json.loads((evidence/"prebuilt-inputs.json").read_text())["manifest_sha256"])
        return result, json.loads((evidence/"closure.json").read_text()), calls

    def test_full_shell_prebuilt_upgrade_and_rollback_without_building(self):
        result, closure, calls = self.fake_shell()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("PASS", closure["status"])
        self.assertTrue(closure["workspace_returned"])
        active = [c for c in calls if c[0]=="compose" and "down" not in c]
        self.assertTrue(all(c.count("-f")==2 for c in active))
        self.assertEqual(0, closure["qualification_credit"])

    def test_migration_failure_preserved_before_checked_cleanup(self):
        result, closure, _ = self.fake_shell("migration")
        self.assertEqual(42, result.returncode, result.stderr)
        self.assertEqual("upgrade", closure["phase"])
        self.assertEqual("PASS", closure["cleanup"])
        self.assertEqual("FAIL", closure["status"])

    def test_cleanup_failure_cannot_be_success(self):
        result, closure, _ = self.fake_shell("cleanup")
        self.assertEqual(3, result.returncode, result.stderr)
        self.assertEqual("FAIL", closure["cleanup"])


if __name__ == "__main__": unittest.main()

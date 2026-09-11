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
        self.assertIn("networks:\n  default:\n    internal: true", text)
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

    def fake_shell(self, fail="", interrupt="", probe=False):
        if probe:
            self.value["platform"] = "linux/amd64"
            self.path.write_text(json.dumps(self.value))
            self.digest = hashlib.sha256(self.path.read_bytes()).hexdigest()
        binary = self.base / "bin"; binary.mkdir()
        docker = binary / "docker"
        docker.write_text('''#!/usr/bin/env python3
import json,os,sys
from pathlib import Path
args=sys.argv[1:]
with open(os.environ["FAKE_LOG"], "a") as out: out.write(json.dumps(args)+"\\n")
value=json.loads(Path(os.environ["FAKE_MANIFEST"]).read_text())
if args[0]=="info": print(value["platform"])
elif args[0]=="inspect":
    if args[-2]=="{{.Image}}": print("sha256:"+"c"*64)
    else:
        base=Path(os.environ["FAKE_MANIFEST"]).parent
        print(json.dumps({"id":args[-1],"image":"sha256:"+"c"*64,
            "owner":(base/"owner-label").read_text(),"running":True,
            "paused":(base/"paused").exists() if args[-1]=="e"*64 else False,
            "network":"container:"+"e"*64}))
elif args[0] in ("pause","unpause"):
    path=Path(os.environ["FAKE_MANIFEST"]).parent/"paused"
    if args[0]=="pause": path.touch()
    else: path.unlink(missing_ok=True)
elif args[0]=="exec":
    if args[4]=="read":
        seq=int(args[5])
        print(json.dumps({"schema":"iicp.directory-outage-control.v1","nonce":"f"*32,
            "sequence":seq,"action":{1:"pause",2:"resume"}[seq]}))
elif args[0]=="wait": print("1" if os.environ.get("FAKE_FAIL")=="probe" else "0")
elif args[0]=="logs": print(json.dumps({"schema":"iicp.directory-sdk-probe.v1", "status":"PASS", "non_authorizing":True, "qualification_credit":0, "matrix":{"rows":[{}]*18},"outage_nonce":"f"*32}))
elif args[:2]==["image","inspect"]:
    fmt,image=args[-2:]
    if fmt=="{{.Id}} {{.Os}} {{.Architecture}}": print(image+" linux amd64")
    elif fmt=="{{.Os}}/{{.Architecture}}": print(value["platform"])
    elif fmt=="{{.Id}}": print(image)
    else:
        c=next(c for c in (value["previous"],value["next"]) if image in (c["app_image"],c["web_image"]))
        key="source_commit" if "revision" in fmt else "version" if "version" in fmt else "archive_sha256"
        print(c[key])
elif args[0] in ("container","volume","network"): pass
elif args[0]=="compose":
    if "-p" in args: (Path(os.environ["FAKE_MANIFEST"]).parent/"owner-label").write_text(args[args.index("-p")+1])
    tag=os.environ.get("IICP_IMAGE_TAG","")
    if "ps" in args and "sdk-probe" in args: print("d"*64)
    if "ps" in args and "app" in args: print("e"*64)
    if "down" in args: sys.exit(55 if os.environ.get("FAKE_FAIL")=="cleanup" else 0)
    if "--status" in args and os.environ.get("FAKE_FAIL")=="still-running": print("synthetic-container")
    if "wget" in args: print(json.dumps({"ok":True,"role":"directory","ready":True}))
    if "cat" in args and "/app/VERSION" in args: print("0.0.0" if os.environ.get("FAKE_FAIL")=="version" else tag[1:])
    if "mariadb-dump" in " ".join(args): print("synthetic-backup")
    if "--batch --skip-column-names" in " ".join(args):
        sql=sys.stdin.read()
        if sql.startswith("SELECT SHA2"):
            import hashlib
            text="1:alpha|2:beta" if os.environ.get("FAKE_FAIL")!="persistence" else "corrupt"
            print(hashlib.sha256(text.encode()).hexdigest())
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
        if probe: args.extend(["--sdk-probe-image", "sha256:"+"c"*64])
        if interrupt: args.extend(["--interrupt-at", interrupt])
        result = subprocess.run(args, env=env, capture_output=True, text=True, timeout=120)
        evidence = next(self.base.glob("*.evidence"))
        calls = [json.loads(line) for line in (self.base/"commands.jsonl").read_text().splitlines()]
        self.assertFalse(any(call[0] in ("build", "pull", "run") for call in calls))
        self.assertEqual(self.digest, json.loads((evidence/"prebuilt-inputs.json").read_text())["manifest_sha256"])
        return result, json.loads((evidence/"closure.json").read_text()), calls

    def test_full_shell_sdk_hook_before_verified_cleanup(self):
        result, closure, calls = self.fake_shell(probe=True)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("PASS", closure["cleanup"])
        wait = next(i for i,c in enumerate(calls) if c[0] == "wait")
        down = next(i for i,c in enumerate(calls) if "down" in c)
        self.assertLess(wait, down)
        self.assertEqual(3, sum("rm" in c and "scheduler" in c for c in calls))
        self.assertTrue(any("compose.operator-sdk-test.yml" in " ".join(c) for c in calls))

    def test_full_shell_sdk_failure_preserved_and_cleaned(self):
        result, closure, calls = self.fake_shell(fail="probe", probe=True)
        self.assertNotEqual(0, result.returncode)
        self.assertEqual("sdk_compatibility", closure["phase"])
        self.assertEqual("PASS", closure["cleanup"])
        self.assertTrue(any("down" in c for c in calls))
        evidence = next(self.base.glob("*.evidence"))
        self.assertEqual("FAIL", json.loads((evidence/"sdk-capture.json").read_text())["status"])

    def test_full_shell_prebuilt_upgrade_and_rollback_without_building(self):
        result, closure, calls = self.fake_shell()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("PASS", closure["status"])
        self.assertTrue(closure["workspace_returned"])
        active = [c for c in calls if c[0]=="compose" and "down" not in c]
        self.assertTrue(all(c.count("-f")==2 for c in active))
        self.assertEqual(0, closure["qualification_credit"])
        evidence = next(self.base.glob("*.evidence"))
        for stage in ("previous", "upgrade", "rollback", "forward"):
            value = json.loads((evidence / ("fixture-"+stage+".json")).read_text())
            self.assertTrue(value["verified"])
            self.assertEqual(hashlib.sha256(b"1:alpha|2:beta").hexdigest(), value["sha256"])

    def test_declared_application_interruption_checkpoints(self):
        for checkpoint in ("before-migration", "after-migration", "after-activation"):
            with self.subTest(checkpoint=checkpoint):
                # Each attempt owns a separate temporary environment.
                case = PrebuiltTests(); case.setUp()
                try:
                    result, closure, calls = case.fake_shell(interrupt=checkpoint)
                    self.assertEqual(0, result.returncode, result.stderr)
                    self.assertEqual("PASS", closure["status"])
                    evidence = next(case.base.glob("*.evidence"))
                    value = json.loads((evidence/"interruption.json").read_text())
                    self.assertEqual(checkpoint, value["checkpoint"])
                    self.assertTrue(value["services_stopped"])
                    self.assertTrue(any("--status" in c and "running" in c for c in calls))
                finally: case.doCleanups()

    def test_failed_interruption_observation_cannot_pass(self):
        result, closure, _ = self.fake_shell("still-running", "before-migration")
        self.assertNotEqual(0, result.returncode)
        self.assertEqual("upgrade", closure["phase"])
        self.assertEqual("FAIL", closure["status"])
        evidence = next(self.base.glob("*.evidence"))
        self.assertFalse((evidence/"interruption.json").exists())

    def test_wrong_runtime_version_cannot_pass(self):
        result, closure, _ = self.fake_shell("version")
        self.assertNotEqual(0, result.returncode)
        self.assertEqual("previous_runtime", closure["phase"])
        self.assertEqual("FAIL", closure["status"])

    def test_persistent_state_mismatch_fails_before_upgrade(self):
        result, closure, _ = self.fake_shell("persistence")
        self.assertNotEqual(0, result.returncode)
        self.assertEqual("previous_runtime", closure["phase"])
        self.assertEqual("PASS", closure["cleanup"])
        self.assertEqual("FAIL", closure["status"])

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

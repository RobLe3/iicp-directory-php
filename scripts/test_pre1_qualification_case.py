#!/usr/bin/env python3
from __future__ import annotations

import copy
import importlib.util
import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "pre1_driver", ROOT / "scripts/run_pre1_qualification_case.py"
)
module = importlib.util.module_from_spec(spec)
assert spec.loader
spec.loader.exec_module(module)


class DriverContractTests(unittest.TestCase):
    def test_description_is_complete_and_content_free(self) -> None:
        value = module.description()
        self.assertEqual(
            value["schema"], "iicp.pre1-component-driver-description.v1"
        )
        self.assertEqual(value["component"], module.COMPONENT)
        self.assertEqual(value["scenarios"], sorted(module.SCENARIO_COMMANDS))
        self.assertTrue(value["commands_sha256"].startswith("sha256:"))
        self.assertTrue(value["semantic_binding"]["exact_assertion_per_scenario"])
        self.assertEqual(
            set(value["semantic_binding"]["cell_dimensions_consumed"]),
            {
                "runtime",
                "target",
                "directory_flavor",
                "mode",
                "cell_id",
                "scenario_id",
            },
        )
        self.assertTrue(value["non_authorizing"])

    def test_cell_parser_rejects_wrong_component_and_boundary(self) -> None:
        good = "|".join(
            (
                module.COMPONENT,
                module.RUNTIMES[0],
                module.TARGETS[0],
                module.DIRECTORIES[0],
                module.MODES[0],
            )
        )
        self.assertEqual(module.parse_cell(good)[0], module.COMPONENT)
        with self.assertRaises(ValueError):
            module.parse_cell("wrong|" + "|".join(good.split("|")[1:]))
        with self.assertRaises(ValueError):
            module.parse_cell(good.replace(module.RUNTIMES[0], "unsupported"))

    def test_referenced_test_files_exist(self) -> None:
        commands = [module.SUPPORT_COMMAND, *module.SCENARIO_COMMANDS.values()]
        for command in commands:
            if command == ["@installed"]:
                self.assertEqual(module.SCENARIO_COMMANDS["no-dual-authority"], command)
                self.assertTrue((ROOT / "scripts/pre1_comparative_topology.py").is_file())
                continue
            if command[0] == "@php":
                assertion = command[-1].removeprefix("/::").removesuffix("$/")
                source = ROOT / command[2]
                marker = f"function {assertion}("
            else:
                dotted = command[3]
                assertion = dotted.rsplit(".", 1)[-1]
                source = ROOT / ("/".join(dotted.split(".")[:-2]) + ".py")
                marker = f"def {assertion}("
            self.assertTrue(source.is_file(), source)
            self.assertIn(marker, source.read_text())

    def test_every_scenario_has_one_unique_exact_assertion(self) -> None:
        self.assertEqual(set(module.SCENARIO_CASES), set(module.SCENARIO_COMMANDS))
        assertions = [row["assertion"] for row in module.SCENARIO_CASES.values()]
        self.assertEqual(len(assertions), len(set(assertions)))
        self.assertNotIn(module.SUPPORT_CASE["assertion"], assertions)

    def test_semantic_context_negative_controls_change_the_binding(self) -> None:
        base = (
            "directory-php|php-8.3|linux-aarch64|php|restricted",
            "rate-limit",
            "sha256:" + "a" * 64,
            "sha256:" + "b" * 64,
            "sha256:" + "c" * 64,
            "sha256:" + "d" * 64,
        )
        expected = module.canonical_sha256(module.semantic_execution_context(*base))
        mutations = [
            (base[0].replace("php-8.3", "php-8.4"), *base[1:]),
            (base[0].replace("linux-aarch64", "linux-x86_64"), *base[1:]),
            (base[0].replace("|php|", "|rust|"), *base[1:]),
            (base[0].replace("restricted", "public"), *base[1:]),
            (base[0], "disk-full", *base[2:]),
            (*base[:2], "sha256:" + "e" * 64, *base[3:]),
            (*base[:3], "sha256:" + "e" * 64, *base[4:]),
            (*base[:4], "sha256:" + "e" * 64, base[5]),
            (*base[:5], "sha256:" + "e" * 64),
        ]
        for mutation in mutations:
            with self.subTest(mutation=mutation[:2]):
                try:
                    observed = module.canonical_sha256(
                        module.semantic_execution_context(*mutation)
                    )
                except ValueError:
                    continue
                self.assertNotEqual(observed, expected)

    def test_environment_manifest_requires_offline_package_smoke_and_digest(self) -> None:
        candidate = "sha256:" + "a" * 64
        artifacts = "sha256:" + "b" * 64
        runtime_map = "sha256:" + "c" * 64
        value = {
            "schema": "iicp.pre1-qualification-environment.v1",
            "status": "READY",
            "target": "linux-aarch64",
            "bindings": {
                "candidate_manifest_sha256": candidate,
                "artifact_materialization_sha256": artifacts,
                "runtime_map_sha256": runtime_map,
                "runner_inventory_sha256": "sha256:" + "d" * 64,
            },
            "network": {},
            "source_state": {},
            "runtimes": {
                "php-8.3": {
                    "lock_inputs_sha256": "sha256:" + "e" * 64,
                    "dependency_cache_sha256": "sha256:" + "f" * 64,
                    "online_prepare_status": "PASS",
                    "offline_install_status": "PASS",
                    "package_artifact_smoke_status": "PASS",
                    "egress_disabled_during_offline": True,
                    "empty_volatile_cache_at_start": True,
                }
            },
            "content_free": True,
            "secrets_present": False,
            "non_authorizing": True,
            "environment_sha256": None,
        }
        value["environment_sha256"] = module.canonical_sha256(value)
        self.assertEqual(
            module._validate_environment_manifest(
                value,
                target="linux-aarch64",
                runtime="php-8.3",
                candidate_digest=candidate,
                materialization_digest=artifacts,
                runtime_map_digest=runtime_map,
            ),
            value["environment_sha256"],
        )
        for mutation in (
            ("offline_install_status", "FAIL"),
            ("package_artifact_smoke_status", "FAIL"),
            ("egress_disabled_during_offline", False),
        ):
            changed = copy.deepcopy(value)
            changed["runtimes"]["php-8.3"][mutation[0]] = mutation[1]
            changed["environment_sha256"] = None
            changed["environment_sha256"] = module.canonical_sha256(changed)
            with self.assertRaises(ValueError):
                module._validate_environment_manifest(
                    changed,
                    target="linux-aarch64",
                    runtime="php-8.3",
                    candidate_digest=candidate,
                    materialization_digest=artifacts,
                    runtime_map_digest=runtime_map,
                )

    def test_php_runtime_is_exactly_bound_to_cell(self) -> None:
        self.assertEqual(module.expected_runtime_version("php-8.3", {}), "8.3")

    def test_case_command_rejects_malformed_and_broad_commands(self) -> None:
        for command in (
            None, [], ["@php"], ["@python", "-m", "unittest", "suite"],
            ["@php", "vendor/bin/phpunit", None, "--filter", "/::test_one$/"],
            ["@php", "vendor/bin/phpunit", "tests/One.php", "--filter", "test_one"],
            ["@php", "vendor/bin/phpunit", "outside.php", "--filter", "/::test_one$/"],
            ["@php", "vendor/bin/phpunit", "tests/One.php", "--filter", "/::test_other$/"],
        ):
            with self.subTest(command=command), self.assertRaises(RuntimeError):
                module._case_command({"assertion": "test_one", "command": command}, "negative")


class ContextBoundaryTests(unittest.TestCase):
    """Exercise the complete context chain, not only individual predicates."""

    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory(prefix="iicp-php-context-")
        self.addCleanup(self.temp.cleanup)
        root = Path(self.temp.name).resolve()
        self.cell = "directory-php|php-8.3|linux-x86_64|php|restricted"
        artifacts = root / "artifacts"
        component = artifacts / module.COMPONENT
        component.mkdir(parents=True)
        for name in ("package-manifest.json", "build-receipt.json"):
            (component / name).write_text("{}")
        self.manifest = {
            "status": "FROZEN", "immutable": True,
            "manifest_sha256": "sha256:" + "a" * 64,
            "components": [{
                "id": module.COMPONENT, "state": "BUILT", "source_commit": "b" * 40,
                "artifacts": [],
                "package_manifest_sha256": module.file_sha256(component / "package-manifest.json"),
                "build_receipt_sha256": module.file_sha256(component / "build-receipt.json"),
            }],
        }
        self.runtime = {
            "schema": "iicp.pre1-runtime-map.v1", "target": "linux-x86_64",
            "runtimes": {"php-8.3": {}}, "map_sha256": "sha256:" + "c" * 64,
        }
        digest = module.artifact_materialization_sha256(self.manifest, artifacts)
        bindings = {
            "candidate_manifest_sha256": self.manifest["manifest_sha256"],
            "artifact_materialization_sha256": digest,
            "runtime_map_sha256": self.runtime["map_sha256"],
        }
        self.environment = {
            "schema": "iicp.pre1-qualification-environment.v1", "status": "READY",
            "target": "linux-x86_64", "content_free": True,
            "secrets_present": False, "non_authorizing": True, "bindings": bindings,
            "runtimes": {"php-8.3": {
                "online_prepare_status": "PASS", "offline_install_status": "PASS",
                "package_artifact_smoke_status": "PASS",
                "egress_disabled_during_offline": True, "empty_volatile_cache_at_start": True,
            }},
            "environment_sha256": None,
        }
        self.environment["environment_sha256"] = module.canonical_sha256(self.environment)
        self.context = module.semantic_execution_context(
            self.cell, None, bindings["candidate_manifest_sha256"], digest,
            bindings["runtime_map_sha256"], self.environment["environment_sha256"],
        )
        env = {
            "HOME": str(root), "IICP_HOME": str(root / "iicp"),
            "IICP_PRE1_CELL_ID": self.cell, "IICP_PRE1_SCENARIO_ID": "support",
            "IICP_PRE1_NETWORK_POLICY": "isolated-fixtures-only",
            "IICP_PRE1_EVIDENCE_POLICY": "digest-only",
            "IICP_PRE1_CANDIDATE_DIGEST": bindings["candidate_manifest_sha256"],
            "IICP_PRE1_ARTIFACT_ROOT": str(artifacts),
            "IICP_PRE1_ARTIFACT_MATERIALIZATION_SHA256": digest,
            "IICP_PRE1_RUNTIME_MAP_SHA256": bindings["runtime_map_sha256"],
            "IICP_PRE1_QUALIFICATION_ENVIRONMENT_SHA256": self.environment["environment_sha256"],
            "IICP_PRE1_RUNTIME": "php-8.3", "IICP_PRE1_TARGET": "linux-x86_64",
            "IICP_PRE1_DIRECTORY_FLAVOR": "php", "IICP_PRE1_MODE": "restricted",
            "IICP_PRE1_CONTEXT_SHA256": module.canonical_sha256(self.context),
        }
        self.documents = {}
        for name, value in (
            ("IICP_PRE1_CANDIDATE_MANIFEST", self.manifest),
            ("IICP_PRE1_RUNTIME_MAP", self.runtime),
            ("IICP_PRE1_ENVIRONMENT_MANIFEST", self.environment),
        ):
            path = root / (name + ".json")
            path.write_text(json.dumps(value))
            env[name] = str(path)
            self.documents[name] = path
        for mock in (
            patch.dict(os.environ, env, clear=True),
            patch.object(module, "detected_target", return_value="linux-x86_64"),
            patch.object(module.subprocess, "check_output", return_value="b" * 40 + "\n"),
        ):
            mock.start()
            self.addCleanup(mock.stop)

    def test_complete_context_preserves_return_and_digest(self) -> None:
        self.assertEqual(module.validate_context(self.cell, None),
                         ("php-8.3", {}, self.manifest, self.context))

    def test_every_environment_binding_is_enforced(self) -> None:
        names = [name for name in os.environ if name.startswith("IICP_PRE1_")]
        names += ["HOME", "IICP_HOME"]
        for name in names:
            with self.subTest(name=name), patch.dict(os.environ, {name: "/not-present"}):
                with self.assertRaises((ValueError, OSError)):
                    module.validate_context(self.cell, None)

    def test_actual_host_and_source_are_enforced(self) -> None:
        with patch.object(module, "detected_target", return_value="linux-aarch64"):
            with self.assertRaisesRegex(ValueError, "actual host"):
                module.validate_context(self.cell, None)
        with patch.object(module.subprocess, "check_output", return_value="d" * 40):
            with self.assertRaisesRegex(ValueError, "source commit"):
                module.validate_context(self.cell, None)

    def test_unfrozen_unbuilt_and_missing_runtime_are_rejected(self) -> None:
        mutations = [
            ("IICP_PRE1_CANDIDATE_MANIFEST", {**self.manifest, "immutable": False}),
            ("IICP_PRE1_CANDIDATE_MANIFEST", {**self.manifest, "status": "DRAFT"}),
            ("IICP_PRE1_CANDIDATE_MANIFEST", {**self.manifest, "components": []}),
            ("IICP_PRE1_RUNTIME_MAP", {**self.runtime, "runtimes": {}}),
            ("IICP_PRE1_RUNTIME_MAP", {**self.runtime, "target": "linux-aarch64"}),
            ("IICP_PRE1_ENVIRONMENT_MANIFEST", {**self.environment, "status": "FAILED"}),
        ]
        for name, value in mutations:
            path = self.documents[name]
            previous = path.read_text()
            try:
                path.write_text(json.dumps(value))
                with self.subTest(name=name, value=value), self.assertRaises(ValueError):
                    module.validate_context(self.cell, None)
            finally:
                path.write_text(previous)



class ModernEnvironmentTests(unittest.TestCase):
    def fixture(self):
        runtime = module.RUNTIMES[0]
        value = {
            "schema": "iicp.pre1-qualification-environment.v3",
            "status": "READY", "target": "linux-x86_64",
            "bindings": {key: "sha256:" + char * 64 for key, char in (
                ("candidate_manifest_sha256", "a"),
                ("artifact_materialization_sha256", "b"),
                ("runtime_map_sha256", "c"))},
            "network": {"preparation_egress": "dependency-download-only",
                        "qualification_egress": "disabled", "loopback_fixtures": True},
            "source_state": {"clean_checkout": True, "empty_volatile_caches_at_start": True,
                             "product_artifacts_separate": True},
            "execution": {"execution_kind": "native", "evidence_scope": "functional-and-performance",
                          "host_architecture": "x86_64", "guest_architecture": "x86_64",
                          "container_engine": None, "container_engine_version": None,
                          "emulation_mechanism": None, "image_digest": None},
            "isolation": {"kind": "isolated-fixture", "identity": "test-only",
                          "evidence_sha256": "sha256:" + "d" * 64},
            "runtimes": {runtime: {
                "lock_inputs_sha256": "sha256:" + "e" * 64,
                "dependency_cache_sha256": "sha256:" + "f" * 64,
                "online_prepare_status": "PASS", "offline_install_status": "PASS",
                "package_artifact_smoke_status": "PASS", "egress_disabled_during_offline": True,
                "empty_volatile_cache_at_start": True}},
            "content_free": True, "secrets_present": False, "non_authorizing": True,
            "environment_sha256": None,
        }
        return runtime, value

    def check(self, value, runtime):
        value["environment_sha256"] = None
        value["environment_sha256"] = module.canonical_sha256(value)
        return module._validate_environment_manifest(
            value, target="linux-x86_64", runtime=runtime,
            candidate_digest="sha256:" + "a" * 64,
            materialization_digest="sha256:" + "b" * 64,
            runtime_map_digest="sha256:" + "c" * 64)

    def test_v3_preserves_provenance_and_package_gates(self):
        runtime, value = self.fixture()
        self.assertEqual(self.check(value, runtime), value["environment_sha256"])

    def test_rehashed_invalid_provenance_is_not_accepted(self):
        mutations = [("execution", "guest_architecture", "aarch64"),
                     ("execution", "evidence_scope", "functional-only"),
                     ("execution", "image_digest", "sha256:" + "0" * 64),
                     ("isolation", "evidence_sha256", "invalid"),
                     ("network", "qualification_egress", "enabled"),
                     ("source_state", "product_artifacts_separate", False)]
        for section, key, replacement in mutations:
            runtime, value = self.fixture()
            value[section][key] = replacement
            with self.subTest(key=key), self.assertRaises(ValueError):
                self.check(value, runtime)

    def test_rehashed_invalid_dependency_hash_is_not_accepted(self):
        runtime, value = self.fixture()
        value["runtimes"][runtime]["dependency_cache_sha256"] = "missing"
        with self.assertRaises(ValueError):
            self.check(value, runtime)


class QualityWorkflowBoundaryTests(unittest.TestCase):
    def check_changes(self, paths):
        from unittest.mock import patch
        import pre1_harness_binding as binding
        identity = {"harness_source_commit": "1" * 40, "harness_sha256": "sha256:" + "2" * 64}
        with patch.object(binding, "harness_identity", return_value=identity), patch.object(binding, "git", side_effect=[b"", ("\0".join(paths) + "\0").encode()]):
            binding.validate_harness_source(ROOT, "3" * 40, identity)

    def test_reviewed_quality_workflows_are_digest_bound_tooling(self):
        self.check_changes([".github/workflows/quality.yml", ".github/workflows/ci.yml"])

    def test_release_dependencies_and_runtime_remain_frozen(self):
        for path in [".github/workflows/release.yml", ".github/workflows/other.yml", "Cargo.lock", "Cargo.toml", "src/lib.rs"]:
            with self.subTest(path=path), self.assertRaisesRegex(ValueError, "frozen product source"):
                self.check_changes([path])



# Output handoff regression coverage runs in the existing owning driver gate.
import json
import os
import sys
import tempfile
from unittest.mock import Mock, patch
import pre1_package_execution as adapter


class DirectoryOutputTests(unittest.TestCase):
    def setUp(self):
        self.contract = json.loads((ROOT / 'parity/behavior-contract-v1.json').read_bytes())
        self.context = {'component': module.COMPONENT, 'scenario_id': 'cross-flavor-equivalence', 'mode': 'restricted'}
        self.assertion = module.SCENARIO_CASES['cross-flavor-equivalence']['assertion']
        self.marker = 'IICP_PRE1_DIRECTORY_ASSERTION_PASS ' + self.assertion

    def values(self):
        observations = {}
        for group in ('eligibility_cases', 'ranking_cases', 'pricing_cases'):
            for case in self.contract[group]:
                if group == 'pricing_cases': value = case['expected']
                else:
                    ids = sorted(case['expected_ids']) if group == 'eligibility_cases' else (
                        [] if case['requested_model'] == 'missing-model' else ['fixture-http-ranking'])
                    scores = [0.9 - i / 10 for i in range(len(ids))] if group == 'eligibility_cases' else ([case['expected']] if ids else [])
                    value = {'eligible_ids': ids, 'recommendation_order': ids, 'scores': scores}
                observations[group + '/' + case['name']] = value
        return {row['name']: row['expected'] for row in self.contract['registration_cases']}, {
            'scope': 'installed-' + module.COMPONENT.removeprefix('directory-') + '-tcp-discovery-and-registration-pricing',
            'mode': 'restricted', 'fixture_sha256': 'sha256:61f84608db554cf2a3da02c46e01f27c77e57c9553ade0da8c5a017860d73f3f',
            'qualification_credit': False, 'production_endpoint_validation': False, 'observations': observations}

    def endpoints(self):
        return {"scope": "installed-" + module.COMPONENT.removeprefix("directory-") + "-tcp-production-endpoints",
            "mode": "restricted", "fixture_sha256": "sha256:61f84608db554cf2a3da02c46e01f27c77e57c9553ade0da8c5a017860d73f3f",
            "app_env": "production", "qualification_credit": False, "observations": {
                "endpoint_cases/" + row["name"]: {"blocked": row["blocked"], "status": 422,
                    "reason": "IICP-E035" if row["blocked"] else "IICP-E036",
                    "node_rows": 0, "capability_rows": 0, "availability_rows": 0}
                for row in self.contract["endpoint_cases"]}}

    def output(self, registration=None, discovery=None):
        a, b = self.values()
        return '\n'.join(['IICP_PRE1_REGISTRATION_OBSERVATION ' + json.dumps(a if registration is None else registration),
            'IICP_PRE1_REGISTRATION_TRANSPORT tcp',
            'IICP_PRE1_INSTALLED_DISCOVERY_OBSERVATION ' + json.dumps(b if discovery is None else discovery),
            "IICP_PRE1_INSTALLED_ENDPOINT_OBSERVATION " + json.dumps(self.endpoints()), self.marker]) + '\n'

    def test_endpoint_records_fail_closed(self):
        from copy import deepcopy
        original = self.endpoints()
        for field, wrong in (("app_env", "testing"), ("qualification_credit", True),
                             ("mode", "public"), ("fixture_sha256", "sha256:" + "0" * 64)):
            value = deepcopy(original); value[field] = wrong
            with self.subTest(field=field), self.assertRaises(ValueError):
                adapter.validate_directory_endpoint_output(value, self.context, self.contract)
        for change in ({"status": 201}, {"blocked": 1}, {"node_rows": False},
                       {"node_rows": 1}, {"reason": "IICP-E035"}):
            value = deepcopy(original)
            value["observations"]["endpoint_cases/public_ipv6"].update(change)
            with self.subTest(change=change), self.assertRaises(ValueError):
                adapter.validate_directory_endpoint_output(value, self.context, self.contract)
        value = deepcopy(original); value["observations"].pop("endpoint_cases/public_ipv6")
        with self.assertRaises(ValueError): adapter.validate_directory_endpoint_output(value, self.context, self.contract)

    def test_endpoint_marker_required_and_bounded(self):
        raw = self.output(); line = "IICP_PRE1_INSTALLED_ENDPOINT_OBSERVATION " + json.dumps(self.endpoints())
        for output in (raw.replace(line + "\n", ""), raw.replace(line, line + "\n" + line),
                       raw.replace(line, line.replace('"app_env": "production"', '"app_env": "production", "app_env": "production"'))):
            self.assertEqual(self.code(output), 2)

    def code(self, output, code=0):
        return adapter.directory_output_exit_code(code, output, self.context, self.assertion, ROOT)

    def test_complete_scoped_output_is_accepted(self):
        self.assertEqual(self.code(self.output()), 0)
        self.assertEqual(self.code(self.output(), 101), 101)

    def test_marker_only_other_cases_remain_strict(self):
        context = {**self.context, 'scenario_id': 'credential-missing'}
        self.assertEqual(adapter.directory_output_exit_code(0, self.marker + '\n', context, self.assertion, ROOT), 0)
        self.assertEqual(adapter.directory_output_exit_code(0, self.output(), context, self.assertion, ROOT), 2)
        self.assertEqual(adapter.directory_output_exit_code(0, self.marker + '\nnoise\n', context, self.assertion, ROOT), 2)

    def test_unknown_missing_duplicate_reordered_and_failed_markers_rejected(self):
        raw = self.output(); rows = raw.splitlines()
        for output in (self.marker, raw + self.marker, raw + 'noise\n', '\n'.join(rows[:-1]),
                       '\n'.join([rows[1], rows[0], *rows[2:]]), raw.replace(self.marker, 'FAIL'),
                       raw.replace('TRANSPORT tcp', 'TRANSPORT http-kernel'), 'x' * 1048577):
            with self.subTest(output=output[:70]): self.assertEqual(self.code(output), 2)

    def test_registration_boolean_partial_or_wrong_rollback_rejected(self):
        for field, value in (('node_rows', True), ('node_rows', 2), ('recovered', False)):
            a, b = self.values(); a['recovery_replaces_relations'][field] = value
            self.assertEqual(self.code(self.output(a, b)), 2)
        a, b = self.values(); a['revoked_operator_rolls_back']['capability_rows'] = 1
        self.assertEqual(self.code(self.output(a, b)), 2)

    def test_discovery_identity_coverage_credit_and_transport_scope_rejected(self):
        for field, value in (('mode', 'public'), ('scope', 'other'), ('fixture_sha256', 'sha256:' + 'a' * 64),
                             ('qualification_credit', True), ('production_endpoint_validation', True)):
            a, b = self.values(); b[field] = value
            self.assertEqual(self.code(self.output(a, b)), 2)
        a, b = self.values(); b['observations'].pop(next(iter(b['observations'])))
        self.assertEqual(self.code(self.output(a, b)), 2)

    def test_wrong_pricing_rank_and_eligibility_rejected(self):
        for group in ('pricing_cases', 'ranking_cases', 'eligibility_cases'):
            a, b = self.values(); key = next(k for k in b['observations'] if k.startswith(group))
            if group == 'pricing_cases': b['observations'][key] = True
            elif group == 'ranking_cases': b['observations'][key]['scores'] = [0.111]
            else: b['observations'][key]['eligible_ids'] = []
            self.assertEqual(self.code(self.output(a, b)), 2)

    def test_duplicate_json_nonfinite_and_malformed_rejected(self):
        raw = self.output()
        for output in (raw.replace('"mode": "restricted"', '"mode": "restricted", "mode": "restricted"'),
                       raw.replace('"node_rows": 1', '"node_rows": NaN'),
                       raw.replace('"node_rows": 1', '"node_rows": 1e999'),
                       raw.replace('"node_rows": 1', '"node_rows": invalid')):
            self.assertEqual(self.code(output), 2)

    def test_stale_fixture_is_rejected(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary); (root/'parity').mkdir(); (root/'parity/behavior-contract-v1.json').write_text('{}')
            self.assertEqual(adapter.directory_output_exit_code(0, self.output(), self.context, self.assertion, root), 2)

    def test_main_writes_real_handoff_result_into_existing_proof(self):
        for output, child_code, expected in ((self.output(), 0, 0), (self.marker, 0, 2), (self.output(), 101, 101)):
            component = {'id': module.COMPONENT}; manifest = {'components': [component]}
            proof = {'value': {'fixture': True}, 'artifact': Path('/fixture/artifact')}
            with patch.object(sys, 'argv', ['driver', '--cell', 'fixture', '--scenario', 'cross-flavor-equivalence', '--evidence-mode', 'digest-only']), \
                 patch.dict(os.environ, {'IICP_PRE1_ARTIFACT_ROOT': '/fixture', 'IICP_PRE1_RUN_ID': 'unit-run'}), \
                 patch.object(module, 'validate_context', return_value=('fixture', {}, manifest, self.context)), \
                 patch.object(module, 'validate_runtime'), patch.object(module, 'command_environment', return_value={}), \
                 patch.object(module, 'package_command', return_value=(['fixture'], {}, ROOT, proof)), \
                 patch.object(module.subprocess, 'run', return_value=Mock(returncode=child_code, stdout=output)), \
                 patch.object(module, 'validate_binding'), patch.object(module, 'make_case_proof') as make, \
                 patch.object(module, 'write_case_proof') as write, patch('builtins.print'):
                self.assertEqual(module.main(), expected)
                self.assertEqual(make.call_args.args[3], expected)
                write.assert_called_once_with(make.return_value)



if __name__ == "__main__":
    unittest.main()

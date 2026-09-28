"""Negative controls for bounded installed HTTP discovery observations."""
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock, patch

import pre1_installed_discovery as probe
import pre1_package_execution as adapter


class InstalledDiscoveryTests(unittest.TestCase):
    def setUp(self):
        self.contract = probe.fixture(Path(__file__).resolve().parents[1])

    def answer(self):
        return {"count": 2, "nodes": [{"node_id": "eligible", "score": 0.9},
                                     {"node_id": "fallback-capability", "score": 0.7}]}

    def registration_snapshot(self, identifier=None, **changes):
        counts = {"node_rows": 1, "capability_rows": 1, "availability_rows": 1} if identifier else {
            "node_rows": 0, "capability_rows": 0, "availability_rows": 0}
        return {"headers": {}, "claim": None, "counts": counts, "node_id": identifier,
            "models": ["model-b"], "start": "09:00", "operator_verified": 1,
            "operator_pubkey": "synthetic-public", "operator_status": "active", **changes}

    def test_tcp_recovery_reads_token_and_actual_replaced_relations(self):
        state = Mock(side_effect=[self.registration_snapshot(), self.registration_snapshot("fixture-http-recovery")])
        responses = [(201, {"node_id": "fixture-http-recovery", "node_token": "first"}),
            (201, {"node_id": "fixture-http-recovery", "node_token": "second", "recovered": True})]
        expected = self.contract["registration_cases"][0]["expected"]
        with patch.object(probe, "request", side_effect=responses) as request:
            self.assertEqual(probe.observe_registration_recovery(state, "public", expected), expected)
            self.assertEqual(request.call_args.args[2]["current_node_token"], "first")
        self.assertEqual(state.call_args.args[0], "registration_snapshot")

    def test_tcp_recovery_wrong_models_windows_rows_or_boolean_refused(self):
        expected = self.contract["registration_cases"][0]["expected"]
        for changes in ({"models": ["model-a"]}, {"start": "08:00"}, {"node_id": "other"},
                        {"counts": {"node_rows": True, "capability_rows": 1, "availability_rows": 1}}):
            state = Mock(side_effect=[self.registration_snapshot(), self.registration_snapshot("fixture-http-recovery", **changes)]) if "node_id" not in changes else Mock(side_effect=[self.registration_snapshot(), self.registration_snapshot(**changes)])
            responses = [(201, {"node_id": "fixture-http-recovery", "node_token": "first"}),
                (201, {"node_id": "fixture-http-recovery", "node_token": "second", "recovered": True})]
            with patch.object(probe, "request", side_effect=responses), self.assertRaises(ValueError):
                probe.observe_registration_recovery(state, "public", expected)

    def rollback_inputs(self):
        claim = {"node_id": "fixture-http-operator", "operator_pub": "synthetic-public", "not_after": 9999999999, "sig": "fixture"}
        states = [self.registration_snapshot(claim=claim), self.registration_snapshot("fixture-http-operator"),
            self.registration_snapshot(operator_status="revoked"), self.registration_snapshot(operator_status="revoked")]
        responses = [(201, {"node_id": "fixture-http-operator", "node_token": "fixture"}),
            (422, {"error": {"code": "validation_error", "fields": {"operator_delegation": [
                "operator identity is rotated or revoked and cannot make new delegation claims (IICP-E063)"]}}})]
        return states, responses

    def test_tcp_revocation_requires_bound_operator_and_zero_partial_rows(self):
        states, responses = self.rollback_inputs()
        expected = self.contract["registration_cases"][1]["expected"]
        state = Mock(side_effect=states)
        with patch.object(probe, "request", side_effect=responses) as request:
            self.assertEqual(probe.observe_registration_rollback(state, expected), expected)
            self.assertEqual(request.call_args_list[0].args[2], request.call_args_list[1].args[2])
        self.assertIn((("registration_operator_revoke",), {}), [(row.args, row.kwargs) for row in state.call_args_list])

    def test_tcp_revocation_refuses_bad_positive_wrong_reason_and_partial_rollback(self):
        expected = self.contract["registration_cases"][1]["expected"]
        for defect in ("unverified", "boolean", "wrong-public", "not-revoked", "generic-422", "partial"):
            states, responses = self.rollback_inputs()
            if defect == "unverified": states[1]["operator_verified"] = 0
            elif defect == "boolean": states[1]["operator_verified"] = True
            elif defect == "wrong-public": states[1]["operator_pubkey"] = "other"
            elif defect == "not-revoked": states[2]["operator_status"] = "active"
            elif defect == "generic-422": responses[1][1]["error"]["fields"]["operator_delegation"] = ["bad signature"]
            else: states[3]["counts"]["capability_rows"] = 1
            with self.subTest(defect=defect), patch.object(probe, "request", side_effect=responses), self.assertRaises(ValueError):
                probe.observe_registration_rollback(Mock(side_effect=states), expected)

    def test_tcp_restricted_anonymous_refusal_must_not_persist(self):
        for response, snapshot in (((200, {}), self.registration_snapshot()),
                ((401, {"error": {"code": "other"}}), self.registration_snapshot()),
                ((401, {"error": {"code": "restricted_domain_denied"}}), self.registration_snapshot("fixture-http-recovery"))):
            with patch.object(probe, "request", return_value=response), self.assertRaises(ValueError):
                probe.observe_registration_recovery(Mock(side_effect=[self.registration_snapshot(), snapshot]),
                    "restricted", self.contract["registration_cases"][0]["expected"])

    def test_registration_state_refuses_bad_actions_and_redacts_error_capture(self):
        with self.assertRaises(ValueError): probe.registration_state(Path("/installed"), "php", {}, Path("/router"), "other")
        with tempfile.TemporaryDirectory() as temporary, patch.dict(os.environ, {"IICP_PRE1_CASE_EVIDENCE_ROOT": temporary}), \
             patch.object(probe.subprocess, "run", return_value=Mock(returncode=1, stdout=b"credential-marker", stderr=b"private-app-key private-genesis-key")):
            with self.assertRaises(ValueError):
                probe.registration_state(Path("/installed"), "php", {"APP_KEY": "private-app-key", "IICP_GENESIS_ED25519_SECRET_KEY": "private-genesis-key"}, Path("/router"), "registration_snapshot")
            logs = list(Path(temporary).glob("registration-state-failure-*.log"))
            self.assertEqual(len(logs), 1)
            self.assertNotIn(b"private-", logs[0].read_bytes())
            self.assertNotIn(b"credential-marker", logs[0].read_bytes())
            self.assertEqual(logs[0].stat().st_mode & 0o777, 0o600)

    def test_eligible_set_and_recommendation_order_are_separate(self):
        result = probe.discovery_projection(200, self.answer(), self.contract["eligibility_cases"][0], "eligibility_cases")
        self.assertEqual(["eligible", "fallback-capability"], result["recommendation_order"])
        self.assertEqual(sorted(self.contract["eligibility_cases"][0]["expected_ids"]), result["eligible_ids"])

    def test_missing_duplicate_ineligible_and_wrong_order_refused(self):
        cases = [self.answer() for _ in range(5)]
        cases[0]["nodes"].pop()
        cases[1]["nodes"][1]["node_id"] = "eligible"
        cases[2]["nodes"][1]["node_id"] = "empty-health"
        cases[3]["nodes"].reverse()
        cases[4]["count"] = True
        for value in cases:
            with self.subTest(value=value), self.assertRaises(ValueError):
                probe.discovery_projection(200, value, self.contract["eligibility_cases"][0], "eligibility_cases")

    def test_invalid_scores_and_server_errors_refused(self):
        for score in (True, float("nan"), float("inf"), -0.1, 1.1, "0.9"):
            value = self.answer()
            value["nodes"][0]["score"] = score
            with self.subTest(score=score), self.assertRaises(ValueError):
                probe.discovery_projection(200, value, self.contract["eligibility_cases"][0], "eligibility_cases")
        for status in (401, 422, 500):
            with self.assertRaises(ValueError):
                probe.discovery_projection(status, self.answer(), self.contract["eligibility_cases"][0], "eligibility_cases")

    def test_missing_model_never_returns_primitive_scored_candidate(self):
        case = self.contract["ranking_cases"][2]
        self.assertEqual([], probe.discovery_projection(200, {"count": 0, "nodes": []}, case, "ranking_cases")["scores"])
        with self.assertRaises(ValueError):
            probe.discovery_projection(200, {"count": 1, "nodes": [{"node_id": "fixture-http-ranking", "score": case["expected"]}]}, case, "ranking_cases")

    def test_actual_ranking_score_required(self):
        case = self.contract["ranking_cases"][0]
        value = {"count": 1, "nodes": [{"node_id": "fixture-http-ranking", "score": case["expected"]}]}
        self.assertEqual([case["expected"]], probe.discovery_projection(200, value, case, "ranking_cases")["scores"])
        value["nodes"][0]["score"] += 0.01
        with self.assertRaises(ValueError):
            probe.discovery_projection(200, value, case, "ranking_cases")

    def test_environment_is_explicit_not_inherited(self):
        env = {"PATH": "/fixture", "HTTP_PROXY": "secret", "DB_PASSWORD": "secret", "APP_ENV": "production"}
        for mode in ("public", "local-only", "restricted"):
            result = probe.launch_environment(Path("/installed"), Path("/case"), env, mode)
            self.assertNotIn("HTTP_PROXY", result)
            self.assertNotIn("DB_PASSWORD", result)
            self.assertEqual("testing", result["APP_ENV"])
            self.assertEqual("true" if mode == "restricted" else "false", result["IICP_RESTRICTED_DOMAIN_ENABLED"])
            self.assertEqual("/case/database.sqlite", result["DB_DATABASE"])
        with self.assertRaises(ValueError):
            probe.launch_environment(Path("/installed"), Path("/case"), env, "other")

    def test_fixture_mutation_and_symlink_refused(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / "parity").mkdir()
            path = root / "parity/behavior-contract-v1.json"
            path.write_text(json.dumps(self.contract))
            with self.assertRaises(ValueError):
                probe.fixture(root)
            path.unlink()
            path.symlink_to(Path(__file__).resolve().parents[1] / "parity/behavior-contract-v1.json")
            with self.assertRaises(ValueError):
                probe.fixture(root)

    def test_observer_is_preparation_bound_and_required_before_success(self):
        rows = adapter.directory_fixtures(Path(__file__).resolve().parents[1], "directory-php")
        self.assertEqual(Path(probe.__file__).read_bytes(), rows["directory-discovery.py"])
        self.assertIn('installed_observer["execute"]', adapter.DIRECTORY_PROBE)
        self.assertLess(adapter.DIRECTORY_PROBE.index('installed_observer["execute"]'),
                        adapter.DIRECTORY_PROBE.rindex('print("IICP_PRE1_DIRECTORY_ASSERTION_PASS'))

    def test_http_redirect_and_non_fixture_destination_refused(self):
        with self.assertRaises(ValueError):
            probe.NoRedirect().redirect_request(None, None, 302, None, None, "https://iicp.network")
        for path in ("https://iicp.network/api/v1/discover", "//example", "/api/v1/discover\n"):
            with self.assertRaises(ValueError):
                probe.request(path, {})

    def test_pricing_read_from_discovery_not_registration_response(self):
        case = self.contract["pricing_cases"][0]
        discovery = {"count": 1, "nodes": [{"node_id": "fixture-http-price", "score": 0.5,
            "pricing": {"credit_cost_multiplier": case["expected"]}}]}
        with patch.object(probe, "request", side_effect=[(201, {"node_id": "fixture-http-price"}), (200, discovery)]) as request:
            self.assertEqual(case["expected"], probe.observe_pricing(case, {}))
        self.assertEqual("/api/v1/register", request.call_args_list[0].args[0])
        self.assertIn("/api/v1/discover?", request.call_args_list[1].args[0])
        self.assertEqual({"max_concurrent": 10, "tokens_per_min": 10000}, request.call_args_list[0].args[2]["limits"])

    def test_pricing_failure_does_not_expose_response_credentials(self):
        with patch.object(probe, "request", return_value=(422, {"node_token": "secret-token",
                "error": {"fields": {"region": ["sensitive text"]}}})):
            with self.assertRaises(ValueError) as caught:
                probe.observe_pricing(self.contract["pricing_cases"][0], {})
        self.assertIn("status=422", str(caught.exception))
        self.assertIn("region", str(caught.exception))
        self.assertNotIn("secret-token", str(caught.exception))
        self.assertNotIn("sensitive text", str(caught.exception))

    def test_wrong_pricing_nonfinite_and_boolean_refused(self):
        for actual in (True, float("nan"), float("inf"), 0.16, "0.15"):
            discovery = {"count": 1, "nodes": [{"node_id": "fixture-http-price", "score": 0.5,
                "pricing": {"credit_cost_multiplier": actual}}]}
            with self.subTest(actual=actual), patch.object(probe, "request", side_effect=[
                    (201, {"node_id": "fixture-http-price"}), (200, discovery)]), self.assertRaises(ValueError):
                probe.observe_pricing(self.contract["pricing_cases"][0], {})

    def test_failed_seed_preserves_private_log_without_membership_marker(self):
        child = Mock(returncode=1, stdout=b"seed error\nIICP_PRE1_HTTP_SEED secret-membership\n", stderr=b"native failure")
        with tempfile.TemporaryDirectory() as temporary, patch.dict(os.environ,
                {"IICP_PRE1_CASE_EVIDENCE_ROOT": temporary}), patch.object(probe.subprocess, "run", return_value=child):
            with self.assertRaises(ValueError):
                probe.seed_case(Path("/installed"), "php", {}, Path("/router"), "eligibility_cases", 0)
            path = Path(temporary) / "installed-http-seed-failure.log"
            self.assertEqual(0o600, path.stat().st_mode & 0o777)
            self.assertIn(b"seed error", path.read_bytes())
            self.assertNotIn(b"secret-membership", path.read_bytes())

    def test_teardown_checks_listener_absence_after_process_exit(self):
        process = Mock(pid=1234)
        with patch.object(probe.os, "killpg") as kill, patch.object(probe, "listener_absent") as absent:
            probe.stop_server(process)
        kill.assert_called_once_with(1234, probe.signal.SIGKILL)
        process.wait.assert_called_once_with(timeout=10)
        absent.assert_called_once_with()

    def test_limit_request_cannot_substitute_recommended_provider(self):
        complete = self.answer()
        limited = {"count": 1, "nodes": [complete["nodes"][1]]}
        with patch.object(probe, "request", side_effect=[(200, complete), (200, limited)]), self.assertRaises(ValueError):
            probe.observe_selection(self.contract["eligibility_cases"][0], "eligibility_cases", {})

    def test_provider_fixture_cleanup_on_failed_registration(self):
        with patch.object(probe, "listener_absent") as absent, patch.object(probe, "HTTPServer") as factory, patch.object(probe.threading, "Thread") as thread:
            thread.return_value.is_alive.return_value = False
            with self.assertRaises(ValueError):
                with probe.health_fixture():
                    raise ValueError("registration failed")
            factory.assert_called_once_with(("127.0.0.1", 8092), probe.FixtureHealthHandler)
            factory.return_value.shutdown.assert_called_once_with()
            factory.return_value.server_close.assert_called_once_with()
            thread.return_value.join.assert_called_once_with(timeout=5)
            self.assertEqual([((8092,), {}), ((8092,), {})], [(x.args, x.kwargs) for x in absent.call_args_list])

    def test_provider_fixture_surviving_listener_is_failure(self):
        with patch.object(probe, "listener_absent", side_effect=[None, ValueError("listener survived")]), patch.object(probe, "HTTPServer"), patch.object(probe.threading, "Thread") as thread:
            thread.return_value.is_alive.return_value = False
            with self.assertRaisesRegex(ValueError, "listener survived"):
                with probe.health_fixture():
                    pass


if __name__ == "__main__":
    unittest.main()

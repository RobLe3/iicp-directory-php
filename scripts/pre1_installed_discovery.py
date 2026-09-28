"""TCP observations from an isolated, installed PHP Directory; no admission authority."""
from __future__ import annotations

import base64
from contextlib import contextmanager
from http.server import BaseHTTPRequestHandler, HTTPServer
import threading
import errno
import hashlib
import json
import math
import os
from pathlib import Path
import signal
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

FIXTURE_SHA256 = "61f84608db554cf2a3da02c46e01f27c77e57c9553ade0da8c5a017860d73f3f"
INTENT = "urn:iicp:intent:llm:chat:v1"

BOOTSTRAP = r'''<?php
$root = realpath(getenv('PRE1_DIRECTORY_INSTALLED'));
$state = realpath(getenv('IICP_PRE1_MODE_STATE'));
if (!$root || !$state) { throw new RuntimeException('Installed HTTP state unavailable'); }
require getenv('IICP_PRE1_ORIGIN_GUARD');
$app = require $root . '/bootstrap/app.php';
$app->useStoragePath($state . '/storage');
if (PHP_SAPI === 'cli-server') {
    $app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
    if (config('app.env') !== 'testing' || config('database.connections.sqlite.database') !== $state . '/database.sqlite') {
        throw new RuntimeException('Installed HTTP configuration boundary differs');
    }
    $app->handleRequest(Illuminate\Http\Request::capture());
    return;
}
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $state . '/database.sqlite') {
    throw new RuntimeException('Installed HTTP database boundary differs');
}
if (Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
    throw new RuntimeException('Installed HTTP migration failed');
}
$fixturePath = $root . '/parity/behavior-contract-v1.json';
if (hash_file('sha256', $fixturePath) !== '61f84608db554cf2a3da02c46e01f27c77e57c9553ade0da8c5a017860d73f3f') {
    throw new RuntimeException('Installed HTTP fixture differs');
}
$fixture = json_decode(file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
App\Models\Node::query()->delete();
$group = $argv[1];
$index = (int) $argv[2];
$headers = [];
$membership = $app->make(App\Services\TrustDomainMembershipService::class);
if (config('iicp.restricted_domain.enabled')) {
    $issued = $membership->issue('client', 'fixture-http-client', ['discovery'], 3600);
    $headers = ['X-IICP-Membership' => $issued['token'], 'X-IICP-Subject-Id' => 'fixture-http-client'];
}
if ($group !== 'pricing_cases') {
    $case = $fixture[$group][$index];
    $candidates = $group === 'eligibility_cases' ? $case['candidates'] : [['id' => 'fixture-http-ranking', ...$case['node']]];
    foreach ($candidates as $candidate) {
        $node = App\Models\Node::create([
            'id' => $candidate['id'], 'endpoint' => 'http://127.0.0.1:1',
            'node_token_hash' => password_hash('synthetic-fixture', PASSWORD_BCRYPT),
            'region' => $candidate['region'] ?? 'eu-west', 'available' => true, 'status' => 'active',
            'last_seen' => now(), 'public_reachable' => true,
            'load' => $candidate['load'] ?? 0.2, 'active_jobs' => $candidate['active_jobs'] ?? 2,
            'max_concurrent' => $candidate['max_concurrent'] ?? 10, 'tokens_per_min' => 10000,
            'health_models' => $candidate['health_models'] ?? null,
            'backend_stability' => isset($candidate['backend_state']) ? ['backend_state' => $candidate['backend_state'], 'reason_class' => 'ok'] : null,
            'pricing_credits_per_1000' => $candidate['pricing'] ?? null,
            'sdk_version' => ($candidate['sdk_current'] ?? true) ? App\Services\NodeReadinessPolicy::SDK_BASELINE_VERSION : null,
            'cx_public_key' => ($candidate['cx_key'] ?? true) ? 'fixture-key' : null,
        ]);
        $node->capabilities()->create(['intent' => 'urn:iicp:intent:llm:chat:v1', 'models' => $candidate['models'], 'max_tokens' => 4096]);
        if (($candidate['reputation'] ?? null) !== null) {
            App\Models\Reputation::create(['node_id' => $node->id, 'score' => $candidate['reputation'],
                'completed_tasks_count' => $candidate['tasks'] ?? 0, 'tasks_total' => max(1, $candidate['tasks'] ?? 0), 'tasks_failed' => 0]);
        }
        if (config('iicp.restricted_domain.enabled')) { $membership->issue('node', $node->id, ['registration'], 3600); }
    }
} elseif (config('iicp.restricted_domain.enabled')) {
    $issued = $membership->issue('node', 'fixture-http-price', ['registration', 'discovery'], 3600);
    $headers = ['X-IICP-Membership' => $issued['token'], 'X-IICP-Subject-Id' => 'fixture-http-price'];
}
echo 'IICP_PRE1_HTTP_SEED ' . json_encode((object) $headers, JSON_THROW_ON_ERROR) . "\n";
'''


def fixture(installed: Path):
    path = installed / "parity/behavior-contract-v1.json"
    if path.is_symlink() or hashlib.sha256(path.read_bytes()).hexdigest() != FIXTURE_SHA256:
        raise ValueError("installed discovery fixture differs")
    return json.loads(path.read_bytes())


def listener_absent(port=8091):
    with socket.socket() as connection:
        connection.settimeout(1)
        if connection.connect_ex(("127.0.0.1", port)) != errno.ECONNREFUSED:
            raise ValueError("installed HTTP listener absence not established")


class FixtureHealthHandler(BaseHTTPRequestHandler):
    def do_GET(self):
        self.send_response(200 if self.path == "/iicp/health" else 404)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(b'{"fixture":true}')

    def log_message(self, *args):
        pass


@contextmanager
def health_fixture():
    """Synthetic provider liveness only; never another Directory authority."""
    listener_absent(8092)
    server = HTTPServer(("127.0.0.1", 8092), FixtureHealthHandler)
    thread = threading.Thread(target=lambda: server.serve_forever(poll_interval=0.05))
    thread.start()
    try:
        yield
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=5)
        if thread.is_alive():
            raise ValueError("installed provider fixture did not stop")
        listener_absent(8092)


def isolated_network():
    import fcntl
    import struct
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as control:
        active = {name for _, name in socket.if_nameindex()
            if struct.unpack_from("H", fcntl.ioctl(control.fileno(), 0x8913,
                struct.pack("256s", name.encode())), 16)[0] & 1}
    if active != {"lo"}:
        raise ValueError("installed HTTP requires loopback-only isolation")


def selection_values(status, body):
    if status != 200 or not isinstance(body, dict) or "error" in body:
        raise ValueError("installed discovery response differs")
    nodes = body.get("nodes")
    if not isinstance(nodes, list) or type(body.get("count")) is not int or body["count"] != len(nodes):
        raise ValueError("installed discovery count differs")
    ids = [row["node_id"] for row in nodes]
    scores = [row["score"] for row in nodes]
    if len(set(ids)) != len(ids) or any(not valid_score(score) for score in scores):
        raise ValueError("installed discovery projection differs")
    if scores != sorted(scores, reverse=True):
        raise ValueError("installed recommendation order differs")
    return ids, scores


def valid_score(score):
    return type(score) in (int, float) and math.isfinite(score) and 0 <= score <= 1


def discovery_projection(status, body, case, group):
    ids, scores = selection_values(status, body)
    if group == "eligibility_cases":
        if sorted(ids) != sorted(case["expected_ids"]):
            raise ValueError("installed eligibility differs")
    elif case["requested_model"] == "missing-model":
        if ids:
            raise ValueError("installed ranking widened eligibility")
    elif ids != ["fixture-http-ranking"] or scores != [case["expected"]]:
        raise ValueError("installed ranking differs")
    return {"eligible_ids": sorted(ids), "recommendation_order": ids, "scores": scores}


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        raise ValueError("installed HTTP redirect refused")


def request(path, headers, body=None):
    if not path.startswith("/api/v1/") or "\n" in path:
        raise ValueError("installed HTTP path differs")
    req = urllib.request.Request("http://127.0.0.1:8091" + path,
        data=None if body is None else json.dumps(body).encode(),
        headers={"Accept": "application/json", "Content-Type": "application/json", **headers})
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    try:
        response = opener.open(req, timeout=10)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        raw = response.read(65537)
        if len(raw) > 65536:
            raise ValueError("installed HTTP response exceeds bound")
        return response.status, json.loads(raw)


def launch_environment(installed, state, env, mode):
    if mode not in {"public", "restricted", "local-only"}:
        raise ValueError("installed HTTP mode differs")
    result = {key: env[key] for key in ("PATH",) if key in env}
    result.update(HOME=str(state), TMPDIR=str(state), PRE1_DIRECTORY_INSTALLED=str(installed),
        IICP_PRE1_MODE_STATE=str(state), IICP_PRE1_ORIGIN_GUARD=str(Path.cwd() / "directory-origin.php"),
        APP_ENV="testing", APP_KEY="base64:" + base64.b64encode(os.urandom(32)).decode(),
        DB_CONNECTION="sqlite", DB_DATABASE=str(state / "database.sqlite"), DB_URL="", DATABASE_URL="",
        CACHE_STORE="array", SESSION_DRIVER="array", QUEUE_CONNECTION="sync", LOG_CHANNEL="stderr",
        IICP_RESTRICTED_DOMAIN_ENABLED="true" if mode == "restricted" else "false",
        IICP_TRUST_DOMAIN_ID="example.internal", IICP_DIRECTORY_AUTHORITY_ID="did:key:directory",
        IICP_DIRECTORY_AUTHORITY_KEY_ID="did:key:directory#key-1", IICP_MEMBERSHIP_EPOCH="1")
    for key, name in {"APP_CONFIG_CACHE": "config.php", "APP_ROUTES_CACHE": "routes.php",
                      "APP_PACKAGES_CACHE": "packages.php", "APP_SERVICES_CACHE": "services.php"}.items():
        result[key] = str(state / name)
    return result


def seed_case(installed, php, env, router, group, index):
    child = subprocess.run([php, str(router), group, str(index)], cwd=installed, env=env,
        capture_output=True, timeout=40, check=False)
    rows = [row[len(b"IICP_PRE1_HTTP_SEED "):] for row in child.stdout.splitlines()
            if row.startswith(b"IICP_PRE1_HTTP_SEED ")]
    if child.returncode != 0 or len(child.stdout) > 16384 or len(rows) != 1:
        destination = os.environ.get("IICP_PRE1_CASE_EVIDENCE_ROOT")
        if destination:
            with (Path(destination) / "installed-http-seed-failure.log").open("xb") as output:
                os.fchmod(output.fileno(), 0o600)
                # Exclude the successful credential-bearing marker entirely.
                output.write(b"\n".join(row for row in child.stdout.splitlines()
                    if not row.startswith(b"IICP_PRE1_HTTP_SEED "))[:16384] + child.stderr[:16384])
        raise ValueError("installed HTTP seed failed; bounded private evidence retained")
    headers = json.loads(rows[0])
    if not isinstance(headers, dict) or set(headers) not in (set(), {"X-IICP-Membership", "X-IICP-Subject-Id"}):
        raise ValueError("installed HTTP seed headers differ")
    return headers


def observe_cases(installed, php, env, router, contract, mode):
    observed = {}
    for group in ("eligibility_cases", "ranking_cases", "pricing_cases"):
        for index, case in enumerate(contract[group]):
            headers = seed_case(installed, php, env, router, group, index)
            if mode == "restricted" and request("/api/v1/discover?intent=" + INTENT, {})[0] != 401:
                raise ValueError("installed HTTP anonymous access admitted")
            if group == "pricing_cases":
                observed[group + "/" + case["name"]] = observe_pricing(case, headers)
                continue
            observed[group + "/" + case["name"]] = observe_selection(case, group, headers)
    return observed


def observe_selection(case, group, headers):
    query = {"intent": INTENT, "limit": 50}
    for field in ("model", "qos", "min_reputation", "region"):
        value = case.get("requested_" + field, case.get(field))
        if value is not None:
            query[field] = value
    status, value = request("/api/v1/discover?" + urllib.parse.urlencode(query), headers)
    projection = discovery_projection(status, value, case, group)
    status, value = request("/api/v1/discover?" + urllib.parse.urlencode({**query, "limit": 1}), headers)
    ids, _ = selection_values(status, value)
    if ids != projection["recommendation_order"][:1]:
        raise ValueError("installed discovery limit differs")
    return projection


def observe_pricing(case, headers):
    body = {"node_id": "fixture-http-price", "endpoint": "http://127.0.0.1:8092", "region": "eu-west",
        "capabilities": [{"intent": INTENT, "models": case["models"], "max_tokens": 4096}],
        "limits": {"max_concurrent": 10, "tokens_per_min": 10000},
        "pricing": {"credit_cost_multiplier": case["declared"]}}
    status, value = request("/api/v1/register", headers, body)
    if status != 201 or value.get("node_id") != "fixture-http-price":
        raise ValueError("installed pricing registration failed: status=" + str(status)
            + "; validation_fields=" + ",".join(sorted(value.get("error", {}).get("fields", {}))))
    status, value = request("/api/v1/discover?" + urllib.parse.urlencode({"intent": INTENT, "model": case["models"][0]}), headers)
    ids, _ = selection_values(status, value)
    if ids != ["fixture-http-price"]:
        raise ValueError("installed pricing provider not discovered")
    actual = value["nodes"][0].get("pricing", {}).get("credit_cost_multiplier")
    if (type(actual) not in (int, float) or not math.isfinite(actual)
            or abs(actual - case["expected"]) > 0.000001 or round(actual, 6) != case["expected"]):
        raise ValueError("installed discovery pricing differs")
    return round(actual, 6)


def validate_private_home(installed, env):
    home = Path(env["HOME"])
    if home.is_symlink() or not home.is_absolute() or home.stat().st_mode & 0o077:
        raise ValueError("installed HTTP requires private case HOME")
    if (installed / ".env").exists() or (installed / "bootstrap/cache/config.php").exists():
        raise ValueError("installed HTTP rejects dotenv or cached configuration")
    return home


def wait_listener(process):
    deadline = time.monotonic() + 15
    while True:
        try:
            if request("/api/v1/discover?intent=" + INTENT, {})[0] in {200, 401}:
                return
        except urllib.error.URLError:
            pass
        if process.poll() is not None or time.monotonic() > deadline:
            raise ValueError("installed PHP listener readiness failed")
        time.sleep(0.1)


def retain_server_failure(log):
    destination = os.environ.get("IICP_PRE1_CASE_EVIDENCE_ROOT")
    if destination:
        with (Path(destination) / "installed-http-failure.log").open("xb") as output:
            os.fchmod(output.fileno(), 0o600)
            log.seek(0)
            output.write(log.read(1024 * 1024))


def stop_server(process):
    try:
        os.killpg(process.pid, signal.SIGKILL)
    except ProcessLookupError:
        pass
    process.wait(timeout=10)
    listener_absent()


def execute(installed, php, env, mode):
    isolated_network()
    listener_absent()
    contract = fixture(installed)
    home = validate_private_home(installed, env)
    with health_fixture(), tempfile.TemporaryDirectory(prefix="installed-http-", dir=home) as temporary, tempfile.TemporaryFile() as log:
        state = Path(temporary)
        (state / "database.sqlite").touch(mode=0o600)
        for name in ("storage/logs", "storage/framework/cache/data", "storage/framework/sessions", "storage/framework/views"):
            (state / name).mkdir(mode=0o700, parents=True)
        router = state / "router.php"
        router.write_text(BOOTSTRAP)
        launch = launch_environment(installed, state, env, mode)
        launch["IICP_GENESIS_ED25519_SECRET_KEY"] = subprocess.check_output(
            [php, "-r", "echo sodium_bin2hex(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));"],
            env=launch, timeout=10).decode()
        seed_case(installed, php, launch, router, "eligibility_cases", 0)
        process = subprocess.Popen([php, "-S", "127.0.0.1:8091", str(router)],
            cwd=installed, env=launch, stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
        try:
            wait_listener(process)
            observations = observe_cases(installed, php, launch, router, contract, mode)
        except BaseException:
            retain_server_failure(log)
            raise
        finally:
            stop_server(process)
    return {"scope": "installed-php-tcp-discovery-and-registration-pricing", "mode": mode,
        "fixture_sha256": "sha256:" + FIXTURE_SHA256, "observations": observations,
        "qualification_credit": False, "production_endpoint_validation": False}

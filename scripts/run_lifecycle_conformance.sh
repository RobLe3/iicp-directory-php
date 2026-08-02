#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
# Run the released, content-free directory lifecycle profile against disposable state.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SPEC_REPO="${IICP_SPEC_REPO:-$ROOT/../IICP}"
CONFORMANCE_REF="${IICP_CONFORMANCE_REF:-v1.10.8}"
PROFILE="directory-lifecycle-v1"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/iicp-php-lifecycle.XXXXXX")"
SERVER_PID=""
umask 077

cleanup() {
  if [[ -n "$SERVER_PID" ]]; then
    kill "$SERVER_PID" >/dev/null 2>&1 || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
  python3 - "$TMP" <<'PY'
import shutil
import sys
shutil.rmtree(sys.argv[1], ignore_errors=True)
PY
}
trap cleanup EXIT

for command in curl git php python3 tar; do
  command -v "$command" >/dev/null || { echo "missing command: $command" >&2; exit 2; }
done
[[ -d "$SPEC_REPO/.git" ]] || { echo "missing IICP specification checkout" >&2; exit 2; }
git -C "$SPEC_REPO" rev-parse --verify "refs/tags/$CONFORMANCE_REF" >/dev/null

cd "$ROOT"
php artisan test --compact \
  tests/Feature/RoutableEndpointRuleTest.php \
  tests/Feature/LivenessProbeSsrfTest.php >/dev/null

DB="$TMP/lifecycle.sqlite"
: >"$DB"
export APP_ENV=testing APP_DEBUG=false
export APP_KEY="base64:$(python3 - <<'PY'
import base64
import os
print(base64.b64encode(os.urandom(32)).decode())
PY
)"
export DB_CONNECTION=sqlite DB_DATABASE="$DB"
export CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
export IICP_SKIP_LIVENESS_CHECK=true

php artisan migrate --force >"$TMP/migrate.log" 2>&1
PORT="$(python3 - <<'PY'
import socket
sock = socket.socket()
sock.bind(("127.0.0.1", 0))
print(sock.getsockname()[1])
sock.close()
PY
)"
php artisan serve --host=127.0.0.1 --port="$PORT" >"$TMP/server.log" 2>&1 &
SERVER_PID=$!

ready=false
for _ in $(seq 1 60); do
  if curl --fail --silent --show-error "http://127.0.0.1:$PORT/api/v1/stats" >/dev/null 2>&1; then
    ready=true
    break
  fi
  sleep 0.1
done
[[ "$ready" == true ]] || { echo "disposable PHP directory did not become ready" >&2; exit 1; }

git -C "$SPEC_REPO" archive "$CONFORMANCE_REF" conformance-runner | tar -x -C "$TMP"
python3 -m venv "$TMP/venv"
"$TMP/venv/bin/pip" install --quiet --disable-pip-version-check "$TMP/conformance-runner"
"$TMP/venv/bin/iicp-conformance" run \
  --profile "$PROFILE" \
  --target "http://127.0.0.1:$PORT" \
  --output "$TMP/result.json"
"$TMP/venv/bin/iicp-conformance" verify "$TMP/result.json" >/dev/null

python3 - "$TMP/result.json" "$CONFORMANCE_REF" <<'PY'
import json
import sys
result = json.load(open(sys.argv[1], encoding="utf-8"))
expected = {"total": 6, "passed": 6, "failed": 0}
if result.get("profile") != "directory-lifecycle-v1" or result.get("summary") != expected:
    raise SystemExit("lifecycle conformance failed")
print(json.dumps({
    "implementation": "php",
    "profile": result["profile"],
    "suite_version": result["suite_version"],
    "fixture_digest": result["fixture_digest"],
    "summary": result["summary"],
    "specification_ref": sys.argv[2],
    "content_free": True,
}, sort_keys=True))
PY

#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/iicp-operator-capacity.XXXXXX")"
PROJECT="iicp-operator-capacity-$$"
PORT="$(python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()')"
OUTPUT="${1:-$ROOT/reports/operator-capacity-reference.json}"

cleanup() {
  docker compose -p "$PROJECT" -f "$ROOT/compose.operator.yml" down --volumes --remove-orphans >/dev/null 2>&1 || true
  rm -rf "$TMP"
}
trap cleanup EXIT

IICP_OPERATOR_REHEARSAL_DIR="$TMP" IICP_OPERATOR_PROJECT="$PROJECT" \
IICP_OPERATOR_PORT="$PORT" IICP_OPERATOR_REHEARSAL_OUTPUT="$TMP/rehearsal.json" \
  "$ROOT/scripts/rehearse_operator_stack.sh" --keep

docker compose -p "$PROJECT" -f "$ROOT/compose.operator.yml" exec -T db sh -eu -c \
  'exec mariadb -uroot -p"$(cat /run/secrets/db_root_password)" "$MARIADB_DATABASE"' \
  < <("$ROOT/scripts/seed_operator_benchmark.py" --nodes 100)
docker compose -p "$PROJECT" -f "$ROOT/compose.operator.yml" exec -T app php artisan cache:clear >/dev/null
"$ROOT/scripts/benchmark_operator_capacity.py" \
  --base "http://127.0.0.1:$PORT" --output "$OUTPUT" \
  --samples "${IICP_CAPACITY_SAMPLES:-40}" \
  --concurrency "${IICP_CAPACITY_CONCURRENCY:-1,8,32}" \
  ${IICP_CAPACITY_FAIL_ON_ERRORS:+--fail-on-errors}

#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/iicp-operator-capacity.XXXXXX")"
PROJECT="iicp-operator-capacity-$$"
PORT="$(python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()')"
OUTPUT="${1:-$ROOT/reports/operator-capacity-reference.json}"
WORK=""
umask 077

cleanup() {
  local code=$?
  trap - EXIT INT TERM
  set +e
  if [[ -n "$WORK" ]]; then
    python3 "$ROOT/scripts/operator_rehearsal_evidence.py" finish --continuation \
      --work "$WORK" --project "$PROJECT" --mode stack --root "$ROOT" --exit-code "$code"
    exit $?
  fi
  exit "$code"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# The retained result is intentionally non-clean (exit 3). Verify its machine
# evidence before continuing; another exit 3 is not sufficient authorization.
set +e
IICP_OPERATOR_REHEARSAL_DIR="$TMP" IICP_OPERATOR_PROJECT="$PROJECT" \
IICP_OPERATOR_PORT="$PORT" IICP_OPERATOR_REHEARSAL_OUTPUT="$TMP/rehearsal.json" \
  "$ROOT/scripts/rehearse_operator_stack.sh" --keep
rehearsal_code=$?
set -e
if [[ "$rehearsal_code" -ne 3 ]]; then
  [[ "$rehearsal_code" -ne 0 ]] || rehearsal_code=3
  exit "$rehearsal_code"
fi
WORK="$(python3 "$ROOT/scripts/operator_rehearsal_evidence.py" retained --base "$TMP")"
PROJECT="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["project"])' "$WORK/owner.json")"
export IICP_APP_URL="http://127.0.0.1"
export IICP_DB_DATABASE="iicp_directory"
export IICP_DB_USERNAME="iicp_operator"
export IICP_APP_KEY_FILE="$WORK/app_key"
export IICP_DB_PASSWORD_FILE="$WORK/db_password"
export IICP_DB_ROOT_PASSWORD_FILE="$WORK/db_root_password"
export IICP_OPERATOR_PORT="$PORT"
printf 'capacity\n' >"$WORK/phase"

docker compose -p "$PROJECT" -f "$ROOT/compose.operator.yml" exec -T db sh -eu -c \
  'exec mariadb -uroot -p"$(cat /run/secrets/db_root_password)" "$MARIADB_DATABASE"' \
  < <("$ROOT/scripts/seed_operator_benchmark.py" --nodes 100)
docker compose -p "$PROJECT" -f "$ROOT/compose.operator.yml" exec -T app php artisan cache:clear >/dev/null
"$ROOT/scripts/benchmark_operator_capacity.py" \
  --base "http://127.0.0.1:$PORT" --output "$OUTPUT" \
  --samples "${IICP_CAPACITY_SAMPLES:-40}" \
  --concurrency "${IICP_CAPACITY_CONCURRENCY:-1,8,32}" \
  ${IICP_CAPACITY_FAIL_ON_ERRORS:+--fail-on-errors}

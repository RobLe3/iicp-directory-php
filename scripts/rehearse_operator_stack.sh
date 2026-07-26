#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="$ROOT/compose.operator.yml"
TMP="${IICP_OPERATOR_REHEARSAL_DIR:-$(mktemp -d "${TMPDIR:-/tmp}/iicp-operator-rehearsal.XXXXXX")}"
PROJECT="${IICP_OPERATOR_PROJECT:-iicp-operator-rehearsal-$$}"
STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
KEEP=0
SKIP_BUILD=0

for arg in "$@"; do
  case "$arg" in
    --keep) KEEP=1 ;;
    --skip-build) SKIP_BUILD=1 ;;
    *) echo "usage: $0 [--skip-build] [--keep]" >&2; exit 2 ;;
  esac
done

cleanup() {
  if [[ "$KEEP" -eq 0 ]]; then
    docker compose -p "$PROJECT" -f "$COMPOSE_FILE" down --volumes --remove-orphans >/dev/null 2>&1 || true
    rm -rf -- "$TMP"
  else
    echo "kept rehearsal project $PROJECT and evidence $TMP" >&2
  fi
}
trap cleanup EXIT

openssl rand -base64 32 | sed 's/^/base64:/' >"$TMP/app_key"
openssl rand -hex 32 >"$TMP/db_password"
openssl rand -hex 32 >"$TMP/db_root_password"
chmod 0600 "$TMP"/*

export IICP_APP_URL="http://127.0.0.1"
export IICP_DB_DATABASE="iicp_directory"
export IICP_DB_USERNAME="iicp_operator"
export IICP_APP_KEY_FILE="$TMP/app_key"
export IICP_DB_PASSWORD_FILE="$TMP/db_password"
export IICP_DB_ROOT_PASSWORD_FILE="$TMP/db_root_password"
export IICP_IMAGE_TAG="${IICP_IMAGE_TAG:-test}"
export IICP_OPERATOR_PORT
IICP_OPERATOR_PORT="${IICP_OPERATOR_PORT:-$(python3 - <<'PY'
import socket
s = socket.socket()
s.bind(("127.0.0.1", 0))
print(s.getsockname()[1])
s.close()
PY
)}"

compose=(docker compose -p "$PROJECT" -f "$COMPOSE_FILE")

if [[ "$SKIP_BUILD" -eq 0 ]]; then
  "${compose[@]}" build
fi

"${compose[@]}" up -d db
"${compose[@]}" --profile tools run --rm migrate
"${compose[@]}" up -d app scheduler web

wait_ready() {
  local attempts="${1:-60}"
  for ((i = 0; i < attempts; i++)); do
    if curl --fail --silent --max-time 5 \
      "http://127.0.0.1:$IICP_OPERATOR_PORT/iicp/ready" |
      python3 -c 'import json,sys; p=json.load(sys.stdin); assert p == {"ok": True, "role": "directory", "ready": True}' \
      2>/dev/null; then
      return 0
    fi
    sleep 2
  done
  return 1
}
wait_ready

curl --fail --silent --max-time 5 \
  "http://127.0.0.1:$IICP_OPERATOR_PORT/iicp/health" |
  python3 -c 'import json,sys; assert json.load(sys.stdin) == {"ok": True, "role": "directory"}'

# A failed candidate configuration must not replace the running application.
set +e
"${compose[@]}" run --rm -e APP_DEBUG=true app php -r 'exit(0);' >/dev/null 2>&1
bad_candidate_status=$?
set -e
[[ "$bad_candidate_status" -eq 78 ]]
wait_ready 5

# Rehearse database-unavailable behavior and recovery. Readiness is fixed and
# content-free; it may return 503 or the proxy may return 504 while the socket
# timeout elapses, but it must never return a false 200.
"${compose[@]}" stop db >/dev/null
status="$(curl --silent --output "$TMP/unready.json" --write-out '%{http_code}' \
  --max-time 35 "http://127.0.0.1:$IICP_OPERATOR_PORT/iicp/ready" || true)"
[[ "$status" != 200 ]]
"${compose[@]}" start db >/dev/null
wait_ready

# Create a content-free backup, restore it into a new disposable database, and
# prove the restored schema is current using the same one-shot operator image.
"${compose[@]}" exec -T db sh -eu -c \
  'exec mariadb-dump -uroot -p"$(cat /run/secrets/db_root_password)" "$MARIADB_DATABASE"' \
  >"$TMP/backup.sql"
[[ -s "$TMP/backup.sql" ]]
backup_sha256="$(sha256sum "$TMP/backup.sql" | cut -d' ' -f1)"

"${compose[@]}" exec -T db sh -eu -c \
  'mariadb -uroot -p"$(cat /run/secrets/db_root_password)" -e "
     CREATE DATABASE iicp_restore;
     GRANT ALL PRIVILEGES ON iicp_restore.* TO '\''$MARIADB_USER'\''@'\''%'\'';
     FLUSH PRIVILEGES;"'
"${compose[@]}" exec -T db sh -eu -c \
  'exec mariadb -uroot -p"$(cat /run/secrets/db_root_password)" iicp_restore' \
  <"$TMP/backup.sql"
"${compose[@]}" --profile tools run --rm \
  -e DB_DATABASE=iicp_restore migrate \
  php artisan migrate:status --no-interaction >/dev/null

python3 - "$TMP/result.json" "$STARTED_AT" "$backup_sha256" <<'PY'
import json, sys
from datetime import datetime, timezone

path, started, digest = sys.argv[1:]
result = {
    "schema": "iicp.directory.operator-rehearsal.v1",
    "started_at": started,
    "completed_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
    "content_free": True,
    "production_database_used": False,
    "deployment_authorized": False,
    "checks": {
        "clean_migration": True,
        "liveness": True,
        "readiness": True,
        "bad_candidate_rejected": True,
        "database_failure_not_ready": True,
        "database_recovery": True,
        "backup_restore": True,
        "restored_migration_status": True,
    },
    "backup_sha256": digest,
}
with open(path, "w", encoding="utf-8") as handle:
    json.dump(result, handle, indent=2, sort_keys=True)
    handle.write("\n")
print(json.dumps(result, indent=2, sort_keys=True))
PY

if [[ -n "${IICP_OPERATOR_REHEARSAL_OUTPUT:-}" ]]; then
  cp "$TMP/result.json" "$IICP_OPERATOR_REHEARSAL_OUTPUT"
  chmod 0600 "$IICP_OPERATOR_REHEARSAL_OUTPUT"
fi

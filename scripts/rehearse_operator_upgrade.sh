#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="$ROOT/compose.operator.yml"
BASE="${IICP_OPERATOR_UPGRADE_DIR:-$(python3 -c 'import tempfile,pathlib; print(pathlib.Path(tempfile.gettempdir()).resolve())')}"
PROJECT="${IICP_OPERATOR_UPGRADE_PROJECT:-iicp-operator-upgrade-$$}"
OUTPUT="${IICP_OPERATOR_UPGRADE_OUTPUT:-}"
STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
PREVIOUS_TAG=""
NEXT_TAG=""
KEEP=0
PREBUILT_MANIFEST=""
MANIFEST_SHA256=""
PREVIOUS_SOURCE=""
NEXT_SOURCE=""
PREBUILT_JSON=""

usage() {
  echo "usage: $0 (--previous-tag TAG --next-tag TAG | --prebuilt-manifest FILE --manifest-sha256 HEX --previous-source SHA --next-source SHA) [--keep]" >&2
}

while [[ "$#" -gt 0 ]]; do
  case "$1" in
    --previous-tag) PREVIOUS_TAG="${2:-}"; shift 2 ;;
    --next-tag) NEXT_TAG="${2:-}"; shift 2 ;;
    --prebuilt-manifest) PREBUILT_MANIFEST="${2:-}"; shift 2 ;;
    --manifest-sha256) MANIFEST_SHA256="${2:-}"; shift 2 ;;
    --previous-source) PREVIOUS_SOURCE="${2:-}"; shift 2 ;;
    --next-source) NEXT_SOURCE="${2:-}"; shift 2 ;;
    --keep) KEEP=1; shift ;;
    *) usage; exit 2 ;;
  esac
done

if [[ -n "$PREBUILT_MANIFEST" ]]; then
  [[ -z "$PREVIOUS_TAG$NEXT_TAG" ]] || { usage; exit 2; }
  PREBUILT_JSON="$(python3 "$ROOT/scripts/operator_prebuilt_inputs.py" \
    --manifest "$PREBUILT_MANIFEST" --manifest-sha256 "$MANIFEST_SHA256" \
    --previous-source "$PREVIOUS_SOURCE" --next-source "$NEXT_SOURCE")"
  read -r PREVIOUS_TAG NEXT_TAG <<<"$(printf '%s' "$PREBUILT_JSON" | python3 -c 'import json,sys; m=json.load(sys.stdin)["manifest"]; print("v"+m["previous"]["version"], "v"+m["next"]["version"])')"
else
  [[ -z "$MANIFEST_SHA256$PREVIOUS_SOURCE$NEXT_SOURCE" ]] || { usage; exit 2; }
  [[ "$PREVIOUS_TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?$ ]] || { usage; exit 2; }
  [[ "$NEXT_TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?$ ]] || { usage; exit 2; }
  [[ "$PREVIOUS_TAG" != "$NEXT_TAG" ]] || { echo "release tags must differ" >&2; exit 2; }
  git -C "$ROOT" cat-file -e "$PREVIOUS_TAG^{commit}"
  git -C "$ROOT" cat-file -e "$NEXT_TAG^{commit}"

fi

umask 077
PROJECT="${PROJECT}-$(python3 -c 'import secrets; print(secrets.token_hex(6))')"
TMP="$(python3 "$ROOT/scripts/operator_rehearsal_evidence.py" prepare --base "$BASE" --project "$PROJECT" --mode upgrade)"
cleanup() {
  local code=$?
  trap - EXIT INT TERM
  local keep_arg=""
  [[ "$KEEP" -eq 0 ]] || keep_arg="--keep"
  set +e
  IICP_IMAGE_TAG="$NEXT_TAG" python3 "$ROOT/scripts/operator_rehearsal_evidence.py" finish \
    --work "$TMP" --project "$PROJECT" --mode upgrade --root "$ROOT" \
    --exit-code "$code" ${keep_arg:+"$keep_arg"}
  exit $?
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
phase() { printf '%s\n' "$1" >"$TMP/phase"; }
phase prepare

if [[ -n "$PREBUILT_JSON" ]]; then
  printf '%s' "$PREBUILT_JSON" | PYTHONPATH="$ROOT/scripts" python3 -c '
import json,sys
from pathlib import Path
from operator_prebuilt_inputs import write_overrides
from operator_rehearsal_evidence import write_json
value=json.load(sys.stdin); work=Path(sys.argv[1])
write_json(Path(str(work)+".evidence")/"prebuilt-inputs.json", value)
write_overrides(work, value["manifest"])
' "$TMP"
else
  phase build
  git -C "$ROOT" worktree add --detach "$TMP/previous" "$PREVIOUS_TAG" >/dev/null
  git -C "$ROOT" worktree add --detach "$TMP/next" "$NEXT_TAG" >/dev/null

  docker build -f "$TMP/previous/Dockerfile.operator" \
    -t "iicp-directory-operator:$PREVIOUS_TAG" "$TMP/previous"
  docker build -f "$TMP/previous/Dockerfile.operator-nginx" \
    -t "iicp-directory-operator-nginx:$PREVIOUS_TAG" "$TMP/previous"
  docker build -f "$TMP/next/Dockerfile.operator" \
    -t "iicp-directory-operator:$NEXT_TAG" "$TMP/next"
  docker build -f "$TMP/next/Dockerfile.operator-nginx" \
    -t "iicp-directory-operator-nginx:$NEXT_TAG" "$TMP/next"

fi

openssl rand -base64 32 | sed 's/^/base64:/' >"$TMP/app_key"
openssl rand -hex 32 >"$TMP/db_password"
openssl rand -hex 32 >"$TMP/db_root_password"
chmod 0600 "$TMP/app_key" "$TMP/db_password" "$TMP/db_root_password"

export IICP_APP_URL="http://127.0.0.1"
export IICP_DB_DATABASE="iicp_directory"
export IICP_DB_USERNAME="iicp_operator"
export IICP_APP_KEY_FILE="$TMP/app_key"
export IICP_DB_PASSWORD_FILE="$TMP/db_password"
export IICP_DB_ROOT_PASSWORD_FILE="$TMP/db_root_password"
export IICP_OPERATOR_PORT="${IICP_OPERATOR_PORT:-$(python3 - <<'PY'
import socket
s = socket.socket()
s.bind(("127.0.0.1", 0))
print(s.getsockname()[1])
s.close()
PY
)}"

compose() {
  local tag="$1"
  shift
  if [[ -n "$PREBUILT_JSON" ]]; then
    IICP_IMAGE_TAG="$tag" docker compose -p "$PROJECT" -f "$COMPOSE_FILE" -f "$TMP/$tag.yml" "$@"
  else
    IICP_IMAGE_TAG="$tag" docker compose -p "$PROJECT" -f "$COMPOSE_FILE" "$@"
  fi
}

wait_ready() {
  for ((attempt = 0; attempt < 90; attempt++)); do
    if curl --fail --silent --max-time 5 \
      "http://127.0.0.1:$IICP_OPERATOR_PORT/iicp/ready" |
      python3 -c 'import json,sys; assert json.load(sys.stdin) == {"ok": True, "role": "directory", "ready": True}' \
      2>/dev/null; then
      return 0
    fi
    sleep 2
  done
  return 1
}

container_version() {
  compose "$1" exec -T app cat /app/VERSION | tr -d '\r\n'
}

# Resolve both complete models before any service starts. In prebuilt mode the
# override removes build instructions and forbids pulling all six services.
compose "$PREVIOUS_TAG" config --quiet
compose "$NEXT_TAG" config --quiet

phase previous_runtime
compose "$PREVIOUS_TAG" up -d db
compose "$PREVIOUS_TAG" --profile tools run --rm migrate
compose "$PREVIOUS_TAG" up -d app scheduler web
wait_ready
[[ "$(container_version "$PREVIOUS_TAG")" == "${PREVIOUS_TAG#v}" ]]

compose "$PREVIOUS_TAG" exec -T db sh -eu -c \
  'exec mariadb-dump -uroot -p"$(cat /run/secrets/db_root_password)" "$MARIADB_DATABASE"' \
  >"$TMP/pre-upgrade.sql"
[[ -s "$TMP/pre-upgrade.sql" ]]
backup_sha256="$(sha256sum "$TMP/pre-upgrade.sql" | cut -d' ' -f1)"

phase upgrade
compose "$NEXT_TAG" --profile tools run --rm migrate
compose "$NEXT_TAG" up -d --no-deps --force-recreate app scheduler web
wait_ready
[[ "$(container_version "$NEXT_TAG")" == "${NEXT_TAG#v}" ]]

phase rollback
compose "$NEXT_TAG" stop web scheduler app >/dev/null
compose "$NEXT_TAG" exec -T db sh -eu -c \
  'mariadb -uroot -p"$(cat /run/secrets/db_root_password)" -e "
     DROP DATABASE IF EXISTS \`$MARIADB_DATABASE\`;
     CREATE DATABASE \`$MARIADB_DATABASE\`;
     GRANT ALL PRIVILEGES ON \`$MARIADB_DATABASE\`.* TO '\''$MARIADB_USER'\''@'\''%'\'';
     FLUSH PRIVILEGES;"'
compose "$NEXT_TAG" exec -T db sh -eu -c \
  'exec mariadb -uroot -p"$(cat /run/secrets/db_root_password)" "$MARIADB_DATABASE"' \
  <"$TMP/pre-upgrade.sql"

compose "$PREVIOUS_TAG" up -d --no-deps --force-recreate app scheduler web
wait_ready
[[ "$(container_version "$PREVIOUS_TAG")" == "${PREVIOUS_TAG#v}" ]]
compose "$PREVIOUS_TAG" --profile tools run --rm \
  migrate php artisan migrate:status --no-interaction >/dev/null

phase forward_recovery
compose "$NEXT_TAG" --profile tools run --rm migrate
compose "$NEXT_TAG" up -d --no-deps --force-recreate app scheduler web
wait_ready
[[ "$(container_version "$NEXT_TAG")" == "${NEXT_TAG#v}" ]]

phase result
python3 - "$TMP/result.json" "$STARTED_AT" "$PREVIOUS_TAG" "$NEXT_TAG" "$backup_sha256" <<'PY'
import json
import sys
from datetime import datetime, timezone

path, started, previous, next_tag, digest = sys.argv[1:]
result = {
    "schema": "iicp.directory.operator-upgrade-rehearsal.v1",
    "started_at": started,
    "completed_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
    "content_free": True,
    "production_database_used": False,
    "deployment_authorized": False,
    "previous_tag": previous,
    "next_tag": next_tag,
    "backup_sha256": digest,
    "checks": {
        "previous_clean_start": True,
        "pre_upgrade_backup": True,
        "next_one_shot_migration": True,
        "next_readiness": True,
        "database_restore": True,
        "previous_image_rollback": True,
        "previous_migration_status": True,
        "next_forward_recovery": True,
    },
}
with open(path, "w", encoding="utf-8") as handle:
    json.dump(result, handle, indent=2, sort_keys=True)
    handle.write("\n")
print(json.dumps(result, indent=2, sort_keys=True))
PY

if [[ -n "$OUTPUT" ]]; then
  python3 "$ROOT/scripts/operator_rehearsal_evidence.py" export --work "$TMP" \
    --project "$PROJECT" --mode upgrade --output "$OUTPUT"
fi

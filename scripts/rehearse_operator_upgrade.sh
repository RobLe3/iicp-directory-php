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
INTERRUPT_AT=""
SDK_PROBE_IMAGE=""

usage() {
  echo "usage: $0 (--previous-tag TAG --next-tag TAG | --prebuilt-manifest FILE --manifest-sha256 HEX --previous-source SHA --next-source SHA) [--sdk-probe-image sha256:IMAGE_ID] [--keep] [--interrupt-at before-migration|after-migration|after-activation]" >&2
}

while [[ "$#" -gt 0 ]]; do
  case "$1" in
    --previous-tag) PREVIOUS_TAG="${2:-}"; shift 2 ;;
    --next-tag) NEXT_TAG="${2:-}"; shift 2 ;;
    --prebuilt-manifest) PREBUILT_MANIFEST="${2:-}"; shift 2 ;;
    --manifest-sha256) MANIFEST_SHA256="${2:-}"; shift 2 ;;
    --previous-source) PREVIOUS_SOURCE="${2:-}"; shift 2 ;;
    --next-source) NEXT_SOURCE="${2:-}"; shift 2 ;;
    --interrupt-at) INTERRUPT_AT="${2:-}"; shift 2 ;;
    --sdk-probe-image) SDK_PROBE_IMAGE="${2:-}"; shift 2 ;;
    --keep) KEEP=1; shift ;;
    *) usage; exit 2 ;;
  esac
done

if [[ -n "$SDK_PROBE_IMAGE" ]]; then
  [[ -n "$PREBUILT_MANIFEST" && "$KEEP" -eq 0 ]] || { usage; exit 2; }
  [[ "$SDK_PROBE_IMAGE" =~ ^sha256:[0-9a-f]{64}$ ]] || { usage; exit 2; }
  # Only a preloaded content-addressed Linux amd64 image; no pull or build.
  [[ "$(docker image inspect --format '{{.Id}} {{.Os}} {{.Architecture}}' "$SDK_PROBE_IMAGE")" == "$SDK_PROBE_IMAGE linux amd64" ]] || exit 2
  export IICP_SDK_PROBE_IMAGE="$SDK_PROBE_IMAGE"
fi

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

if [[ -n "$INTERRUPT_AT" ]]; then
  [[ -n "$PREBUILT_MANIFEST" ]] || { usage; exit 2; }
  case "$INTERRUPT_AT" in
    before-migration|after-migration|after-activation) ;;
    *) usage; exit 2 ;;
  esac
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
  local files=(-f "$COMPOSE_FILE")
  if [[ -n "$PREBUILT_JSON" ]]; then files+=(-f "$TMP/$tag.yml"); fi
  if [[ -n "$SDK_PROBE_IMAGE" ]]; then files+=(-f "$ROOT/compose.operator-sdk-test.yml"); fi
  IICP_IMAGE_TAG="$tag" docker compose -p "$PROJECT" "${files[@]}" "$@"
}

ready_json() {
  if [[ -n "$PREBUILT_JSON" ]]; then
    compose "$1" exec -T web wget -q -T 5 -O - http://127.0.0.1:8080/iicp/ready
  else
    curl --fail --silent --max-time 5 "http://127.0.0.1:$IICP_OPERATOR_PORT/iicp/ready"
  fi
}

wait_ready() {
  for ((attempt = 0; attempt < 90; attempt++)); do
    if ready_json "$1" |
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

# Synthetic state is intentionally separate from Directory API conformance.
# It proves that upgrade/restore preserves persistent rows, not registration semantics.
fixture_sql() {
  compose "$1" exec -T db sh -eu -c \
    'exec mariadb --batch --skip-column-names -uroot -p"$(cat /run/secrets/db_root_password)" "$MARIADB_DATABASE"'
}
verify_fixture() {
  local digest
  digest="$(printf '%s\n' "SELECT SHA2(GROUP_CONCAT(CONCAT(id, ':', marker) ORDER BY id SEPARATOR '|'), 256) FROM iicp_rehearsal_fixture;" | fixture_sql "$1" | tr -d '\r\n')"
  [[ "$digest" == "$FIXTURE_SHA256" ]] || return 1
  # One exclusive content-free checkpoint survives normal workspace cleanup.
  python3 - "$TMP.evidence/fixture-$2.json" "$digest" <<'PYFIXTURE'
import json, os, sys
path, digest = sys.argv[1:]
fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
with os.fdopen(fd, "w") as output:
    json.dump({"schema": "iicp.directory.synthetic-persistence.v1", "sha256": digest,
               "row_count": 2, "verified": True, "qualification_credit": 0}, output)
    output.write("\n")
PYFIXTURE
}
FIXTURE_SHA256="$(python3 -c 'import hashlib; print(hashlib.sha256(b"1:alpha|2:beta").hexdigest())')"

# Declared application-service interruption, not a simulated host power loss.
interrupt_services() {
  [[ "$INTERRUPT_AT" == "$1" ]] || return 0
  compose "$2" stop --timeout 0 web scheduler app >/dev/null
  local running
  running="$(compose "$2" ps --status running --quiet app scheduler web)"
  [[ -z "$running" ]] || return 1
  python3 - "$TMP.evidence/interruption.json" "$1" <<'PYINTERRUPT'
import json, os, sys
fd = os.open(sys.argv[1], os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
with os.fdopen(fd, "w") as output:
    json.dump({"schema": "iicp.directory.application-interruption.v1",
               "checkpoint": sys.argv[2], "services_stopped": True,
               "host_failure_simulated": False, "qualification_credit": 0}, output)
    output.write("\n")
PYINTERRUPT
}

# Resolve both complete models before any service starts. In prebuilt mode the
# override removes build instructions and forbids pulling all six services.
compose "$PREVIOUS_TAG" config --quiet
compose "$NEXT_TAG" config --quiet

phase previous_runtime
compose "$PREVIOUS_TAG" up -d db
compose "$PREVIOUS_TAG" --profile tools run --rm migrate
compose "$PREVIOUS_TAG" up -d app scheduler web
wait_ready "$PREVIOUS_TAG"
[[ "$(container_version "$PREVIOUS_TAG")" == "${PREVIOUS_TAG#v}" ]] || exit 1
fixture_sql "$PREVIOUS_TAG" <<'SQL'
CREATE TABLE iicp_rehearsal_fixture (id INT PRIMARY KEY, marker VARCHAR(16) NOT NULL);
INSERT INTO iicp_rehearsal_fixture VALUES (1, 'alpha'), (2, 'beta');
SQL
verify_fixture "$PREVIOUS_TAG" previous


compose "$PREVIOUS_TAG" exec -T db sh -eu -c \
  'exec mariadb-dump -uroot -p"$(cat /run/secrets/db_root_password)" "$MARIADB_DATABASE"' \
  >"$TMP/pre-upgrade.sql"
[[ -s "$TMP/pre-upgrade.sql" ]] || exit 1
backup_sha256="$(sha256sum "$TMP/pre-upgrade.sql" | cut -d' ' -f1)"

phase upgrade
interrupt_services before-migration "$PREVIOUS_TAG"
compose "$NEXT_TAG" --profile tools run --rm migrate
interrupt_services after-migration "$PREVIOUS_TAG"
if [[ -n "$SDK_PROBE_IMAGE" ]]; then compose "$NEXT_TAG" rm -sf web scheduler; fi
compose "$NEXT_TAG" up -d --no-deps --force-recreate app scheduler web
wait_ready "$NEXT_TAG"
[[ "$(container_version "$NEXT_TAG")" == "${NEXT_TAG#v}" ]] || exit 1
verify_fixture "$NEXT_TAG" upgrade
interrupt_services after-activation "$NEXT_TAG"
if [[ "$INTERRUPT_AT" == "after-activation" ]]; then
  compose "$NEXT_TAG" up -d --no-deps app scheduler web
  wait_ready "$NEXT_TAG"
  verify_fixture "$NEXT_TAG" restarted
fi

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

if [[ -n "$SDK_PROBE_IMAGE" ]]; then compose "$NEXT_TAG" rm -sf web scheduler; fi
compose "$PREVIOUS_TAG" up -d --no-deps --force-recreate app scheduler web
wait_ready "$PREVIOUS_TAG"
[[ "$(container_version "$PREVIOUS_TAG")" == "${PREVIOUS_TAG#v}" ]] || exit 1
verify_fixture "$PREVIOUS_TAG" rollback
compose "$PREVIOUS_TAG" --profile tools run --rm \
  migrate php artisan migrate:status --no-interaction >/dev/null

phase forward_recovery
compose "$NEXT_TAG" --profile tools run --rm migrate
if [[ -n "$SDK_PROBE_IMAGE" ]]; then compose "$NEXT_TAG" rm -sf web scheduler; fi
compose "$NEXT_TAG" up -d --no-deps --force-recreate app scheduler web
wait_ready "$NEXT_TAG"
[[ "$(container_version "$NEXT_TAG")" == "${NEXT_TAG#v}" ]] || exit 1
verify_fixture "$NEXT_TAG" forward

if [[ -n "$SDK_PROBE_IMAGE" ]]; then
  phase sdk_compatibility
  # Entry point emits bounded content-free JSON, validates all 18 rows, and
  # exits nonzero for partial/fixture-only evidence. EXIT trap owns DB cleanup.
  compose "$NEXT_TAG" --profile sdk-test up -d --no-deps sdk-probe
  python3 "$ROOT/scripts/operator_sdk_probe.py" \
    --container "$(compose "$NEXT_TAG" --profile sdk-test ps --all --quiet sdk-probe)" \
    --image "$SDK_PROBE_IMAGE" --output "$TMP.evidence" \
    --app "$(compose "$NEXT_TAG" ps --all --quiet app)" --project "$PROJECT"
fi

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

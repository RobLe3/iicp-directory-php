#!/usr/bin/env bash
set -euo pipefail

APP_IMAGE="${1:-iicp-directory-operator:test}"
NGINX_IMAGE="${2:-iicp-directory-operator-nginx:test}"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/iicp-operator-image-test.XXXXXX")"
trap 'rm -rf -- "$TMP"' EXIT

printf 'base64:Kys8RTJYcEp4RUhxNHJNMm9OeEdUVm1BcUZYWmJtR2lKdkhhSlh3WG40PQ==\n' >"$TMP/app_key"
printf 'disposable-password\n' >"$TMP/db_password"
chmod 0644 "$TMP/app_key" "$TMP/db_password"

[[ "$(docker image inspect "$APP_IMAGE" --format '{{.Config.User}}')" == "10001:10001" ]]
[[ "$(docker image inspect "$NGINX_IMAGE" --format '{{.Config.User}}')" == "101:101" ]]

common=(
  --rm
  --read-only
  --tmpfs /tmp:rw,noexec,nosuid,size=16m
  --mount type=volume,destination=/app/bootstrap/cache
  --mount type=volume,destination=/app/storage
  --env APP_ENV=production
  --env APP_DEBUG=false
  --env APP_URL=https://directory.invalid
  --env DB_HOST=db
  --env DB_DATABASE=iicp
  --env DB_USERNAME=iicp
  --env DB_PASSWORD_FILE=/run/secrets/db_password
  --mount type=bind,source="$TMP/db_password",destination=/run/secrets/db_password,readonly
)

set +e
docker run "${common[@]}" "$APP_IMAGE" php -r 'exit(0);' >/dev/null 2>&1
missing_key_status=$?
set -e
[[ "$missing_key_status" -eq 78 ]] || {
  echo "operator image did not fail closed on a missing APP_KEY" >&2
  exit 1
}

docker run "${common[@]}" \
  --env APP_KEY_FILE=/run/secrets/app_key \
  --mount type=bind,source="$TMP/app_key",destination=/run/secrets/app_key,readonly \
  "$APP_IMAGE" php -r 'exit(str_starts_with((string) getenv("APP_KEY"), "base64:") ? 0 : 1);'

if docker history --no-trunc "$APP_IMAGE" |
  grep -Eq 'disposable-password|Kys8RTJYcEp4RUhx'; then
  echo "operator image history contains test secret material" >&2
  exit 1
fi

echo "operator image runtime contract: PASS"

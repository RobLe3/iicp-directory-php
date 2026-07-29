#!/usr/bin/env bash
# Structural contract for the minimized shared-hosting runtime artifact.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/iicp-runtime-artifact.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT
ARTIFACT="$WORK/artifact"

"$ROOT/scripts/materialize_shared_hosting_runtime.sh" "$ARTIFACT" >/dev/null

required=(
  app/Http/Controllers/RegisterController.php
  bootstrap/app.php
  bootstrap/providers.php
  config/app.php
  database/migrations
  public/index.php
  resources/views/welcome.blade.php
  routes/api.php
  routes/web.php
  artisan
  composer.json
  composer.lock
  LICENSE
  VERSION
  bootstrap/cache
  storage/app/private
  storage/framework/cache/data
  storage/framework/sessions
  storage/framework/testing
  storage/framework/views
  storage/logs
)
for path in "${required[@]}"; do
  [[ -e "$ARTIFACT/$path" ]] || {
    echo "required runtime path is missing: $path" >&2
    exit 1
  }
done

forbidden=(
  .git
  .github
  .env
  .env.example
  .env.testing
  build
  contracts
  docs
  operator
  ops
  parity
  releases
  reports
  scripts
  spec
  tests
  tom
  Dockerfile
  compose.operator.yml
  infection.json5
  openapi.yaml
  package.json
  phpstan-baseline.neon
  phpstan.neon.dist
  phpunit.xml
  public/.htaccess.template
  resources/css
  resources/js
  vite.config.js
)
for path in "${forbidden[@]}"; do
  [[ ! -e "$ARTIFACT/$path" ]] || {
    echo "forbidden source-review/development path entered runtime artifact: $path" >&2
    exit 1
  }
done

expected_top_level=$'LICENSE\nVERSION\napp\nartisan\nbootstrap\ncomposer.json\ncomposer.lock\nconfig\ndatabase\npublic\nresources\nroutes\nstorage'
actual_top_level="$(find "$ARTIFACT" -mindepth 1 -maxdepth 1 -exec basename {} \; | sort)"
[[ "$actual_top_level" == "$expected_top_level" ]] || {
  echo "unexpected runtime artifact top-level layout:" >&2
  comm -3 <(printf '%s\n' "$expected_top_level") <(printf '%s\n' "$actual_top_level") >&2
  exit 1
}

php -l "$ARTIFACT/artisan" >/dev/null
composer validate --no-check-publish --working-dir="$ARTIFACT" >/dev/null

if [[ "${IICP_RUNTIME_ARTIFACT_COMPOSER_INSTALL:-0}" == "1" ]]; then
  composer install \
    --working-dir="$ARTIFACT" \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-progress
  [[ -f "$ARTIFACT/vendor/autoload.php" ]]
  database="$WORK/runtime.sqlite"
  : > "$database"
  APP_ENV=testing \
    APP_KEY='00000000000000000000000000000000' \
    DB_CONNECTION=sqlite \
    DB_DATABASE="$database" \
    "$ARTIFACT/artisan" migrate:fresh --force >/dev/null
  APP_ENV=testing \
    APP_KEY='00000000000000000000000000000000' \
    DB_CONNECTION=sqlite \
    DB_DATABASE="$database" \
    "$ARTIFACT/artisan" route:list --json >/dev/null
fi

echo "shared-hosting runtime artifact contract: PASS"

#!/bin/sh
# SPDX-License-Identifier: Apache-2.0
# Testbed entrypoint for docker-compose.federation.yml ONLY.
#
# `php artisan serve` (used by the testbed) does NOT forward arbitrary container
# environment variables to the request-handling subprocess. Any value the app reads
# via env() at request time therefore has to be written into .env, or it reads as
# null inside an HTTP request. Two things broke because of this:
#   - IICP_GENESIS_ED25519_SECRET_KEY → seed emitted unsigned (sig:null) events
#   - IICP_DEV_ALLOW_HTTP_DID         → seed rejected testbed replicas with IICP-E043
#
# Production does NOT use this entrypoint: it runs under php-fpm (env passes through
# per request) via the deploy/ pipeline. This script is a testbed convenience only.
set -e

# Upsert KEY=VALUE into .env (skip when the value is empty). `#` delimiter avoids
# clashing with `/`, `:`, `=`, `+` that appear in URLs / base64 keys.
upsert() {
    key="$1"
    val="$2"
    [ -z "$val" ] && return 0
    if grep -q "^${key}=" .env; then
        sed -i "s#^${key}=.*#${key}=${val}#" .env
    else
        echo "${key}=${val}" >> .env
    fi
}

upsert DB_HOST "${DB_HOST:-localhost}"
upsert DB_DATABASE "${DB_DATABASE:-iicp_directory}"
upsert DB_USERNAME "${DB_USERNAME:-iicp_dir_user}"
upsert DB_PASSWORD "${DB_PASSWORD:-iicp_dir_pass}"
upsert APP_ENV "${APP_ENV}"
upsert APP_KEY "${APP_KEY}"
upsert APP_URL "${APP_URL}"
upsert IICP_DEV_ALLOW_HTTP_DID "${IICP_DEV_ALLOW_HTTP_DID}"
upsert IICP_GENESIS_ED25519_SECRET_KEY "${IICP_GENESIS_ED25519_SECRET_KEY}"
upsert IICP_REPLICA_ED25519_SECRET_KEY "${IICP_REPLICA_ED25519_SECRET_KEY}"
upsert IICP_REPLICA_MODE "${IICP_REPLICA_MODE}"
upsert IICP_SEED_URL "${IICP_SEED_URL}"
upsert IICP_SKIP_LIVENESS_CHECK "${IICP_SKIP_LIVENESS_CHECK}"
upsert IICP_DISCOVERY_PROFILE "${IICP_DISCOVERY_PROFILE}"

# DID document contains '#key-1' which breaks the '#'-delimited sed in upsert().
# Write it verbatim with single-quote quoting (JSON contains no single quotes).
if [ -n "$IICP_DEV_DID_DOCUMENT_JSON" ] && ! grep -q '^IICP_DEV_DID_DOCUMENT_JSON=' .env; then
    printf "IICP_DEV_DID_DOCUMENT_JSON='%s'\n" "$(printf '%s' "$IICP_DEV_DID_DOCUMENT_JSON" | tr -d '\n')" >> .env
fi

php artisan config:clear
php artisan migrate --force
exec php artisan serve --host=0.0.0.0 --port=8080

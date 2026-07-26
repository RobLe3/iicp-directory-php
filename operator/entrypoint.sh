#!/bin/sh
set -eu

load_secret() {
    name="$1"
    required="$2"
    eval "file=\${${name}_FILE:-}"
    eval "value=\${${name}:-}"

    if [ -n "$file" ]; then
        [ -f "$file" ] || {
            echo "operator configuration error: ${name}_FILE is unreadable" >&2
            exit 78
        }
        value="$(cat "$file")"
        export "$name=$value"
    fi

    if [ "$required" = required ] && [ -z "$value" ]; then
        echo "operator configuration error: $name is required" >&2
        exit 78
    fi
}

require_value() {
    name="$1"
    eval "value=\${${name}:-}"
    [ -n "$value" ] || {
        echo "operator configuration error: $name is required" >&2
        exit 78
    }
}

load_secret APP_KEY required
load_secret DB_PASSWORD required
load_secret IICP_DEPLOY_SECRET optional
load_secret IICP_GENESIS_ED25519_SECRET_KEY optional
load_secret GENESIS_SEED_SECRET_KEY optional

require_value APP_URL
require_value DB_HOST
require_value DB_DATABASE
require_value DB_USERNAME

[ "${APP_ENV:-}" = production ] || {
    echo "operator configuration error: APP_ENV must be production" >&2
    exit 78
}
[ "${APP_DEBUG:-false}" = false ] || {
    echo "operator configuration error: APP_DEBUG must be false" >&2
    exit 78
}

case "${APP_KEY:-}" in
    base64:*) ;;
    *) echo "operator configuration error: APP_KEY must use Laravel base64 format" >&2; exit 78 ;;
esac

for path in bootstrap/cache storage; do
    [ -w "$path" ] || {
        echo "operator configuration error: $path is not writable" >&2
        exit 78
    }
done

php artisan config:cache >/dev/null
exec "$@"

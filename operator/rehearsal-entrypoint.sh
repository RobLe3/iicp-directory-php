#!/bin/sh
set -eu

# This entry point exists only for the isolated SDK rehearsal overlay.  The
# production entry point deliberately refuses APP_ENV=testing; bypassing it
# entirely also bypassed Docker-secret loading and left Laravel without an
# application key.
[ "${IICP_OPERATOR_REHEARSAL:-}" = 1 ] || {
    echo "operator rehearsal configuration error: explicit rehearsal mode required" >&2
    exit 78
}
[ "${APP_ENV:-}" = testing ] || {
    echo "operator rehearsal configuration error: APP_ENV must be testing" >&2
    exit 78
}
[ "${APP_URL:-}" = http://127.0.0.1 ] || {
    echo "operator rehearsal configuration error: loopback APP_URL required" >&2
    exit 78
}

load_secret() {
    name="$1"
    eval "file=\${${name}_FILE:-}"
    eval "value=\${${name}:-}"
    if [ -n "$file" ]; then
        [ -f "$file" ] || {
            echo "operator rehearsal configuration error: ${name}_FILE is unreadable" >&2
            exit 78
        }
        value="$(cat "$file")"
        export "$name=$value"
    fi
    [ -n "$value" ] || {
        echo "operator rehearsal configuration error: $name is required" >&2
        exit 78
    }
}

load_secret APP_KEY
load_secret DB_PASSWORD
case "$APP_KEY" in
    base64:*) ;;
    *) echo "operator rehearsal configuration error: APP_KEY must use Laravel base64 format" >&2; exit 78 ;;
esac

for path in bootstrap/cache storage; do
    [ -w "$path" ] || {
        echo "operator rehearsal configuration error: $path is not writable" >&2
        exit 78
    }
done

php artisan config:cache >/dev/null
exec "$@"

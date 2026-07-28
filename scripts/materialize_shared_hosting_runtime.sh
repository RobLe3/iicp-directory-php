#!/usr/bin/env bash
# Copy only the source files required by the shared-hosting Laravel runtime.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DESTINATION="${1:-}"
ALLOWLIST="${IICP_RUNTIME_ALLOWLIST:-$ROOT/ops/shared-hosting-runtime-allowlist.txt}"

[[ -n "$DESTINATION" ]] || {
  echo "usage: $0 DESTINATION" >&2
  exit 2
}
[[ -f "$ALLOWLIST" ]] || {
  echo "runtime allowlist is missing: $ALLOWLIST" >&2
  exit 2
}
[[ ! -e "$DESTINATION" ]] || {
  echo "runtime destination must not already exist: $DESTINATION" >&2
  exit 2
}

mkdir -p "$DESTINATION"
rsync -a --prune-empty-dirs \
  --include='*/' \
  --include-from="$ALLOWLIST" --exclude='*' \
  "$ROOT/" "$DESTINATION/"

mkdir -p \
  "$DESTINATION/bootstrap/cache" \
  "$DESTINATION/storage/app/private" \
  "$DESTINATION/storage/framework/cache/data" \
  "$DESTINATION/storage/framework/sessions" \
  "$DESTINATION/storage/framework/testing" \
  "$DESTINATION/storage/framework/views" \
  "$DESTINATION/storage/logs"

echo "Shared-hosting runtime materialized from reviewed allowlist"

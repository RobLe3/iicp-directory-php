#!/usr/bin/env bash
# Copy only the source files required by the shared-hosting Laravel runtime.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCE="${1:-$ROOT}"
DESTINATION="${2:-}"
ALLOWLIST="${IICP_RUNTIME_ALLOWLIST:-$ROOT/ops/shared-hosting-runtime-allowlist.txt}"

if [[ -z "$DESTINATION" ]]; then
  echo "usage: $0 [SOURCE] DESTINATION" >&2
  exit 2
fi
[[ -d "$SOURCE" ]] || { echo "runtime source is not a directory: $SOURCE" >&2; exit 2; }
[[ -f "$ALLOWLIST" ]] || { echo "runtime allowlist is missing: $ALLOWLIST" >&2; exit 2; }

SOURCE="$(cd "$SOURCE" && pwd -P)"
mkdir -p "$DESTINATION"
DESTINATION="$(cd "$DESTINATION" && pwd -P)"

if [[ "$DESTINATION" == "$SOURCE" || "$DESTINATION" == "$SOURCE/"* ]]; then
  echo "runtime destination must be outside the source tree" >&2
  exit 2
fi
if find "$DESTINATION" -mindepth 1 -print -quit | grep -q .; then
  echo "runtime destination must be empty: $DESTINATION" >&2
  exit 2
fi

copied=0
while IFS= read -r raw || [[ -n "$raw" ]]; do
  entry="${raw%$'\r'}"
  [[ -z "$entry" || "$entry" == \#* ]] && continue
  if [[ "$entry" == /* || "$entry" == *".."* ]]; then
    echo "unsafe runtime allowlist entry: $entry" >&2
    exit 2
  fi

  source_path="$SOURCE/${entry%/}"
  [[ -e "$source_path" ]] || {
    echo "runtime allowlist entry is missing from source: $entry" >&2
    exit 1
  }

  if [[ "$entry" == */ ]]; then
    [[ -d "$source_path" ]] || {
      echo "runtime allowlist directory is not a directory: $entry" >&2
      exit 1
    }
    mkdir -p "$DESTINATION/${entry%/}"
    rsync -a "$source_path/" "$DESTINATION/${entry%/}/"
  else
    [[ -f "$source_path" ]] || {
      echo "runtime allowlist file is not a file: $entry" >&2
      exit 1
    }
    mkdir -p "$(dirname "$DESTINATION/$entry")"
    cp -p "$source_path" "$DESTINATION/$entry"
  fi
  copied=$((copied + 1))
done < "$ALLOWLIST"

mkdir -p \
  "$DESTINATION/bootstrap/cache" \
  "$DESTINATION/storage/app/private" \
  "$DESTINATION/storage/framework/cache/data" \
  "$DESTINATION/storage/framework/sessions" \
  "$DESTINATION/storage/framework/testing" \
  "$DESTINATION/storage/framework/views" \
  "$DESTINATION/storage/logs"

printf 'Shared-hosting runtime materialized: %d allowlist entries\n' "$copied"

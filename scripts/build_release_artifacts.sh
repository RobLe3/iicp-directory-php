#!/usr/bin/env bash
# Build deterministic, content-free public source release artifacts.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-}"
OUT="${2:-$ROOT/dist}"

[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?$ ]] || {
  echo "usage: $0 VERSION [OUTPUT_DIRECTORY]" >&2
  exit 2
}

mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
ARCHIVE="$OUT/iicp-directory-php-v$VERSION.tar.gz"
MANIFEST="$OUT/RELEASE-MANIFEST.json"

VERIFY_ARGS=(--version "$VERSION")
[[ "${IICP_RELEASE_ALLOW_UNTAGGED:-0}" == "1" ]] && VERIFY_ARGS+=(--allow-untagged)
python3 "$ROOT/scripts/verify_release.py" "${VERIFY_ARGS[@]}" >/dev/null
git -C "$ROOT" archive --format=tar --prefix="iicp-directory-php-v$VERSION/" HEAD \
  | gzip -n -9 >"$ARCHIVE"
python3 "$ROOT/scripts/check_secret_hygiene.py" --archive "$ARCHIVE" >/dev/null
python3 "$ROOT/scripts/verify_release.py" \
  "${VERIFY_ARGS[@]}" --archive "$ARCHIVE" --output "$MANIFEST" >/dev/null

(cd "$OUT" && sha256sum \
  "$(basename "$ARCHIVE")" \
  "$(basename "$MANIFEST")" >SHA256SUMS)

printf '%s\n' "$ARCHIVE" "$MANIFEST" "$OUT/SHA256SUMS"

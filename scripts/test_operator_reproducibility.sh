#!/usr/bin/env bash
set -euo pipefail

image_a="${1:-iicp-directory-operator:repro-a}"
image_b="${2:-iicp-directory-operator:repro-b}"
tmp="$(mktemp -d "${TMPDIR:-/tmp}/iicp-operator-repro.XXXXXX")"
trap 'rm -rf "$tmp"' EXIT

"$(dirname "$0")/operator_image_manifest.sh" "$image_a" >"$tmp/a"
"$(dirname "$0")/operator_image_manifest.sh" "$image_b" >"$tmp/b"
diff -u "$tmp/a" "$tmp/b"
echo "operator application/runtime manifests are reproducible"

#!/usr/bin/env sh
set -eu

image="${1:?usage: operator_image_manifest.sh IMAGE}"

docker run --rm --entrypoint /bin/sh "$image" -c '
  set -eu
  printf "%s\n" "schema=iicp.directory.operator-image-manifest.v1"
  printf "%s\n" "[application]"
  find /app -xdev -type f -print0 | sort -z |
    xargs -0 sha256sum | sed "s#  /app/#  #"
  printf "%s\n" "[packages]"
  apk info -vv | LC_ALL=C sort
  printf "%s\n" "[runtime]"
  if command -v php >/dev/null; then
    php -r "printf(\"php=%s\\n\", PHP_VERSION);"
    php -m | LC_ALL=C sort
  else
    nginx -v 2>&1
  fi
'

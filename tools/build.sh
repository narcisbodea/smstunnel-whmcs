#!/usr/bin/env bash
# Builds the release zip from the committed files: build/smstunnel-whmcs-<version>.zip and its SHA-256.
#
#   bash tools/build.sh
#
# The zip holds modules/ (upload it to the WHMCS root), README.md, CHANGELOG.md and LICENSE, exactly as
# committed (git archive, LF line endings), so commit first. It is then unpacked in the composer:2
# Docker image and every PHP file in it is checked with php -l.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

VERSION="$(sed -n "s/^    const NUMBER = '\([0-9.]*\)';/\1/p" modules/addons/smstunnel/lib/Version.php)"
if [ -z "$VERSION" ]; then
  echo "Could not read the version from lib/Version.php" >&2
  exit 1
fi
if ! grep -q "^## $VERSION " CHANGELOG.md; then
  echo "CHANGELOG.md has no entry for $VERSION" >&2
  exit 1
fi
if ! git diff --quiet HEAD -- modules README.md CHANGELOG.md LICENSE || [ -n "$(git status --porcelain -- modules README.md CHANGELOG.md LICENSE)" ]; then
  echo "modules/, README.md, CHANGELOG.md or LICENSE has uncommitted changes: commit them first (the zip is built from the commit)." >&2
  exit 1
fi

mkdir -p build
ZIP="build/smstunnel-whmcs-$VERSION.zip"
rm -f "$ZIP" "$ZIP.sha256"
git archive --format=zip -9 -o "$ZIP" HEAD modules README.md CHANGELOG.md LICENSE
( cd build && sha256sum "smstunnel-whmcs-$VERSION.zip" > "smstunnel-whmcs-$VERSION.zip.sha256" )

DOCKER_ROOT="$ROOT"
if command -v cygpath >/dev/null 2>&1; then
  DOCKER_ROOT="$(cygpath -m "$ROOT")"
fi
MSYS_NO_PATHCONV=1 docker run --rm -v "$DOCKER_ROOT/build:/build:ro" --entrypoint sh composer:2 -c "
  set -e
  mkdir /tmp/zip && cd /tmp/zip && unzip -q /build/smstunnel-whmcs-$VERSION.zip
  test -f modules/addons/smstunnel/smstunnel.php && test -f modules/addons/smstunnel/hooks.php
  test -f modules/security/smstunnel2fa/smstunnel2fa.php
  if find . -path ./modules -prune -o -type f -print | grep -v -E '^\./(README\.md|CHANGELOG\.md|LICENSE)$'; then echo 'unexpected files outside modules/'; exit 1; fi
  bad=0
  for f in \$(find modules -name '*.php'); do php -l \"\$f\" >/dev/null || bad=1; done
  [ \$bad -eq 0 ]
  echo \"\$(find modules -type f | wc -l) files, \$(find modules -name '*.php' | wc -l) PHP files, all pass php -l (PHP \$(php -r 'echo PHP_VERSION;'))\"
"
ls -l "$ZIP"
cat "$ZIP.sha256"

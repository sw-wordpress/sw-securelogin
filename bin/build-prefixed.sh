#!/usr/bin/env bash
# Stage production dependencies and isolate them without rewriting the source checkout.
set -euo pipefail

SWSL_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SWSL_STAGE="$(mktemp -d "${TMPDIR:-/tmp}/swsl-prefix.XXXXXXXX")"
trap 'rm -rf "$SWSL_STAGE"' EXIT
SWSL_PLUGIN="$SWSL_STAGE/sw-securelogin"
SWSL_OUTPUT="$SWSL_ROOT/build/sw-securelogin"
mkdir -p "$SWSL_PLUGIN"

# Explicit allowlist keeps development tools, tests, credentials and local notes out.
cp "$SWSL_ROOT/composer.json" "$SWSL_ROOT/composer.lock" "$SWSL_ROOT/sw-securelogin.php" \
  "$SWSL_ROOT/uninstall.php" "$SWSL_ROOT/README.md" "$SWSL_ROOT/LICENSE" "$SWSL_PLUGIN/"
mkdir -p "$SWSL_PLUGIN/docs"
cp "$SWSL_ROOT/docs/PRIVACY.md" "$SWSL_PLUGIN/docs/"
cp -R "$SWSL_ROOT/src" "$SWSL_ROOT/languages" "$SWSL_PLUGIN/"
for SWSL_DIRECTORY in assets templates; do
  if [ -d "$SWSL_ROOT/$SWSL_DIRECTORY" ]; then
    cp -R "$SWSL_ROOT/$SWSL_DIRECTORY" "$SWSL_PLUGIN/"
  fi
done

(
  cd "$SWSL_PLUGIN"
  composer validate --strict
  composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --no-scripts --no-plugins
)
mkdir -p "$SWSL_PLUGIN/bin"
cp "$SWSL_ROOT/bin/mozart-compose.php" "$SWSL_PLUGIN/bin/"
docker run --rm --user "$(id -u):$(id -g)" \
  -v "$SWSL_PLUGIN:/project" \
  coenjacobs/mozart:1.2.3 php -d memory_limit=1G /project/bin/mozart-compose.php

test -f "$SWSL_PLUGIN/vendor-prefixed/autoload.php"
# Prove the staged plugin can load without the original Composer tree.
rm -rf "$SWSL_PLUGIN/vendor" "$SWSL_PLUGIN/bin"
php "$SWSL_ROOT/tests/Build/bootstrap-smoke.php" "$SWSL_PLUGIN"
php "$SWSL_ROOT/tests/Build/webauthn-smoke.php" "$SWSL_PLUGIN"

# Generated artifacts only; reject symlink targets before replacing an older build.
if [ -L "$SWSL_ROOT/build" ] || [ -L "$SWSL_OUTPUT" ]; then
  echo 'Build output must not be a symbolic link.' >&2
  exit 1
fi
mkdir -p "$SWSL_ROOT/build"
rm -rf "$SWSL_OUTPUT"
mv "$SWSL_PLUGIN" "$SWSL_OUTPUT"
echo "Prefixed build: $SWSL_OUTPUT"

#!/usr/bin/env bash
# Build, package twice and smoke-test the extracted final archive.
set -euo pipefail
SWSL_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SWSL_TEMP="$(mktemp -d "${TMPDIR:-/tmp}/swsl-release.XXXXXXXX")"
trap 'rm -rf "$SWSL_TEMP"' EXIT
bash "$SWSL_ROOT/bin/build-prefixed.sh"
TZ=UTC php "$SWSL_ROOT/bin/package-release.php" "$SWSL_ROOT/build/sw-securelogin" "$SWSL_TEMP/first.zip"
TZ=UTC php "$SWSL_ROOT/bin/package-release.php" "$SWSL_ROOT/build/sw-securelogin" "$SWSL_TEMP/second.zip"
cmp "$SWSL_TEMP/first.zip" "$SWSL_TEMP/second.zip"
php -r '$zip = new ZipArchive(); if (true !== $zip->open($argv[1]) || !$zip->extractTo($argv[2])) throw new RuntimeException("ZIP extraction failed."); $zip->close();' "$SWSL_TEMP/first.zip" "$SWSL_TEMP/extracted"
php "$SWSL_ROOT/tests/Build/bootstrap-smoke.php" "$SWSL_TEMP/extracted/sw-securelogin"
php "$SWSL_ROOT/tests/Build/webauthn-smoke.php" "$SWSL_TEMP/extracted/sw-securelogin"
SWSL_VERSION="$(php -r 'preg_match("/ \\* Version: ([0-9.]+)/", file_get_contents($argv[1]), $m); echo $m[1];' "$SWSL_ROOT/sw-securelogin.php")"
SWSL_ZIP="$SWSL_ROOT/build/sw-securelogin-$SWSL_VERSION.zip"
if [ -L "$SWSL_ZIP" ] || [ -L "$SWSL_ZIP.sha256" ]; then echo 'Release output must not be a symlink.' >&2; exit 1; fi
mv "$SWSL_TEMP/first.zip" "$SWSL_ZIP"
(cd "$SWSL_ROOT/build" && sha256sum "sw-securelogin-$SWSL_VERSION.zip" > "sw-securelogin-$SWSL_VERSION.zip.sha256")
echo "Release candidate: $SWSL_ZIP (publication requires the release gates in docs/RELEASE.md)."

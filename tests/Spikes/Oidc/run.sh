#!/usr/bin/env bash
# Isolated evaluation: dependency installation and certificates stay in a temporary tree.
set -euo pipefail
SWSL_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SWSL_PROBE="$SWSL_ROOT/tests/Spikes/Oidc"
SWSL_PHP="${SWSL_SPIKE_PHP:-php}"
SWSL_STAGE="$(mktemp -d "${TMPDIR:-/tmp}/swsl-oidc-spike.XXXXXXXX")"
trap 'rm -rf "$SWSL_STAGE"' EXIT
export COMPOSER_CACHE_DIR="$SWSL_STAGE/cache"
cp "$SWSL_PROBE/composer.json" "$SWSL_PROBE/composer.lock" "$SWSL_STAGE/"
composer install --working-dir="$SWSL_STAGE" \
  --no-interaction --no-scripts --no-plugins --prefer-dist --no-progress
composer validate --working-dir="$SWSL_STAGE" --strict
composer audit --working-dir="$SWSL_STAGE" --locked --no-dev
"$SWSL_PHP" "$SWSL_PROBE/profile.php" "$SWSL_STAGE/vendor/autoload.php"
"$SWSL_PHP" "$SWSL_ROOT/vendor/bin/phpcs" --standard="$SWSL_ROOT/phpcs.xml.dist" \
  "$SWSL_PROBE/StrictCodeFlow.php" "$SWSL_PROBE/StrictVerifierBuilder.php"
"$SWSL_PHP" "$SWSL_ROOT/vendor/bin/phpstan" analyse --configuration="$SWSL_PROBE/phpstan.neon" \
  --autoload-file="$SWSL_STAGE/vendor/autoload.php" --memory-limit=512M --no-progress

# Build the real candidate with the reviewed production adapter.
"$SWSL_PHP" -r '
$path = $argv[1] . "/composer.json";
$candidate = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$root = json_decode(file_get_contents($argv[2] . "/composer.json"), true, 512, JSON_THROW_ON_ERROR);
$candidate["extra"] = $root["extra"];
file_put_contents($path, json_encode($candidate, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
' "$SWSL_STAGE" "$SWSL_ROOT"
mkdir -p "$SWSL_STAGE/bin" "$SWSL_STAGE/src"
cp "$SWSL_ROOT/bin/mozart-compose.php" "$SWSL_ROOT/bin/mozart-graph.php" "$SWSL_ROOT/bin/mozart-visitor.php" "$SWSL_ROOT/bin/mozart-autoload.php" "$SWSL_STAGE/bin/"
cp "$SWSL_PROBE/library.php" "$SWSL_PROBE/profile.php" "$SWSL_PROBE/StrictCodeFlow.php" "$SWSL_PROBE/StrictVerifierBuilder.php" "$SWSL_STAGE/src/"

# Diagnose upstream behavior separately, without changing the guarded production adapter.
cp "$SWSL_PROBE/mozart.php" "$SWSL_STAGE/bin/oidc-mozart-probe.php"
docker run --rm --user "$(id -u):$(id -g)" -v "$SWSL_STAGE:/project" \
  coenjacobs/mozart:1.2.3 php -d memory_limit=1G /project/bin/oidc-mozart-probe.php
cp -R "$SWSL_STAGE/vendor" "$SWSL_STAGE/original-vendor"
docker run --rm --user "$(id -u):$(id -g)" -v "$SWSL_STAGE:/project" \
  coenjacobs/mozart:1.2.3 php -d memory_limit=1G /project/bin/mozart-compose.php
rm -rf "$SWSL_STAGE/vendor"
for SWSL_MODE in clean foreign-first real-foreign-first; do
  "$SWSL_PHP" "$SWSL_PROBE/scoped.php" "$SWSL_STAGE" "$SWSL_MODE"
done

openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
  -subj /CN=oidc-spike.invalid -addext subjectAltName=DNS:oidc-spike.invalid \
  -keyout "$SWSL_STAGE/key.pem" -out "$SWSL_STAGE/certificate.pem" > "$SWSL_STAGE/tls.log" 2>&1
# Deliberately unusable proxy environment: explicit direct pinning must still work.
HTTPS_PROXY=http://127.0.0.1:1 https_proxy=http://127.0.0.1:1 ALL_PROXY=http://127.0.0.1:1 NO_PROXY= no_proxy= \
  python3 "$SWSL_PROBE/https_fixture.py" "$SWSL_STAGE/certificate.pem" "$SWSL_STAGE/key.pem" "$SWSL_PHP"
echo 'Mozart artifact checks passed. Remaining OIDC acceptance work is documented in ADR 008.'

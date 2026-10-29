#!/usr/bin/env bash
# Exercise the actual Mozart configuration with an offline local runtime dependency.
set -euo pipefail
SWSL_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SWSL_STAGE="$(mktemp -d "${TMPDIR:-/tmp}/swsl-mozart-test.XXXXXXXX")"
trap 'rm -rf "$SWSL_STAGE"' EXIT
cp -R "$SWSL_ROOT/src" "$SWSL_STAGE/src"
cp "$SWSL_ROOT/sw-securelogin.php" "$SWSL_STAGE/"
cp "$SWSL_ROOT/tests/Build/Consumer.php" "$SWSL_STAGE/src/BuildConsumer.php"
cp -R "$SWSL_ROOT/tests/Fixtures/MozartDependency" "$SWSL_STAGE/fixture-package"
php -r '
$config = json_decode(file_get_contents($argv[1] . "/composer.json"), true, 512, JSON_THROW_ON_ERROR);
$fixture = [
    "name" => "swsl-test/mozart-project",
    "require" => ["php" => ">=8.2", "swsl-test/mozart-dependency" => "1.0.0"],
    "repositories" => [
        ["type" => "path", "url" => "fixture-package", "options" => ["symlink" => false]],
        ["packagist.org" => false],
    ],
    "autoload" => $config["autoload"],
    "extra" => $config["extra"],
];
file_put_contents($argv[2] . "/composer.json", json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
' "$SWSL_ROOT" "$SWSL_STAGE"
(
  cd "$SWSL_STAGE"
  composer update --no-dev --no-interaction --no-scripts --no-plugins --no-audit
)
mkdir -p "$SWSL_STAGE/bin"
cp "$SWSL_ROOT/bin/mozart-compose.php" "$SWSL_STAGE/bin/"
# Fail closed before touching an unreviewed autoload form.
cp "$SWSL_STAGE/vendor/composer/installed.json" "$SWSL_STAGE/installed-original.json"
php -r '
$path = $argv[1] . "/vendor/composer/installed.json";
$data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$data["packages"][0]["autoload"]["files"] = ["src/Dependency.php"];
file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
' "$SWSL_STAGE"
if docker run --rm --user "$(id -u):$(id -g)" -v "$SWSL_STAGE:/project" \
  coenjacobs/mozart:1.2.3 php /project/bin/mozart-compose.php > "$SWSL_STAGE/rejected.log" 2>&1; then
  echo 'Unreviewed autoload form was unexpectedly accepted.' >&2
  exit 1
fi
grep -q 'Review Mozart root rewriting' "$SWSL_STAGE/rejected.log"
mv "$SWSL_STAGE/installed-original.json" "$SWSL_STAGE/vendor/composer/installed.json"
docker run --rm --user "$(id -u):$(id -g)" \
  -v "$SWSL_STAGE:/project" \
  coenjacobs/mozart:1.2.3 php -d memory_limit=1G /project/bin/mozart-compose.php
rm -rf "$SWSL_STAGE/vendor" "$SWSL_STAGE/fixture-package"
php "$SWSL_ROOT/tests/Build/prefix-smoke.php" "$SWSL_STAGE"
# A present prefixed loader must take precedence over the Composer fallback.
mkdir -p "$SWSL_STAGE/vendor"
printf '%s\n' '<?php throw new RuntimeException("Original vendor loader must not run.");' > "$SWSL_STAGE/vendor/autoload.php"
php "$SWSL_ROOT/tests/Build/prefix-smoke.php" "$SWSL_STAGE"

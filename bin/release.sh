#!/usr/bin/env bash
# Set the plugin version, commit it, then atomically push the branch and tag to origin.
set -euo pipefail

cd "$(dirname "$0")/.."
version="${SWSL_RELEASE_VERSION:-}"
if [[ ! "$version" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]]; then
  echo 'Usage: make release VERSION=X.Y.Z' >&2
  exit 1
fi
branch="$(git symbolic-ref --quiet --short HEAD)" || { echo 'A checked-out branch is required.' >&2; exit 1; }
if [[ -n "$(git status --porcelain --untracked-files=all)" ]]; then
  echo 'Commit or stash all changes before releasing (including untracked files).' >&2
  exit 1
fi
if git show-ref --verify --quiet "refs/tags/$version"; then
  echo "Tag $version already exists locally." >&2
  exit 1
fi
if git ls-remote --exit-code --tags origin "refs/tags/$version"; then
  echo "Tag $version already exists on origin." >&2
  exit 1
else
  result=$?
  if [[ "$result" -ne 2 ]]; then
    echo 'Cannot check tags on origin; nothing changed.' >&2
    exit "$result"
  fi
fi

php <<'PHP'
<?php
declare(strict_types=1);

$file = 'sw-securelogin.php';
$source = file_get_contents($file);
$version = getenv('SWSL_RELEASE_VERSION');
$header = '/^( \* Version:\s+)([0-9]+(?:\.[0-9]+){2})(\s*)$/m';
// Keep the expected declaration strict so unexpected source changes abort the release.
$constant = "/^(defined\( 'SWSL_VERSION' \) \|\| define\( 'SWSL_VERSION', ')([0-9]+(?:\.[0-9]+){2})(' \);)$/m";
$readme = file_get_contents('readme.txt');
if (preg_match_all('/^Stable tag: ([0-9]+\.[0-9]+\.[0-9]+)$/m', $readme, $stable) !== 1) {
    throw new RuntimeException('Expected one Stable tag in readme.txt.');
}
if (preg_match_all($header, $source, $headers) !== 1 || preg_match_all($constant, $source, $constants) !== 1
    || $headers[2][0] !== $constants[2][0] || $headers[2][0] !== $stable[1][0] || version_compare($version, $headers[2][0], '<')) {
    fwrite(STDERR, "Invalid or inconsistent plugin version, or requested downgrade.\n");
    exit(1);
}
foreach ([$header, $constant] as $pattern) {
    $source = preg_replace_callback($pattern, static fn (array $match): string => $match[1] . $version . $match[3], $source);
}
if (file_put_contents($file, $source) !== strlen($source)) {
    fwrite(STDERR, "Failed to write plugin version.\n");
    exit(1);
}
$updated = preg_replace('/^Stable tag: [0-9]+\.[0-9]+\.[0-9]+$/m', 'Stable tag: ' . $version, $readme);
if (file_put_contents('readme.txt', $updated) !== strlen($updated)) {
    throw new RuntimeException('Failed to update readme Stable tag.');
}
PHP

if ! git diff --quiet -- sw-securelogin.php readme.txt; then
  git add -- sw-securelogin.php readme.txt
  git commit -m "Release $version"
fi
git tag -a "$version" -m "Release $version"
# No force push: either both refs are accepted or neither is changed remotely.
git push --atomic origin "HEAD:refs/heads/$branch" "refs/tags/$version"
echo "Published $version to origin; the tag triggers the signed release workflow."

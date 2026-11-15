<?php
/** Validate a stable release tag against every canonical version field. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$tag = $argv[1] ?? '';
if (!preg_match('/^v?(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $tag)) {
    throw new RuntimeException('Expected stable tag [v]X.Y.Z.');
}
$version = ltrim($tag, 'v');
$main = file_get_contents(dirname(__DIR__) . '/sw-securelogin.php');
$readme = file_get_contents(dirname(__DIR__) . '/readme.txt');
if (!preg_match('/^ \* Version: ([0-9.]+)$/m', $main, $header)
    || !preg_match("/define\\( 'SWSL_VERSION', '([0-9.]+)' \\)/", $main, $constant)
    || !preg_match('/^Stable tag: ([0-9.]+)$/m', $readme, $stable)
    || $version !== $header[1] || $version !== $constant[1] || $version !== $stable[1]) {
    throw new RuntimeException('Tag, header, SWSL_VERSION and readme Stable tag must match.');
}
echo "Release version validated: $version\n";

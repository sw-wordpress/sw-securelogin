<?php
/** Offline artifact validation before publishing a draft; no production keys or WordPress needed. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
define('ABSPATH', '/swsl-release-validation/');
class WP_Error {
    public function __construct(public string $code, public string $message, public mixed $data = null) {}
}
function __(string $text, string $domain): string { return $text; }
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
require dirname(__DIR__) . '/src/Update/Manifest.php';
require dirname(__DIR__) . '/src/Update/Package.php';
$release = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
$dir = $argv[2];
if (($release['draft'] ?? null) !== true || ($release['prerelease'] ?? null) !== false) {
    throw new RuntimeException('Only a stable unpublished draft may be published.');
}
// Runtime only accepts published releases. All remaining bindings are checked unchanged.
$release['draft'] = false;
$keys = require dirname(__DIR__) . '/src/Update/Config/trusted-keys.php';
$raw = file_get_contents($dir . '/sw-plugin-release.json');
$signature = file_get_contents($dir . '/sw-plugin-release.json.sig');
$data = SW\Securelogin\Update\Manifest::verify($raw, $signature, $keys, $release);
if (is_wp_error($data) || $data['commit'] !== $argv[3] || count($data['packages']) !== 1) {
    throw new RuntimeException('Signed artifact identity verification failed.');
}
foreach ($data['packages'] as $package) {
    $file = $dir . '/' . $package['filename'];
    $result = SW\Securelogin\Update\Package::verify($file, $package);
    if (is_wp_error($result)) throw new RuntimeException('ZIP verification failed.');
    $zip = new ZipArchive();
    $zip->open($file);
    $shipped = $zip->getFromName('sw-securelogin/src/Update/Config/trusted-keys.php');
    // Never execute PHP from an archive, even to inspect its public keyring.
    if ($shipped !== file_get_contents(dirname(__DIR__) . '/src/Update/Config/trusted-keys.php')) {
        throw new RuntimeException('Shipped public keyring differs from the complete build keyring.');
    }
    foreach (['sw-securelogin.php', 'vendor-prefixed/autoload.php', 'src/Update/Provider.php', 'assets/js/swsl-updates.js'] as $required) {
        if (false === $zip->locateName('sw-securelogin/' . $required)) throw new RuntimeException('Incomplete release ZIP.');
    }
    $zip->close();
}
echo "Draft assets verified; publication may proceed.\n";

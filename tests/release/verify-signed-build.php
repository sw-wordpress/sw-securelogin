<?php

/**
 * Signs the REAL build ZIP(s) with a disposable key and verifies them with the runtime verifier.
 * Also checks package completeness and that signing aborts without the signing secret.
 *
 * Usage: php tests/release/verify-signed-build.php <directory-with-zips>
 * No WordPress, no network, no production key.
 */

declare(strict_types=1);

define('ABSPATH', '/swsl-release-check/');
define('DAY_IN_SECONDS', 86400);

// phpcs:disable
class WP_Error
{
    public function __construct(public string $code = '', public string $message = '', public mixed $data = null)
    {
    }
    public function get_error_code(): string
    {
        return $this->code;
    }
    public function get_error_message(): string
    {
        return $this->message;
    }
}
// phpcs:enable
function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}
function __(string $text, string $domain = 'default'): string
{
    return $text;
}
function wp_parse_url(string $url): array|false
{
    return parse_url($url);
}

$root = dirname(__DIR__, 2);
foreach (array( 'Manifest', 'Package' ) as $class) {
    require_once $root . '/src/Update/' . $class . '.php';
}

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

$dir = realpath($argv[1] ?? '');
if (false === $dir) {
    fail('ZIP directory missing.');
}
$zips = glob($dir . '/sw-securelogin-*.zip') ?: array();
if (1 !== count($zips)) {
    fail('Expected exactly one sw-securelogin ZIP.');
}
$zip_file = $zips[0];

// Completeness of the installable package (the update must ship its own verifier and keyring).
$zip = new ZipArchive();
$zip->open($zip_file);
$names = array();
for ($i = 0; $i < $zip->count(); $i++) {
    $names[] = (string) $zip->getNameIndex($i);
}
$required = array(
    'sw-securelogin/sw-securelogin.php',
    'sw-securelogin/uninstall.php',
    'sw-securelogin/vendor-prefixed/autoload.php',
    'sw-securelogin/assets/js/swsl-updates.js',
    'sw-securelogin/languages/sw-securelogin-de_DE.mo',
);
foreach (array( 'Manifest', 'Package', 'Provider', 'Service', 'Store', 'Transport' ) as $class) {
    $required[] = "sw-securelogin/src/Update/$class.php";
}
foreach (array( 'source', 'descriptors', 'trusted-keys' ) as $config) {
    $required[] = "sw-securelogin/src/Update/Config/$config.php";
}
foreach ($required as $path) {
    if (! in_array($path, $names, true)) {
        fail("Package incomplete, missing $path");
    }
}
foreach ($names as $name) {
    if (preg_match('#^sw-securelogin/(vendor|tests|apps|bin|node_modules|\.git|\.github)/#', $name) || preg_match('#signing-key|\.base64$|\.pem$|secret[-_]key#i', $name)) {
        fail("Unexpected development or key file in package: $name");
    }
}
$main = (string) $zip->getFromName('sw-securelogin/sw-securelogin.php');
$zip->close();
if (! preg_match('/^ \* Update URI:\s+https:\/\/github\.com\/sw-wordpress\/sw-securelogin$/m', $main)) {
    fail('Update URI header missing or wrong.');
}
$ring = require $root . '/src/Update/Config/trusted-keys.php';
echo 'Shipped key ring entries: ' . count($ring) . "\n";

// Disposable key, work on a copy so the build output is untouched.
$work = sys_get_temp_dir() . '/swsl-release-check-' . bin2hex(random_bytes(6));
mkdir($work, 0700);
$copy = $work . '/' . basename($zip_file);
copy($zip_file, $copy);
$pair   = sodium_crypto_sign_keypair();
$secret = base64_encode(sodium_crypto_sign_secretkey($pair));
$public = array( 'throwaway' => base64_encode(sodium_crypto_sign_publickey($pair)) );
$release = array(
    'id'         => 7,
    'tag_name'   => 'v' . preg_replace('/^sw-securelogin-(.+)\.zip$/', '$1', basename($zip_file)),
    'draft'      => false,
    'prerelease' => false,
    'assets'     => array( array( 'id' => 11, 'name' => basename($zip_file), 'size' => filesize($copy) ) ),
);
file_put_contents($work . '/release.json', json_encode($release));

$sign = static function (array $env) use ($root, $work): array {
    $p = proc_open(array( PHP_BINARY, $root . '/bin/release-manifest.php', 'sign', $work . '/release.json', $work, str_repeat('b', 40) ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return array( proc_close($p), $out );
};
[ $exit ] = $sign(array());
if (0 === $exit || file_exists($work . '/sw-plugin-release.json')) {
    fail('Signing must abort without the signing secret.');
}
[ $exit, $out ] = $sign(array( 'SW_PLUGIN_RELEASE_SIGNING_KEY' => $secret, 'SW_PLUGIN_RELEASE_KEY_ID' => 'throwaway' ));
if (0 !== $exit || str_contains($out, $secret)) {
    fail('Signing failed or leaked the key: ' . $out);
}
$manifest = \SW\Securelogin\Update\Manifest::verify((string) file_get_contents($work . '/sw-plugin-release.json'), (string) file_get_contents($work . '/sw-plugin-release.json.sig'), $public, $release);
if (is_wp_error($manifest)) {
    fail('Runtime verifier rejected the signed manifest: ' . json_encode($manifest->data));
}
$package = \SW\Securelogin\Update\Manifest::package($manifest, 'sw-securelogin');
if (null === $package) {
    fail('Package entry missing.');
}
$result = \SW\Securelogin\Update\Package::verify($copy, $package);
if (is_wp_error($result)) {
    fail('Runtime package check rejected the real build ZIP: ' . json_encode($result->data));
}
// The same signature must fail with a different key.
$wrong = array( 'throwaway' => base64_encode(random_bytes(32)) );
if (! is_wp_error(\SW\Securelogin\Update\Manifest::verify((string) file_get_contents($work . '/sw-plugin-release.json'), (string) file_get_contents($work . '/sw-plugin-release.json.sig'), $wrong, $release))) {
    fail('Signature accepted with a foreign key.');
}

// Exercise the exact pre-publication CLI in a private runner tree, with the disposable
// PUBLIC key embedded into a COPY of the real ZIP. No production keyring is changed.
$runner = $work . '/runner';
mkdir($runner . '/src/Update/Config', 0700, true);
mkdir($runner . '/bin', 0700);
foreach (['Manifest', 'Package'] as $class) {
    copy($root . '/src/Update/' . $class . '.php', $runner . '/src/Update/' . $class . '.php');
}
copy($root . '/src/Update/Config/source.php', $runner . '/src/Update/Config/source.php');
copy($root . '/bin/verify-release.php', $runner . '/bin/verify-release.php');
$publicPhp = "<?php\nreturn " . var_export($public, true) . ";\n";
file_put_contents($runner . '/src/Update/Config/trusted-keys.php', $publicPhp);
$zip = new ZipArchive();
$zip->open($copy);
$zip->addFromString('sw-securelogin/src/Update/Config/trusted-keys.php', $publicPhp);
$zip->setExternalAttributesName('sw-securelogin/src/Update/Config/trusted-keys.php', ZipArchive::OPSYS_UNIX, 0100644 << 16);
$zip->close();
clearstatcache(true, $copy);
$release['draft'] = true;
$release['assets'][0]['size'] = filesize($copy);
file_put_contents($work . '/release.json', json_encode($release));
[ $exit, $out ] = $sign(['SW_PLUGIN_RELEASE_SIGNING_KEY' => $secret, 'SW_PLUGIN_RELEASE_KEY_ID' => 'throwaway']);
if ($exit !== 0) fail('Disposable draft signing failed.');
$verifyDraft = static function () use ($runner, $work): int {
    $process = proc_open([PHP_BINARY, $runner . '/bin/verify-release.php', $work . '/release.json', $work, str_repeat('b', 40)], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
    stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return proc_close($process);
};
if ($verifyDraft() !== 0) fail('Release workflow verifier rejected the valid disposable draft.');
$release['draft'] = false;
file_put_contents($work . '/release.json', json_encode($release));
if ($verifyDraft() === 0) fail('Published release must never be modified.');
$release['draft'] = true;
file_put_contents($work . '/release.json', json_encode($release));
file_put_contents($copy, 'tampered');
if ($verifyDraft() === 0) fail('Pre-publication verification accepted a tampered ZIP.');
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($iterator as $entry) {
    if ($entry->isDir()) rmdir($entry->getPathname());
    else unlink($entry->getPathname());
}
rmdir($work);
echo 'PASS: real ZIP completeness/signature, missing-secret refusal, foreign-key refusal, exact draft publication verifier and tamper/published-release refusal.' . "\n";

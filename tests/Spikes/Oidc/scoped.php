<?php
/** Exercise the rewritten root consumer without any original vendor fallback. */
declare(strict_types=1);

$stage = $argv[1];
$mode = $argv[2];
if (is_dir($stage . '/vendor')) throw new RuntimeException('Original vendor must be removed before testing the scoped artifact.');
if ('foreign-first' === $mode) require __DIR__ . '/collision.php';
if ('real-foreign-first' === $mode) require $stage . '/original-vendor/autoload.php';
$argv[1] = $stage . '/vendor-prefixed/autoload.php';
require $stage . '/src/profile.php';

$prefix = 'SW\\Securelogin\\Dependencies\\';
spikeCheck(function_exists($prefix . 'Facile\\OpenIDClient\\parse_metadata_response'), 'Namespaced eager functions missing.');
spikeCheck(function_exists('swsl_trigger_deprecation'), 'Scoped global helper missing.');
$override = new ReflectionClass('Override');
spikeCheck($override->isInternal() === (PHP_VERSION_ID >= 80300), 'Conditional Override stub/native attribute mapping failed.');
spikeCheck(new DateMalformedStringException('fixture') instanceof DateException, 'Conditional date exception classmap failed.');
spikeCheck(get_parent_class($prefix . 'Jose\\Component\\Encryption\\Algorithm\\KeyEncryption\\A128KW') === $prefix . 'Jose\\Component\\Encryption\\Algorithm\\KeyEncryption\\AESKW', 'Local AESKW class was confused with the optional external namespace.');
// Optional dependency references must be rewritten even without a Composer require edge.
$wrapper = $prefix . 'AESKW\\A128KW';
$kek = hex2bin('000102030405060708090A0B0C0D0E0F');
$plain = hex2bin('00112233445566778899AABBCCDDEEFF');
$wrapped = $wrapper::wrap($kek, $plain);
spikeCheck(bin2hex($wrapped) === '1fa68b0a8112b447aef34bd8fb5a7b829d3e862371d2cfe5', 'RFC3394 known-answer fixture failed.');
spikeCheck($wrapper::unwrap($kek, $wrapped) === $plain, 'Optional AES wrapper failed.');

$deprecation = false;
set_error_handler(static function (int $severity) use (&$deprecation): bool {
	if (E_USER_DEPRECATED !== $severity) return false;
	$deprecation = true;
	return true;
});
try { swsl_trigger_deprecation('swsl-fixture', '1.0', 'Fixture deprecation.'); }
finally { restore_error_handler(); }
spikeCheck($deprecation, 'Scoped global helper did not execute.');

foreach (['web-token-jwt-library/LICENSE', 'symfony-polyfill-php83/LICENSE', 'spomky-labs-aes-key-wrap/LICENSE'] as $notice) {
	spikeCheck(is_file($stage . '/vendor-prefixed/licenses/' . $notice), 'Required library notice missing.');
}
$licenses = json_decode(file_get_contents($stage . '/vendor-prefixed/licenses/packages.json'), true, 512, JSON_THROW_ON_ERROR);
$licenses = array_column($licenses, null, 'name');
// The Facile dist archives have no LICENSE file; retain their declared metadata too.
foreach (['facile-it/php-openid-client', 'facile-it/php-jose-verifier'] as $name) {
	spikeCheck(['MIT'] === ($licenses[$name]['license'] ?? null), 'Upstream declared licensing metadata missing.');
}
if ('clean' === $mode) {
	spikeCheck(!class_exists('Facile\\OpenIDClient\\Client\\ClientBuilder', false), 'Original client class leaked.');
	spikeCheck(!function_exists('Facile\\OpenIDClient\\parse_metadata_response'), 'Original namespaced function leaked.');
	require __DIR__ . '/collision.php';
}
// Re-run exchange after the foreign symbols were introduced, including load-after.
$http->token = $sign($claims);
spikeCheck('fixture-subject' === $strict->exchange($client, $callback, $session, $redirect)['sub'], 'Foreign symbols altered the already loaded verifier.');
echo 'PASS: scoped artifact, eager functions, optional dependency, licenses and collision mode ' . $mode . ' on PHP ' . PHP_VERSION . ".\n";

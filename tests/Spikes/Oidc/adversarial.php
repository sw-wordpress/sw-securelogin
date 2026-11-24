<?php
/** Characterize remaining candidate gaps; never use accepted attack fixtures as login tests. */
declare(strict_types=1);

use Facile\JoseVerifier\JWK\JwksProviderBuilder;
use Facile\OpenIDClient\Client\ClientBuilder;
use Facile\OpenIDClient\Issuer\IssuerBuilder;
use Facile\OpenIDClient\Issuer\Metadata\Provider\MetadataProviderBuilder;

// profile.php supplies the real configured service, signer and synthetic HTTP fixture.
// Each row records observed behavior, including gaps; a changed result requires review.
$observedGaps = [];
$observedRejections = [];
// Record (never suppress) PHP diagnostics: the guard must stop them before the candidate runs.
$diagnostics = [];
set_error_handler(static function (int $level, string $message) use (&$diagnostics): bool {
	$diagnostics[] = $message;
	return true;
});
$observe = static function (string $name) use ($strict, &$client, $callback, $session, $redirect, &$observedGaps, &$observedRejections): void {
	try {
		$strict->exchange($client, $callback, $session, $redirect);
	} catch (RuntimeException $error) {
		spikeCheck('OIDC evaluation exchange failed.' === $error->getMessage() && null === $error->getPrevious(), 'Adversarial rejection exposed diagnostics.');
		$observedRejections[] = $name;
		return;
	}
	$observedGaps[] = $name;
};

foreach (['iss', 'sub', 'aud', 'azp', 'nonce', 'exp', 'iat', 'nbf'] as $field) {
	foreach (['null' => null, 'boolean' => true, 'integer' => 123, 'empty-string' => '', 'array' => [], 'object' => (object) ['value' => 'fixture']] as $type => $value) {
		// Integer NumericDates are valid types; age policy is a separate M4.0 decision.
		if ('integer' === $type && in_array($field, ['exp', 'iat', 'nbf'], true)) continue;
		$http->token = $sign(array_replace($claims, [$field => $value]));
		$observe('claim-' . $field . '-' . $type);
	}
}
foreach ([
	'sub-over-255-bytes' => ['sub' => str_repeat('a', 256)],
	'audience-mixed-types' => ['aud' => ['fixture-client', 123], 'azp' => 'fixture-client'],
	'audience-object' => ['aud' => (object) ['client' => 'fixture-client']],
	'audience-numeric-object' => ['aud' => (object) ['0' => 'fixture-client']],
	'audience-empty-member' => ['aud' => ['fixture-client', ''], 'azp' => 'fixture-client'],
	'audience-null-member' => ['aud' => ['fixture-client', null], 'azp' => 'fixture-client'],
	'audience-nested-member' => ['aud' => ['fixture-client', ['other']], 'azp' => 'fixture-client'],
	'sub-non-ascii' => ['sub' => "fixture-\u{00e4}"],
	'exp-numeric-string' => ['exp' => (string) (time() + 120)],
	'iat-numeric-string' => ['iat' => (string) time()],
	'nbf-numeric-string' => ['nbf' => (string) (time() - 120)],
] as $name => $mutation) {
	$http->token = $sign(array_replace($claims, $mutation));
	$observe($name);
}

// Preserve exact raw bytes, including duplicate/escaped names, before signing.
$signRaw = static function (string $payload, string $header) use ($key): string {
	$input = spikeEncode($header) . '.' . spikeEncode($payload);
	spikeCheck(openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256), 'Raw fixture signing failed.');
	return $input . '.' . spikeEncode($signature);
};
$rawClaims = json_encode($claims, JSON_THROW_ON_ERROR);
$rawHeader = '{"alg":"RS256","kid":"fixture-key"}';
foreach ([
	'duplicate-sub' => ['{"sub":"attacker",' . substr($rawClaims, 1), $rawHeader],
	'escaped-duplicate-sub' => ['{"su\\u0062":"attacker",' . substr($rawClaims, 1), $rawHeader],
	'duplicate-nonce' => ['{"nonce":"attacker",' . substr($rawClaims, 1), $rawHeader],
	'duplicate-alg' => [$rawClaims, '{"alg":"none","alg":"RS256","kid":"fixture-key"}'],
	'duplicate-kid' => [$rawClaims, '{"alg":"RS256","kid":"attacker","kid":"fixture-key"}'],
	'duplicate-nested-claim' => ['{"cnf":{"a":1,"a":2},' . substr($rawClaims, 1), $rawHeader],
] as $name => [$payload, $header]) {
	$http->token = $signRaw($payload, $header);
	$observe($name);
}

// Untrusted header URLs must never trigger HTTP, even when the token is rejected.
foreach (['jku', 'x5u'] as $field) {
	foreach (['url' => 'https://attacker.invalid/keys', 'null' => null, 'empty' => '', 'integer' => 123, 'array' => []] as $type => $value) {
		$http->token = $sign($claims, [$field => $value]);
		$before = count($http->calls);
		$observe('header-' . $field . '-' . $type);
		foreach (array_slice($http->calls, $before) as [$method, $uri]) {
			spikeCheck(in_array([$method, $uri], [['POST', $metadata['token_endpoint']], ['GET', $metadata['jwks_uri']]], true), 'Untrusted JOSE header triggered HTTP.');
		}
	}
}

// A fresh issuer/provider prevents cached keys from hiding a malicious JWKS response.
$originalClient = $client;
$originalJwks = $http->jwks;
foreach ([
	'empty' => ['keys' => []],
	'duplicate-kid' => ['keys' => [$jwk, $jwk]],
	'encryption-use' => ['keys' => [array_replace($jwk, ['use' => 'enc'])]],
	'wrong-algorithm' => ['keys' => [array_replace($jwk, ['alg' => 'RS512'])]],
	'sign-only-key-ops' => ['keys' => [array_replace($jwk, ['key_ops' => ['sign']])]],
	'wrong-key-type' => ['keys' => [array_replace($jwk, ['kty' => 'oct', 'k' => spikeEncode('fixture-only')])]],
	'duplicate-name' => '{"keys":[{"kid":"attacker","kid":"fixture-key"}]}',
] as $name => $keys) {
	$http->rawJwks = is_string($keys) ? $keys : null;
	$http->jwks = is_array($keys) ? $keys : $originalJwks;
	$freshIssuer = (new IssuerBuilder())
		->setMetadataProviderBuilder((new MetadataProviderBuilder())->setHttpClient($guarded)->setRequestFactory($factory)->setUriFactory($factory))
		->setJwksProviderBuilder((new JwksProviderBuilder())->withHttpClient($guarded)->withRequestFactory($factory))
		->build($metadata['issuer'] . '/.well-known/openid-configuration');
	$client = (new ClientBuilder())->setIssuer($freshIssuer)->setHttpClient($guarded)->setClientMetadata($originalClient->getMetadata())->build();
	$http->token = $sign($claims);
	$before = count($http->calls);
	$observe('jwks-' . $name);
	spikeCheck(in_array(['GET', $metadata['jwks_uri']], array_slice($http->calls, $before), true), 'JWKS fixture was hidden by cache.');
}
$client = $originalClient;
$http->jwks = $originalJwks;
$http->rawJwks = null;
$http->token = $sign($claims);
spikeCheck('fixture-subject' === $strict->exchange($client, $callback, $session, $redirect)['sub'], 'Adversarial fixtures leaked into the valid flow.');

foreach ($observedGaps as $name) echo 'GAP: configured candidate accepted ' . $name . ".\n";
restore_error_handler();
spikeCheck([] === $diagnostics, 'Candidate emitted PHP diagnostics: ' . implode('; ', array_unique($diagnostics)));
spikeCheck([] === $observedGaps && 79 === count($observedRejections), 'Candidate adversarial behavior changed; review gaps before updating expectations.');
echo 'Adversarial characterization: ' . count($observedRejections) . ' rejected, ' . count($observedGaps) . " accepted gaps.\n";

// Boundaries and case-sensitive subjects must survive schema checking byte-for-byte.
foreach ([['sub' => 'A'], ['sub' => str_repeat('z', 255)], ['sub' => 'Fixture-Subject'], ['aud' => ['fixture-client']]] as $mutation) {
	$expected = array_replace($claims, $mutation);
	$http->token = $sign($expected);
	$verified = $strict->exchange($client, $callback, $session, $redirect);
	spikeCheck($expected['sub'] === $verified['sub'] && $expected['aud'] === $verified['aud'], 'Valid schema boundary was rejected or normalized.');
}
$http->token = $sign($claims);
echo "PASS: four subject/audience schema boundary controls preserve exact values.\n";

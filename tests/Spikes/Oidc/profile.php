<?php
/** Exercise the configured library and code-only guards with the original signed fixtures. */
declare(strict_types=1);

use Facile\OpenIDClient\Session\AuthSession;
use Facile\OpenIDClient\Client\ClientBuilder;
use Facile\OpenIDClient\Client\Metadata\ClientMetadata;
use SW\Securelogin\Spikes\Oidc\StrictCodeFlow;

require __DIR__ . '/library.php';
require __DIR__ . '/StrictVerifierBuilder.php';
require __DIR__ . '/StrictCodeFlow.php';

$strict = new StrictCodeFlow($http, $factory);
$callback = ['code' => 'fixture-code', 'state' => 'fixture-state', 'iss' => $metadata['issuer']];
$redirect = 'https://wp.example.invalid/callback';
$http->token = $sign($claims);
spikeCheck('fixture-subject' === $strict->exchange($client, $callback, $session, $redirect)['sub'], 'Configured valid flow failed.');
echo "PASS: configured code flow returns verified subject.\n";

$rejected = 0;
$reject = static function (callable $action, string $name, bool $beforeHttp = false) use ($http, &$rejected): void {
	$count = count($http->calls);
	try {
		$action();
	} catch (RuntimeException $error) {
		spikeCheck('OIDC evaluation exchange failed.' === $error->getMessage() && null === $error->getPrevious(), 'Rejection exposed library details or used the wrong boundary.');
		if ($beforeHttp) spikeCheck($count === count($http->calls), 'Invalid local callback reached HTTP.');
		++$rejected;
		echo 'PASS: configured flow rejects ' . $name . ".\n";
		return;
	}
	throw new RuntimeException('Configured flow accepted ' . $name);
};

foreach (['nonce', 'iss', 'sub', 'aud', 'exp', 'iat'] as $field) {
	$http->token = $sign(array_diff_key($claims, [$field => true]));
	$reject(static fn() => $strict->exchange($client, $callback, $session, $redirect), 'missing-' . $field);
}
foreach (array_merge($mutations, [
	'azp' => ['azp' => 'wrong-client'],
	'multiple-audiences-without-azp' => ['aud' => ['fixture-client', 'other-client']],
	'multiple-audiences-with-wrong-azp' => ['aud' => ['fixture-client', 'other-client'], 'azp' => 'other-client'],
]) as $name => $mutation) {
	$http->token = $sign(array_replace($claims, $mutation));
	$reject(static fn() => $strict->exchange($client, $callback, $session, $redirect), $name);
}
foreach ([['azp' => 'fixture-client'], ['aud' => ['fixture-client', 'other-client'], 'azp' => 'fixture-client']] as $mutation) {
	$http->token = $sign(array_replace($claims, $mutation));
	spikeCheck('fixture-subject' === $strict->exchange($client, $callback, $session, $redirect)['sub'], 'Valid authorized-party rejected.');
}
foreach (['malformed', 'signature', 'none', 'HS256', 'unknown-kid'] as $name) {
	$header = match ($name) { 'none' => ['alg' => 'none'], 'HS256' => ['alg' => 'HS256'], 'unknown-kid' => ['kid' => 'missing-key'], default => [] };
	$http->token = $sign($claims, $header);
	if ('malformed' === $name) $http->token = 'invalid-fixture-token';
	if ('signature' === $name) {
		$parts = explode('.', $http->token);
		$bytes = base64_decode(strtr($parts[2], '-_', '+/'), true);
		$bytes[0] = chr(ord($bytes[0]) ^ 1);
		$http->token = $parts[0] . '.' . $parts[1] . '.' . spikeEncode($bytes);
	}
	$reject(static fn() => $strict->exchange($client, $callback, $session, $redirect), 'configured-' . $name);
}
$http->token = '';
$reject(static fn() => $strict->exchange($client, $callback, $session, $redirect), 'missing-id-token');
$http->token = $sign($claims);

foreach (['code', 'state', 'iss'] as $field) {
	foreach ([null, '', false, [], 123] as $value) {
		$input = array_replace($callback, [$field => $value]);
		$reject(static fn() => $strict->exchange($client, $input, $session, $redirect), 'invalid-callback-' . $field, true);
	}
	$input = array_diff_key($callback, [$field => true]);
	$reject(static fn() => $strict->exchange($client, $input, $session, $redirect), 'missing-callback-' . $field, true);
}
foreach ([['state' => 'wrong-state'], ['iss' => 'https://another-issuer.invalid']] as $mutation) {
	$input = array_replace($callback, $mutation);
	$reject(static fn() => $strict->exchange($client, $input, $session, $redirect), 'mismatched-state-or-issuer', true);
}
foreach (['id_token', 'access_token', 'code_verifier', 'error', 'unexpected'] as $field) {
	$input = $callback + [$field => 'injected'];
	$reject(static fn() => $strict->exchange($client, $input, $session, $redirect), 'injected-' . $field, true);
}
$reject(static fn() => $strict->exchange($client, $callback, $session, 'https://attacker.invalid/callback'), 'redirect-substitution', true);
foreach (['state', 'nonce', 'code_verifier'] as $field) {
	foreach ([null, ''] as $value) {
		$sessionData = ['state' => 'fixture-state', 'nonce' => 'fixture-nonce', 'code_verifier' => 'fixture-verifier'];
		$sessionData[$field] = $value;
		$missing = AuthSession::fromArray($sessionData);
		$reject(static fn() => $strict->exchange($client, $callback, $missing, $redirect), 'missing-session-' . $field, true);
	}
}
foreach ([null, 'none', 'HS256'] as $algorithm) {
	$clientData = $client->getMetadata()->toArray();
	unset($clientData['id_token_signed_response_alg']);
	if (null !== $algorithm) $clientData['id_token_signed_response_alg'] = $algorithm;
	$wrongProfile = (new ClientBuilder())->setIssuer($issuer)->setHttpClient($http)->setClientMetadata(ClientMetadata::fromArray($clientData))->build();
	$reject(static fn() => $strict->exchange($wrongProfile, $callback, $session, $redirect), 'unapproved-algorithm-profile', true);
}
echo 'Configured-profile rejection checks: ' . $rejected . ". No WordPress session/link API is present.\n";

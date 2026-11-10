<?php
/** Isolated library feasibility probe. Synthetic responses, no network or WP state. */
declare(strict_types=1);

use Facile\JoseVerifier\Exception\ExceptionInterface as VerificationFailure;
use Facile\JoseVerifier\JWK\JwksProviderBuilder;
use Facile\OpenIDClient\Client\ClientBuilder;
use Facile\OpenIDClient\Client\Metadata\ClientMetadata;
use Facile\OpenIDClient\Issuer\IssuerBuilder;
use Facile\OpenIDClient\Issuer\Metadata\Provider\MetadataProviderBuilder;
use Facile\OpenIDClient\Service\Builder\AuthorizationServiceBuilder;
use Facile\OpenIDClient\Session\AuthSession;
use Jose\Component\Checker\InvalidHeaderException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

require $argv[1];

function spikeCheck(bool $condition, string $message): void {
	if (!$condition) throw new RuntimeException($message);
}

function spikeEncode(string $bytes): string {
	return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

// Sign fixtures with OpenSSL; validation is exclusively performed by the candidate.
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
spikeCheck(false !== $key, 'Fixture key generation failed.');
$details = openssl_pkey_get_details($key);
spikeCheck(is_array($details), 'Fixture key details failed.');
$jwk = ['kty' => 'RSA', 'kid' => 'fixture-key', 'use' => 'sig', 'alg' => 'RS256', 'n' => spikeEncode($details['rsa']['n']), 'e' => spikeEncode($details['rsa']['e'])];
$sign = static function (array $claims, array $header = []) use ($key): string {
	$input = spikeEncode(json_encode($header + ['alg' => 'RS256', 'kid' => 'fixture-key'], JSON_THROW_ON_ERROR)) . '.' . spikeEncode(json_encode($claims, JSON_THROW_ON_ERROR));
	spikeCheck(openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256), 'Fixture signing failed.');
	return $input . '.' . spikeEncode($signature);
};
$claims = ['iss' => 'https://issuer.example.invalid', 'sub' => 'fixture-subject', 'aud' => 'fixture-client', 'iat' => time(), 'exp' => time() + 120, 'nonce' => 'fixture-nonce'];
$metadata = ['issuer' => $claims['iss'], 'authorization_endpoint' => $claims['iss'] . '/auth', 'token_endpoint' => $claims['iss'] . '/token', 'jwks_uri' => $claims['iss'] . '/keys', 'response_types_supported' => ['code'], 'id_token_signing_alg_values_supported' => ['RS256']];
$http = new class($metadata, ['keys' => [$jwk]]) implements ClientInterface {
	public array $calls = [];
	public string $token = '';
	public function __construct(private array $metadata, private array $jwks) {}
	public function sendRequest(RequestInterface $request): ResponseInterface {
		$uri = (string) $request->getUri();
		$this->calls[] = [$request->getMethod(), $uri];
		$base = $this->metadata['issuer'];
		if ('GET' === $request->getMethod() && $base . '/.well-known/openid-configuration' === $uri) {
			$data = $this->metadata;
		} elseif ('GET' === $request->getMethod() && $base . '/keys' === $uri) {
			$data = $this->jwks;
		} elseif ('POST' === $request->getMethod() && $base . '/token' === $uri) {
			parse_str((string) $request->getBody(), $body);
			spikeCheck('fixture-verifier' === ($body['code_verifier'] ?? null), 'PKCE verifier missing from exchange.');
			spikeCheck('https://wp.example.invalid/callback' === ($body['redirect_uri'] ?? null), 'Callback changed.');
			spikeCheck('Basic ' . base64_encode('fixture-client:fixture-secret') === $request->getHeaderLine('Authorization'), 'Client authentication changed.');
			$data = ['access_token' => 'fixture-access', 'token_type' => 'Bearer'];
			if ('' !== $this->token) $data['id_token'] = $this->token;
		} else {
			throw new RuntimeException('Unexpected HTTP request; injection failed.');
		}
		return new Response(200, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
	}
};
$factory = new Psr17Factory();
$issuer = (new IssuerBuilder())
	->setMetadataProviderBuilder((new MetadataProviderBuilder())->setHttpClient($http)->setRequestFactory($factory)->setUriFactory($factory))
	->setJwksProviderBuilder((new JwksProviderBuilder())->withHttpClient($http)->withRequestFactory($factory))
	->build($metadata['issuer'] . '/.well-known/openid-configuration');
$client = (new ClientBuilder())->setIssuer($issuer)->setHttpClient($http)->setClientMetadata(ClientMetadata::fromArray([
	'client_id' => 'fixture-client', 'client_secret' => 'fixture-secret', 'redirect_uris' => ['https://wp.example.invalid/callback'],
	'token_endpoint_auth_method' => 'client_secret_basic', 'id_token_signed_response_alg' => 'RS256', 'response_types' => ['code'],
]))->build();
$service = (new AuthorizationServiceBuilder())->setHttpClient($http)->setRequestFactory($factory)->build();
$session = AuthSession::fromArray(['state' => 'fixture-state', 'nonce' => 'fixture-nonce', 'code_verifier' => 'fixture-verifier']);
$http->token = $sign($claims);
$result = $service->callback($client, ['code' => 'fixture-code', 'state' => 'fixture-state'], null, $session);
spikeCheck('fixture-subject' === ($result->claims()['sub'] ?? null), 'Valid RS256 callback failed.');
spikeCheck($http->calls === [
	['GET', $metadata['issuer'] . '/.well-known/openid-configuration'],
	['POST', $metadata['token_endpoint']],
	['GET', $metadata['jwks_uri']],
], 'Discovery, token and JWKS must all use the injected transport.');
echo "PASS: injected discovery/token/JWKS, RS256, client authentication and PKCE exchange.\n";

$mutations = [
	'issuer' => ['iss' => 'https://attacker.example.invalid'],
	'audience' => ['aud' => 'another-client'],
	'nonce' => ['nonce' => 'wrong-nonce'],
	'expired' => ['exp' => time() - 120],
	'future-issued' => ['iat' => time() + 120],
	'future-not-before' => ['nbf' => time() + 120],
];
foreach ($mutations as $name => $mutation) {
	$http->token = $sign(array_replace($claims, $mutation));
	try {
		$service->callback($client, ['code' => 'fixture-code'], null, $session);
		throw new RuntimeException('Invalid token accepted: ' . $name);
	} catch (VerificationFailure|InvalidHeaderException $expected) {
		echo 'PASS: rejected ' . $name . ".\n";
	}
}
foreach (['signature', 'none', 'HS256', 'unknown-kid'] as $name) {
	$header = match ($name) { 'none' => ['alg' => 'none'], 'HS256' => ['alg' => 'HS256'], 'unknown-kid' => ['kid' => 'missing-key'], default => [] };
	$http->token = $sign($claims, $header);
	if ('signature' === $name) {
		$parts = explode('.', $http->token);
		$bytes = base64_decode(strtr($parts[2], '-_', '+/'), true);
		$bytes[0] = chr(ord($bytes[0]) ^ 1);
		$http->token = $parts[0] . '.' . $parts[1] . '.' . spikeEncode($bytes);
	}
	try {
		$service->callback($client, ['code' => 'fixture-code'], null, $session);
		throw new RuntimeException('Invalid token accepted: ' . $name);
	} catch (VerificationFailure|InvalidHeaderException $expected) {
		echo 'PASS: rejected ' . $name . ".\n";
	}
}

// Record gaps, rather than treating an OAuth token or unchecked state as OIDC success.
foreach (['authorized-party' => array_replace($claims, ['azp' => 'another-client']), 'missing-nonce' => array_diff_key($claims, ['nonce' => true])] as $name => $gapClaims) {
	$http->token = $sign($gapClaims);
	spikeCheck('fixture-subject' === ($service->callback($client, ['code' => 'fixture-code'], null, $session)->claims()['sub'] ?? null), 'Known ' . $name . ' gap changed; review candidate.');
	echo 'GAP: default verifier accepted ' . $name . ".\n";
}
$http->token = '';
spikeCheck(null === $service->callback($client, ['code' => 'fixture-code'], null, $session)->getIdToken(), 'Missing-ID-token behavior changed; review integration.');
$http->token = $sign($claims);
spikeCheck('fixture-subject' === ($service->callback($client, ['code' => 'fixture-code', 'state' => 'wrong-state'], null, $session)->claims()['sub'] ?? null), 'State behavior changed; review integration.');
echo "GAPS CONFIRMED: callback() accepts absent ID token and does not compare plain callback state; application guards required.\n";

<?php
/**
 * Test-only application guards around the complete candidate OIDC service.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Spikes\Oidc;

use Facile\OpenIDClient\Client\ClientInterface;
use Facile\OpenIDClient\Service\AuthorizationService;
use Facile\OpenIDClient\Service\Builder\AuthorizationServiceBuilder;
use Facile\OpenIDClient\Session\AuthSessionInterface;
use Jose\Component\Checker\ClaimCheckerManager;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestFactoryInterface;
use RuntimeException;
use Throwable;

/** Feasibility adapter only: no browser binding, atomic consumption or session issuance. */
final class StrictCodeFlow {
	/** Candidate service with explicit verifier configuration.
	 *
	 * @var AuthorizationService
	 */
	private AuthorizationService $service;

	/** Construct the isolated service with the supplied transport.
	 *
	 * @param HttpClient              $http Injected transport.
	 * @param RequestFactoryInterface $factory Injected request factory.
	 */
	public function __construct( HttpClient $http, RequestFactoryInterface $factory ) {
		$builder = new AuthorizationServiceBuilder();
		$builder->setHttpClient( $http );
		$builder->setRequestFactory( $factory );
		$builder->setIdTokenVerifierBuilder( new StrictVerifierBuilder() );
		$this->service = $builder->build();
	}

	/** Exchange only a pre-parsed code callback for this narrow RS256/RFC9207 profile.
	 *
	 * Future production callers must first verify/consume the browser-bound transaction.
	 * Raw query parsing, duplicate parameters, HTTP limits and local policy are not here.
	 *
	 * @param ClientInterface      $client Configured candidate client.
	 * @param array<string, mixed> $callback Parsed response, not token endpoint data.
	 * @param AuthSessionInterface $session Expected transaction values from the caller.
	 * @param string               $redirect Registered callback URI.
	 * @return array<string, mixed> Library-verified claims after mandatory-claim checks.
	 * @throws RuntimeException For any callback, library or required-result failure.
	 */
	public function exchange( ClientInterface $client, array $callback, AuthSessionInterface $session, string $redirect ): array {
		try {
			$expected_state = $session->getState();
			if ( ! is_string( $expected_state ) || '' === $expected_state ) {
				throw new RuntimeException();
			}
			foreach ( array( $session->getNonce(), $session->getCodeVerifier() ) as $value ) {
				if ( ! is_string( $value ) || '' === $value ) {
					throw new RuntimeException();
				}
			}
			if ( array_diff( array_keys( $callback ), array( 'code', 'state', 'iss' ) ) ) {
				throw new RuntimeException();
			}
			foreach ( array( 'code', 'state', 'iss' ) as $field ) {
				if ( ! isset( $callback[ $field ] ) || ! is_string( $callback[ $field ] ) || '' === $callback[ $field ] ) {
					throw new RuntimeException();
				}
			}
			if ( ! hash_equals( $expected_state, $callback['state'] ) || $client->getIssuer()->getMetadata()->getIssuer() !== $callback['iss'] ) {
				throw new RuntimeException();
			}
			if ( ! in_array( $redirect, $client->getMetadata()->getRedirectUris(), true ) ) {
				throw new RuntimeException();
			}
			// Check the fixed profile before exchange, not only when the verifier is built.
			if ( 'RS256' !== ( $client->getMetadata()->toArray()['id_token_signed_response_alg'] ?? null ) ) {
				throw new RuntimeException();
			}
			$result = $this->service->callback(
				$client,
				array(
					'code'  => $callback['code'],
					'state' => $callback['state'],
				),
				$redirect,
				$session
			);
			if ( null === $result->getIdToken() || '' === $result->getIdToken() ) {
				throw new RuntimeException();
			}
			$claims    = $result->claims();
			$mandatory = array( 'iss', 'sub', 'aud', 'exp', 'iat', 'nonce' );
			if ( is_array( $claims['aud'] ?? null ) && count( $claims['aud'] ) > 1 ) {
				$mandatory[] = 'azp';
			}
			// The library verifies signature and claim values; JOSE enforces presence too.
			( new ClaimCheckerManager( array() ) )->check( $claims, $mandatory );
			return $claims;
		} catch ( Throwable ) {
			// Library messages/causes can contain expected/received claims. Do not expose them.
			throw new RuntimeException( 'OIDC evaluation exchange failed.' );
		}
	}
}

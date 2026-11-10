<?php
/**
 * Operator-controlled OIDC configuration boundary; not a protocol or SSRF validator.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Modules\OidcClient;

use RuntimeException;

/** Compare provider configuration with separately supplied operator approval. */
final class ProviderTrust {
	private const FIELDS = array( 'issuer', 'client_id', 'authorization_endpoint', 'token_endpoint', 'jwks_uri' );

	/** Accept the value of SWSL_OIDC_PROVIDER_TRUST through constructor injection.
	 *
	 * @param mixed $approvals Untrusted configuration shape, never secret material.
	 */
	public function __construct( private mixed $approvals ) {}

	/** Require exact approval of every security-relevant provider field.
	 *
	 * Optional discovery_uri must match in both records, including its absence.
	 * Display names and other non-security metadata do not affect approval.
	 *
	 * @param string               $provider_id Immutable internal instance ID.
	 * @param array<string, mixed> $configuration Candidate provider configuration.
	 * @throws RuntimeException For absent, malformed or differing approval. No values escape.
	 */
	public function assertApproved( string $provider_id, array $configuration ): void {
		if ( '' === $provider_id || ! is_array( $this->approvals ) || ! isset( $this->approvals[ $provider_id ] ) || ! is_array( $this->approvals[ $provider_id ] ) ) {
			throw new RuntimeException( 'OIDC provider approval is unavailable.' );
		}
		$approved = $this->approvals[ $provider_id ];
		$fields   = array_merge( self::FIELDS, array( 'discovery_uri' ) );
		if ( array_diff( array_keys( $approved ), $fields ) ) {
			throw new RuntimeException( 'OIDC provider approval is invalid.' );
		}
		foreach ( $fields as $field ) {
			$expected = $approved[ $field ] ?? null;
			$actual   = $configuration[ $field ] ?? null;
			if ( 'discovery_uri' === $field && ! array_key_exists( $field, $approved ) && ! array_key_exists( $field, $configuration ) ) {
				continue;
			}
			if ( ! is_string( $expected ) || '' === $expected || ! is_string( $actual ) || $expected !== $actual ) {
				throw new RuntimeException( 'OIDC provider configuration is not approved.' );
			}
			if ( 'client_id' === $field ) {
				if ( strlen( $expected ) > 1024 || preg_match( '/[\x00-\x20\x7f]/', $expected ) ) {
					throw new RuntimeException( 'OIDC client configuration is invalid.' );
				}
				continue;
			}
			$this->assertHttpsUrl( $expected, $field );
		}
	}

	/** Reject malformed URLs before subsequent network/protocol-specific validation.
	 *
	 * @param string $url Exact configured URL, never normalized for comparison.
	 * @param string $field Configuration field.
	 * @throws RuntimeException For an invalid HTTPS URL.
	 */
	private function assertHttpsUrl( string $url, string $field ): void {
		$authority = str_starts_with( $url, 'https://' ) ? substr( $url, 8, strcspn( $url, '/?#', 8 ) ) : '';
		if ( strlen( $url ) > 2048 || ! str_starts_with( $url, 'https://' ) || false === filter_var( $url, FILTER_VALIDATE_URL ) || str_contains( $authority, '@' ) || str_contains( $url, '#' ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) || ( 'issuer' === $field && str_contains( $url, '?' ) ) ) {
			throw new RuntimeException( 'OIDC provider URL is invalid.' );
		}
	}
}

<?php
/**
 * Provider-bound access to operator-supplied OIDC client secrets.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Modules\OidcClient;

use RuntimeException;

/** Release a secret only after checking the provider's operator approval. */
final class ProviderSecrets {

	/** Accept configuration supplied by the future composition root.
	 *
	 * @param ProviderTrust $trust Separately supplied operator approvals.
	 * @param mixed         $secrets Value of SWSL_OIDC_CLIENT_SECRETS; never persisted here.
	 */
	public function __construct( private ProviderTrust $trust, private mixed $secrets ) {}

	/** Read the secret belonging to this approved provider instance.
	 *
	 * Configuration cannot select another provider's secret or a constant/file name.
	 * The caller must still enforce transport and configuration revision checks.
	 *
	 * @param string               $provider_id Immutable internal provider instance ID.
	 * @param array<string, mixed> $configuration Current candidate provider configuration.
	 * @return string Exact secret bytes, for server-side token exchange only.
	 * @throws RuntimeException If approval or the provider's secret is unavailable.
	 */
	public function clientSecret( string $provider_id, array $configuration ): string {
		$this->trust->assertApproved( $provider_id, $configuration );
		if ( ! is_array( $this->secrets ) || ! isset( $this->secrets[ $provider_id ] ) || ! is_string( $this->secrets[ $provider_id ] ) || '' === $this->secrets[ $provider_id ] ) {
			throw new RuntimeException( 'OIDC client secret is unavailable.' );
		}
		return $this->secrets[ $provider_id ];
	}
}

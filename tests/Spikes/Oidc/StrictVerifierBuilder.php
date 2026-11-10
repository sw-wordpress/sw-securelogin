<?php
/**
 * Test-only configuration of the complete OIDC client's existing verifier.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Spikes\Oidc;

use Facile\JoseVerifier\Builder\IdTokenVerifierBuilder;
use Facile\JoseVerifier\IdTokenVerifierInterface;
use Facile\OpenIDClient\Client\ClientInterface;
use Facile\OpenIDClient\Token\IdTokenVerifierBuilderInterface;
use RuntimeException;

/** Keep protocol/signature checking in the candidate; explicitly configure azp. */
final class StrictVerifierBuilder implements IdTokenVerifierBuilderInterface {
	/** Configure the library's verifier for the evaluated profile.
	 *
	 * @param ClientInterface $client Configured client and approved issuer metadata.
	 * @return IdTokenVerifierInterface Library verifier with an explicit azp expectation.
	 * @throws RuntimeException For an unsupported profile.
	 */
	public function build( ClientInterface $client ): IdTokenVerifierInterface {
		$metadata = $client->getMetadata();
		if ( 'RS256' !== ( $metadata->toArray()['id_token_signed_response_alg'] ?? null ) ) {
			throw new RuntimeException( 'OIDC evaluation profile is unavailable.' );
		}
		return IdTokenVerifierBuilder::create(
			$client->getIssuer()->getMetadata()->toArray(),
			$metadata->toArray()
		)
			->withJwksProvider( $client->getIssuer()->getJwksProvider() )
			->withClientJwksProvider( $client->getJwksProvider() )
			->withExpectedAzp( $metadata->getClientId() )
			->build();
	}
}

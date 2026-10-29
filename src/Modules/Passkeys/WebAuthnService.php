<?php
/**
 * Library-backed WebAuthn enrollment adapter.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Modules\Passkeys;

defined( 'ABSPATH' ) || exit;


use lbuchs\WebAuthn\WebAuthn;
use RuntimeException;
use stdClass;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- External WebAuthn library and browser option field names.

/** Exact local origin policy complements the library's RP-suffix origin check. */
final class WebAuthnService {
	/**
	 * Library instance with none attestation and required UV.
	 *
	 * @var WebAuthn
	 */
	private WebAuthn $library;

	/** Create a none-attestation enrollment verifier.
	 *
	 * @param RelyingParty $rp Pinned site policy.
	 */
	public function __construct( private RelyingParty $rp ) {
		$this->library = new WebAuthn( 'SW Securelogin', $rp->id, array( 'none' ), true );
	}

	/** Create browser options with a library-generated CSPRNG challenge.
	 *
	 * @param string   $handle Opaque stable user handle.
	 * @param string   $name WordPress login name.
	 * @param string   $display_name Public display name.
	 * @param string[] $excluded Existing binary credential IDs.
	 * @return stdClass Options consumed by navigator.credentials.create.
	 */
	public function options( string $handle, string $name, string $display_name, array $excluded ): stdClass {
		$options = $this->library->getCreateArgs( $handle, $name, $display_name, 120, 'preferred', 'required', null, $excluded );
		// M1's tested algorithm policy: ES256 on P-256 only.
		$options->publicKey->pubKeyCredParams = array(
			(object) array(
				'type' => 'public-key',
				'alg'  => -7,
			),
		);
		return $options;
	}

	/** Return the generated challenge bytes. */
	public function challenge(): string {
		return $this->library->getChallenge()->getBinaryString();
	}

	/** Validate all enrollment data through the library plus strict site policy.
	 *
	 * @param string $client Encoded clientDataJSON.
	 * @param string $attestation Encoded attestationObject.
	 * @param string $raw_id Encoded rawId.
	 * @param string $challenge Expected server-side challenge bytes.
	 * @return VerifiedCredential Validated result.
	 * @throws RuntimeException For an unsupported context or mismatched credential ID.
	 */
	public function verify( string $client, string $attestation, string $raw_id, string $challenge ): VerifiedCredential {
		$client_binary = Base64Url::decode( $client, 4096 );
		$context       = json_decode( $client_binary, true, 16, JSON_THROW_ON_ERROR );
		if ( ! is_array( $context ) || ( $context['origin'] ?? null ) !== $this->rp->origin || ( array_key_exists( 'crossOrigin', $context ) && false !== $context['crossOrigin'] ) || array_key_exists( 'topOrigin', $context ) ) {
			throw new RuntimeException( 'Unsupported enrollment origin or embedding context.' );
		}
		$data = $this->library->processCreate( $client_binary, Base64Url::decode( $attestation, 16384 ), $challenge, true, true );
		if ( ! hash_equals( $data->credentialId, Base64Url::decode( $raw_id, 1023 ) ) || ( $data->isBackedUp && ! $data->isBackupEligible ) ) {
			throw new RuntimeException( 'Invalid credential metadata.' );
		}
		$key     = openssl_pkey_get_public( $data->credentialPublicKey );
		$details = false === $key ? false : openssl_pkey_get_details( $key );
		if ( ! is_array( $details ) || OPENSSL_KEYTYPE_EC !== $details['type'] || 'prime256v1' !== ( $details['ec']['curve_name'] ?? null ) ) {
			throw new RuntimeException( 'Unsupported or invalid public key.' );
		}
		$hex = bin2hex( $data->AAGUID );
		if ( 32 !== strlen( $hex ) ) {
			throw new RuntimeException( 'Invalid authenticator model identifier.' );
		}
		$aaguid = substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
		return new VerifiedCredential( $data->credentialId, $data->credentialPublicKey, $data->signatureCounter ?? 0, $aaguid, $data->isBackupEligible, $data->isBackedUp );
	}
}

<?php
/** Ephemeral test key and signed assertions; never used by production. */
declare(strict_types=1);

use SW\Securelogin\Modules\Passkeys\Base64Url;
use SW\Securelogin\Modules\Passkeys\VerifiedCredential;

final class AssertionFixture {
	private OpenSSLAsymmetricKey $key;
	public string $id;
	public string $handle;
	public string $publicKey;

	public function __construct() {
		$this->key = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
		$this->publicKey = openssl_pkey_get_details( $this->key )['key'];
		$this->id = random_bytes( 32 );
		$this->handle = random_bytes( 32 );
	}

	public function credential( int $counter = 0, bool $backup = false ): VerifiedCredential {
		return new VerifiedCredential( $this->id, $this->publicKey, $counter, '00000000-0000-0000-0000-000000000000', $backup, false );
	}

	public function body( array $options, array $context = array(), int $flags = 5, int $counter = 1, string $rp = 'example.test' ): array {
		$client = json_encode( array_replace( array( 'type' => 'webauthn.get', 'challenge' => $options['publicKey']['challenge'], 'origin' => 'https://example.test', 'crossOrigin' => false ), $context ), JSON_THROW_ON_ERROR );
		$authenticator = hash( 'sha256', $rp, true ) . chr( $flags ) . pack( 'N', $counter );
		openssl_sign( $authenticator . hash( 'sha256', $client, true ), $signature, $this->key, OPENSSL_ALGO_SHA256 );
		return array( 'transaction' => $options['transaction'], 'rawId' => Base64Url::encode( $this->id ), 'userHandle' => Base64Url::encode( $this->handle ), 'clientDataJSON' => Base64Url::encode( $client ), 'authenticatorData' => Base64Url::encode( $authenticator ), 'signature' => Base64Url::encode( $signature ) );
	}
}

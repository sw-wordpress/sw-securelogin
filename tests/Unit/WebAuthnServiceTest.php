<?php
/** Real Chromium fixtures through the protocol library. */
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SW\Securelogin\Modules\Passkeys\Base64Url;
use SW\Securelogin\Modules\Passkeys\RelyingParty;
use SW\Securelogin\Modules\Passkeys\WebAuthnService;

final class WebAuthnServiceTest extends TestCase {
	private function fixture(): array {
		return json_decode( file_get_contents( dirname( __DIR__ ) . '/Fixtures/WebAuthn/chromium.json' ), true, 512, JSON_THROW_ON_ERROR );
	}

	public function testChromiumRegistrationAndRequiredVerificationOptions(): void {
		$fixture = $this->fixture();
		$service = new WebAuthnService( new RelyingParty( $fixture['origin'], $fixture['rpId'] ) );
		$result = $service->verify( $fixture['clientDataJSON'], $fixture['attestationObject'], $fixture['rawId'], Base64Url::decode( $fixture['challenge'], 32 ) );
		$this->assertSame( Base64Url::decode( $fixture['rawId'], 1023 ), $result->id );
		$this->assertStringContainsString( 'PUBLIC KEY', $result->public_key );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $result->aaguid );
		$options = $service->options( random_bytes( 32 ), 'test', 'Test', array( $result->id ) );
		$this->assertSame( 'required', $options->publicKey->authenticatorSelection->userVerification );
		$this->assertSame( 'none', $options->publicKey->attestation );
		$this->assertSame( 32, strlen( $service->challenge() ) );
	}

	public static function invalidCases(): array {
		return array_map( static fn( $case ) => array( $case ), array( 'challenge', 'origin', 'port', 'subdomain', 'crossOrigin', 'crossOriginNull', 'topOrigin', 'topOriginNull', 'type', 'rawId', 'rpHash', 'uv', 'up', 'backupFlags', 'truncated' ) );
	}

	#[DataProvider( 'invalidCases' )]
	public function testInvalidEnrollmentIsRejected( string $case ): void {
		$fixture = $this->fixture();
		$client = json_decode( Base64Url::decode( $fixture['clientDataJSON'], 4096 ), true );
		$challenge = Base64Url::decode( $fixture['challenge'], 32 );
		$attestation = Base64Url::decode( $fixture['attestationObject'], 16384 );
		$hash_offset = strpos( $attestation, hash( 'sha256', $fixture['rpId'], true ) );
		$this->assertNotFalse( $hash_offset );
		switch ( $case ) {
			case 'challenge': $challenge = random_bytes( 32 ); break;
			case 'origin': $client['origin'] = 'https://evil.test'; break;
			case 'port': $client['origin'] .= ':8443'; break;
			case 'subdomain': $client['origin'] = 'https://evil.example.test'; break;
			case 'crossOriginNull': $client['crossOrigin'] = null; break;
			case 'topOriginNull': $client['topOrigin'] = null; break;
			case 'crossOrigin': $client['crossOrigin'] = true; break;
			case 'topOrigin': $client['topOrigin'] = $fixture['origin']; break;
			case 'type': $client['type'] = 'webauthn.get'; break;
			case 'rawId': $fixture['rawId'] = Base64Url::encode( random_bytes( 32 ) ); break;
			case 'rpHash': $attestation[ $hash_offset ] = chr( ord( $attestation[ $hash_offset ] ) ^ 1 ); break;
			case 'uv': $attestation[ $hash_offset + 32 ] = chr( ord( $attestation[ $hash_offset + 32 ] ) & ~4 ); break;
			case 'up': $attestation[ $hash_offset + 32 ] = chr( ord( $attestation[ $hash_offset + 32 ] ) & ~1 ); break;
			case 'backupFlags': $attestation[ $hash_offset + 32 ] = chr( ( ord( $attestation[ $hash_offset + 32 ] ) | 16 ) & ~8 ); break;
			case 'truncated': $attestation = substr( $attestation, 0, 12 ); break;
		}
		$service = new WebAuthnService( new RelyingParty( $fixture['origin'], $fixture['rpId'] ) );
		$this->expectException( Throwable::class );
		$service->verify( Base64Url::encode( json_encode( $client, JSON_THROW_ON_ERROR ) ), Base64Url::encode( $attestation ), $fixture['rawId'], $challenge );
	}

	public function testBinaryAaguidIsNormalizedForStorage(): void {
		$fixture = $this->fixture();
		$attestation = Base64Url::decode( $fixture['attestationObject'], 16384 );
		$offset = strpos( $attestation, hash( 'sha256', $fixture['rpId'], true ) );
		$this->assertNotFalse( $offset );
		$attestation = substr_replace( $attestation, str_repeat( "\xff", 16 ), $offset + 37, 16 );
		$service = new WebAuthnService( new RelyingParty( $fixture['origin'], $fixture['rpId'] ) );
		$result = $service->verify( $fixture['clientDataJSON'], Base64Url::encode( $attestation ), $fixture['rawId'], Base64Url::decode( $fixture['challenge'], 32 ) );
		$this->assertSame( 'ffffffff-ffff-ffff-ffff-ffffffffffff', $result->aaguid );
	}

	public function testCanonicalBase64UrlRejectsInvalidPaddingAndBounds(): void {
		$this->assertSame( "\0\xff", Base64Url::decode( Base64Url::encode( "\0\xff" ), 2 ) );
		foreach ( array( 'YQ==', 'YQ+', 'YR', '', 'YWFh' ) as $value ) {
			try {
				Base64Url::decode( $value, 1 );
				$this->fail( 'Invalid binary input was accepted.' );
			} catch ( InvalidArgumentException $exception ) {
				$this->assertNotSame( '', $exception->getMessage() );
			}
		}
	}
}

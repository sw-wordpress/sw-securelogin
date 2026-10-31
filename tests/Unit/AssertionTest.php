<?php
/** Real cryptographic assertion verification, including signed negative inputs. */
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SW\Securelogin\Modules\Passkeys\Base64Url;
use SW\Securelogin\Modules\Passkeys\RelyingParty;
use SW\Securelogin\Modules\Passkeys\WebAuthnService;

require_once dirname( __DIR__ ) . '/Support/AssertionFixture.php';

final class AssertionTest extends TestCase {
	private function service(): WebAuthnService {
		return new WebAuthnService( new RelyingParty( 'https://example.test', 'example.test' ) );
	}

	public function testOptionsHideCredentialsAndRequireDiscoverableEnrollment(): void {
		$service = $this->service();
		$first = json_decode( json_encode( $service->assertionOptions() ), true );
		$this->assertArrayNotHasKey( 'allowCredentials', $first['publicKey'] );
		$this->assertSame( 'required', $first['publicKey']['userVerification'] );
		$this->assertSame( 'example.test', $first['publicKey']['rpId'] );
		$second = json_decode( json_encode( $service->assertionOptions() ), true );
		$this->assertNotSame( $first['publicKey']['challenge'], $second['publicKey']['challenge'] );
		$creation = $service->options( random_bytes( 32 ), 'user', 'User', array() );
		$this->assertSame( 'required', $creation->publicKey->authenticatorSelection->residentKey );
	}

	public function testExistingChromiumAssertionPassesAdapter(): void {
		$f = json_decode( file_get_contents( dirname( __DIR__ ) . '/Fixtures/WebAuthn/chromium.json' ), true );
		$service = new WebAuthnService( new RelyingParty( $f['origin'], $f['rpId'] ) );
		$credential = $service->verify( $f['clientDataJSON'], $f['attestationObject'], $f['rawId'], Base64Url::decode( $f['challenge'], 32 ) );
		$row = array( 'credential_id' => $credential->id, 'user_handle' => Base64Url::decode( $f['handle'], 32 ), 'public_key' => $credential->public_key, 'backup_eligible' => $credential->backup_eligible );
		$result = $service->assertion( $f['assertion'] + array( 'rawId' => $f['rawId'], 'userHandle' => $f['handle'] ), $row, Base64Url::decode( $f['challenge'], 32 ) );
		$this->assertGreaterThanOrEqual( 0, $result->sign_count );
	}

	public static function mutations(): array {
		return array_map( static fn( $value ) => array( $value ), array_combine( array( 'challenge', 'origin', 'subdomain', 'port', 'crossOrigin', 'crossOriginNull', 'topOrigin', 'type', 'rpHash', 'uv', 'up', 'signature', 'wrongKey', 'rawId', 'userHandle', 'backupChanged', 'invalidBackup', 'truncated', 'oversized', 'noncanonical' ), array( 'challenge', 'origin', 'subdomain', 'port', 'crossOrigin', 'crossOriginNull', 'topOrigin', 'type', 'rpHash', 'uv', 'up', 'signature', 'wrongKey', 'rawId', 'userHandle', 'backupChanged', 'invalidBackup', 'truncated', 'oversized', 'noncanonical' ) ) );
	}

	#[DataProvider('mutations')]
	public function testSignedAndTransportMutationsAreRejected( string $mutation ): void {
		$fixture = new AssertionFixture();
		$challenge = random_bytes( 32 );
		$options = array( 'transaction' => 'unused', 'publicKey' => array( 'challenge' => Base64Url::encode( $challenge ) ) );
		$context = match ( $mutation ) {
			'challenge' => array( 'challenge' => Base64Url::encode( random_bytes( 32 ) ) ),
			'origin' => array( 'origin' => 'https://evil.test' ),
			'subdomain' => array( 'origin' => 'https://evil.example.test' ),
			'port' => array( 'origin' => 'https://example.test:8443' ),
			'crossOrigin' => array( 'crossOrigin' => true ),
			'crossOriginNull' => array( 'crossOrigin' => null ),
			'topOrigin' => array( 'topOrigin' => 'https://example.test' ),
			'type' => array( 'type' => 'webauthn.create' ),
			default => array(),
		};
		$flags = match ( $mutation ) { 'uv' => 1, 'up' => 4, 'backupChanged' => 13, 'invalidBackup' => 21, default => 5 };
		$body = $fixture->body( $options, $context, $flags, 1, 'rpHash' === $mutation ? 'evil.test' : 'example.test' );
		$row = array( 'credential_id' => $fixture->id, 'user_handle' => $fixture->handle, 'public_key' => $fixture->publicKey, 'backup_eligible' => false );
		switch ( $mutation ) {
			case 'signature': $body['signature'] = Base64Url::encode( random_bytes( 70 ) ); break;
			case 'wrongKey': $row['public_key'] = (new AssertionFixture())->publicKey; break;
			case 'rawId': $body['rawId'] = Base64Url::encode( random_bytes( 32 ) ); break;
			case 'userHandle': $body['userHandle'] = Base64Url::encode( random_bytes( 32 ) ); break;
			case 'truncated': $body['authenticatorData'] = Base64Url::encode( random_bytes( 10 ) ); break;
			case 'oversized': $body['clientDataJSON'] = Base64Url::encode( str_repeat( 'x', 4097 ) ); break;
			case 'noncanonical': $body['signature'] .= '='; break;
		}
		$this->expectException( Throwable::class );
		$this->service()->assertion( $body, $row, $challenge );
	}
}

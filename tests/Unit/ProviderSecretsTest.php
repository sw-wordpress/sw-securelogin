<?php
/** Provider secret isolation tests using synthetic secrets only. */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SW\Securelogin\Modules\OidcClient\ProviderSecrets;
use SW\Securelogin\Modules\OidcClient\ProviderTrust;

final class ProviderSecretsTest extends TestCase {
	private function configuration(): array {
		return array(
			'issuer' => 'https://issuer.example.test',
			'client_id' => 'fixture-client',
			'authorization_endpoint' => 'https://issuer.example.test/auth',
			'token_endpoint' => 'https://issuer.example.test/token',
			'jwks_uri' => 'https://issuer.example.test/keys',
		);
	}

	private function secrets( mixed $values ): ProviderSecrets {
		return new ProviderSecrets( new ProviderTrust( array( 'first' => $this->configuration(), 'second' => $this->configuration() ) ), $values );
	}

	private function assertRejected( ProviderSecrets $secrets, string $id, array $configuration ): void {
		try {
			$secrets->clientSecret( $id, $configuration );
		} catch ( RuntimeException $error ) {
			$this->assertStringNotContainsString( 'fixture-secret', $error->getMessage() );
			$this->assertStringNotContainsString( 'example.test', $error->getMessage() );
			$this->assertStringNotContainsString( 'fixture-client', $error->getMessage() );
			return;
		}
		$this->fail( 'Secret access should have failed.' );
	}

	public function testEachProviderReceivesOnlyItsOwnExactSecretBytes(): void {
		$values = array( 'first' => " fixture-secret+/%=\n", 'second' => 'second-fixture-secret' );
		$secrets = $this->secrets( $values );
		foreach ( $values as $id => $value ) {
			$this->assertSame( $value, $secrets->clientSecret( $id, $this->configuration() ) );
		}
		$this->assertSame( '0', $this->secrets( array( 'first' => '0' ) )->clientSecret( 'first', $this->configuration() ) );
	}

	public function testMissingAndMalformedSecretsFailWithoutBorrowingAnotherProviderSecret(): void {
		foreach ( array( null, false, 'fixture-secret', array(), array( 'second' => 'fixture-secret' ) ) as $values ) {
			$this->assertRejected( $this->secrets( $values ), 'first', $this->configuration() );
		}
		foreach ( array( null, false, 123, array( 'fixture-secret' ), '', new stdClass() ) as $value ) {
			$this->assertRejected( $this->secrets( array( 'first' => $value, 'second' => 'fixture-secret' ) ), 'first', $this->configuration() );
		}
	}

	public function testSecretCannotBeReadWithoutMatchingOperatorApproval(): void {
		$secrets = $this->secrets( array( 'first' => 'fixture-secret', 'unapproved' => 'fixture-secret' ) );
		$this->assertRejected( $secrets, 'unapproved', $this->configuration() );
		$this->assertRejected( $secrets, '', $this->configuration() );
		foreach ( array_keys( $this->configuration() ) as $field ) {
			$candidate = $this->configuration();
			$candidate[ $field ] = 'https://attacker.example.test';
			$this->assertRejected( $secrets, 'first', $candidate );
		}
	}

	public function testApprovalIsCheckedOnEveryReadIncludingDiscoveryModeChanges(): void {
		$secrets = $this->secrets( array( 'first' => 'fixture-secret' ) );
		$this->assertSame( 'fixture-secret', $secrets->clientSecret( 'first', $this->configuration() ) );
		$this->assertRejected( $secrets, 'first', $this->configuration() + array( 'discovery_uri' => 'https://issuer.example.test/discovery' ) );
		$candidate = $this->configuration();
		unset( $candidate['token_endpoint'] );
		$this->assertRejected( $secrets, 'first', $candidate );
	}

	public function testMutableMetadataCannotSelectASecretReference(): void {
		$secrets = $this->secrets( array( 'first' => 'first-fixture-secret', 'second' => 'second-fixture-secret' ) );
		$candidate = $this->configuration() + array( 'secret_ref' => 'second', 'client_secret' => 'injected-fixture-secret' );
		$this->assertSame( 'first-fixture-secret', $secrets->clientSecret( 'first', $candidate ) );
		$this->assertRejected( $this->secrets( array( 'second' => 'second-fixture-secret' ) ), 'first', $candidate );
	}

	public function testMalformedApprovalRejectsAnOtherwiseAvailableSecret(): void {
		foreach ( array( null, array(), array( 'first' => $this->configuration() + array( 'client_secret' => 'fixture-secret' ) ) ) as $approval ) {
			$this->assertRejected( new ProviderSecrets( new ProviderTrust( $approval ), array( 'first' => 'fixture-secret' ) ), 'first', $this->configuration() );
		}
	}
}

<?php
/** Security-boundary tests without WordPress, network requests or real secrets. */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SW\Securelogin\Modules\OidcClient\ProviderTrust;

final class ProviderTrustTest extends TestCase {
	private function approved(): array {
		return array(
			'issuer' => 'https://accounts.example.test',
			'client_id' => 'fixture-client',
			'authorization_endpoint' => 'https://login.example.test/auth',
			'token_endpoint' => 'https://tokens.example.test/token',
			'jwks_uri' => 'https://keys.example.test/certs',
		);
	}

	private function rejects( mixed $approval, array $candidate, string $id = 'fixture-instance' ): void {
		try {
			( new ProviderTrust( $approval ) )->assertApproved( $id, $candidate );
		} catch ( RuntimeException $error ) {
			$this->assertStringNotContainsString( 'example.test', $error->getMessage() );
			$this->assertStringNotContainsString( 'fixture-client', $error->getMessage() );
			return;
		}
		$this->fail( 'Unapproved configuration was accepted.' );
	}

	public function testExactApprovalAllowsSeparateProviderHostsAndUnrelatedMetadata(): void {
		$record = $this->approved();
		( new ProviderTrust( array( 'fixture-instance' => $record ) ) )->assertApproved( 'fixture-instance', $record + array( 'name' => 'Display name' ) );
		$this->addToAssertionCount( 1 );
	}

	public function testEveryProtectedFieldRejectsSubstitutionMissingAndWrongTypes(): void {
		$record = $this->approved();
		foreach ( array_keys( $record ) as $field ) {
			foreach ( array( 'https://attacker.example.test', '', null, array(), 1 ) as $value ) {
				$candidate = $record;
				$candidate[ $field ] = $value;
				$this->rejects( array( 'fixture-instance' => $record ), $candidate );
			}
			$candidate = $record;
			unset( $candidate[ $field ] );
			$this->rejects( array( 'fixture-instance' => $record ), $candidate );
		}
	}

	public function testUnknownProviderCannotBorrowAnotherProvidersApproval(): void {
		foreach ( array( 'other-instance', '' ) as $id ) {
			$this->rejects( array( 'fixture-instance' => $this->approved() ), $this->approved(), $id );
		}
	}

	public function testMalformedOperatorConfigurationFailsClosed(): void {
		foreach ( array( null, false, 'value', array(), array( 'fixture-instance' => 'invalid' ), array( 'fixture-instance' => array() ) ) as $value ) {
			$this->rejects( $value, $this->approved() );
		}
		$record = $this->approved() + array( 'client_secret' => 'must-not-be-here' );
		$this->rejects( array( 'fixture-instance' => $record ), $this->approved() );
	}

	public function testDiscoveryCannotBeAddedRemovedOrSubstitutedByConfiguration(): void {
		$manual = $this->approved();
		$discovery = $manual + array( 'discovery_uri' => 'https://accounts.example.test/.well-known/openid-configuration' );
		( new ProviderTrust( array( 'fixture-instance' => $discovery ) ) )->assertApproved( 'fixture-instance', $discovery );
		$this->rejects( array( 'fixture-instance' => $manual ), $discovery );
		$this->rejects( array( 'fixture-instance' => $discovery ), $manual );
		$this->rejects( array( 'fixture-instance' => $manual ), $manual + array( 'discovery_uri' => null ) );
	}

	public function testEvenMatchingApprovalCannotAuthorizeMalformedUrls(): void {
		foreach ( array( 'x', 'https://', 'http://example.test', 'https://user:secret@example.test', 'https://example.test/#fragment', 'https://example.test/with space', "https://example.test/\nheader", 'https://example.test\\evil/path', 'https://example.test:99999/path' ) as $url ) {
			$record = $this->approved();
			$record['token_endpoint'] = $url;
			$this->rejects( array( 'fixture-instance' => $record ), $record );
		}
		$record = $this->approved();
		$record['issuer'] .= '?issuer=other';
		$this->rejects( array( 'fixture-instance' => $record ), $record );
	}

	public function testUrlComparisonDoesNotNormalizePathsOrQueries(): void {
		$record = $this->approved();
		foreach ( array( '/token/', '/%74oken', '/token?extra=1' ) as $path ) {
			$candidate = $record;
			$candidate['token_endpoint'] = 'https://tokens.example.test' . $path;
			$this->rejects( array( 'fixture-instance' => $record ), $candidate );
		}
	}

	public function testClientIdAndUrlLimitsRejectInvalidOperatorInput(): void {
		foreach ( array( '', "client\n", str_repeat( 'a', 1025 ) ) as $client ) {
			$record = $this->approved();
			$record['client_id'] = $client;
			$this->rejects( array( 'fixture-instance' => $record ), $record );
		}
		$record = $this->approved();
		$record['token_endpoint'] .= str_repeat( 'a', 2049 );
		$this->rejects( array( 'fixture-instance' => $record ), $record );
	}
}

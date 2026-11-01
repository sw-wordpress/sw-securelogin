<?php
/** Fail-closed behaviour found while raising PHPStan to level 8. */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SW\Securelogin\Auth\AuthPolicy;
use SW\Securelogin\Auth\LoginSessionIssuer;
use SW\Securelogin\Modules\Passkeys\CredentialRepository;
use SW\Securelogin\Modules\Passkeys\PrivacyController;
use SW\Securelogin\Security\RateLimiter;

final class StaticAnalysisHardeningTest extends TestCase {
	/** @return wpdb&object{swsl_queries: int} Database whose prepare() fails the way wpdb does for invalid placeholders. */
	private function failingDatabase() {
		$database = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
			public int $swsl_queries = 0;
			public bool $swsl_failing = false;

			public function prepare( $query, ...$args ) {
				return $this->swsl_failing ? null : parent::prepare( $query, ...$args );
			}

			public function query( $query ) {
				if ( ! $this->swsl_failing ) {
					return parent::query( $query );
				}
				++$this->swsl_queries;
				return 1;
			}
		};
		$database->swsl_failing = true;
		return $database;
	}

	public function testRateLimiterDeniesWhenTheStatementCannotBePrepared(): void {
		$database = $this->failingDatabase();
		$this->assertFalse( ( new RateLimiter( $database ) )->allow( 'login', 1, 5, 60 ) );
		$this->assertSame( 0, $database->swsl_queries );
	}

	public function testHandleCreationFailsWithoutRunningAnEmptyStatement(): void {
		$database = $this->failingDatabase();
		$this->expectException( RuntimeException::class );
		try {
			( new CredentialRepository( $database ) )->handle( 1 );
		} finally {
			$this->assertSame( 0, $database->swsl_queries );
		}
	}

	public function testCleanupSkipsStatementsThatCannotBePrepared(): void {
		$database = $this->failingDatabase();
		( new PrivacyController( $database, new CredentialRepository( $database ) ) )->cleanup();
		$this->assertSame( 0, $database->swsl_queries );
	}

	public function testRedirectFallsBackWhenTheAdminUrlHasNoPath(): void {
		$filter = static fn(): string => 'https://example.org';
		add_filter( 'admin_url', $filter );
		try {
			$this->assertSame( 'https://example.org', ( new LoginSessionIssuer( new AuthPolicy() ) )->redirect( 'https://example.org/anything' ) );
		} finally {
			remove_filter( 'admin_url', $filter );
		}
	}
}

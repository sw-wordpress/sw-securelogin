<?php
/** Real WordPress and database migration regressions. */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SW\Securelogin\Core\MigrationInterface;
use SW\Securelogin\Core\MigrationRunner;
use SW\Securelogin\Core\Migrations\InitializeSettings;

final class MigrationRunnerTest extends TestCase {
	protected function setUp(): void {
		delete_option( 'swsl_schema_version' );
		delete_option( 'swsl_settings' );
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS swsltest_swsl_migration_fixture' );
		delete_option( 'swsl_schema_version' );
		delete_option( 'swsl_settings' );
	}

	private function migration( Closure $callback ): MigrationInterface {
		return new class( $callback ) implements MigrationInterface {
			public function __construct( private Closure $callback ) {}
			public function apply(): void {
				( $this->callback )();
			}
		};
	}

	public function testInitialSettingsAreDisabledAndExistingSettingsArePreserved(): void {
		global $wpdb;
		$runner = new MigrationRunner( $wpdb, array( 1 => new InitializeSettings() ) );
		$this->assertTrue( $runner->run() );
		$this->assertSame( array( 'enabled_modules' => array() ), get_option( 'swsl_settings' ) );
		update_option( 'swsl_settings', array( 'enabled_modules' => array( 'future-module' ) ) );
		$this->assertTrue( $runner->run() );
		$this->assertSame( array( 'future-module' ), get_option( 'swsl_settings' )['enabled_modules'] );
	}

	public function testDbDeltaUpgradePreservesRowsAndRunsOnlyOnce(): void {
		global $wpdb;
		$calls = 0;
		$first = $this->migration( static function () use ( $wpdb ): void {
			dbDelta( "CREATE TABLE {$wpdb->prefix}swsl_migration_fixture (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 label varchar(100) NOT NULL,
 PRIMARY KEY  (id)
) {$wpdb->get_charset_collate()};" );
			if ( 'swsltest_swsl_migration_fixture' !== $wpdb->get_var( "SHOW TABLES LIKE 'swsltest_swsl_migration_fixture'" ) ) {
				throw new RuntimeException( 'Fixture schema was not created.' );
			}
		} );
		$this->assertTrue( ( new MigrationRunner( $wpdb, array( 1 => $first ) ) )->run() );
		$wpdb->insert( 'swsltest_swsl_migration_fixture', array( 'label' => 'preserve me' ) );
		$second = $this->migration( static function () use ( $wpdb, &$calls ): void {
			++$calls;
			dbDelta( "CREATE TABLE {$wpdb->prefix}swsl_migration_fixture (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 label varchar(100) NOT NULL,
 status varchar(20) NOT NULL DEFAULT 'ready',
 PRIMARY KEY  (id)
) {$wpdb->get_charset_collate()};" );
			if ( 'ready' !== $wpdb->get_var( 'SELECT status FROM swsltest_swsl_migration_fixture LIMIT 1' ) ) {
				throw new RuntimeException( 'Fixture upgrade failed.' );
			}
		} );
		$runner = new MigrationRunner( $wpdb, array( 1 => $first, 2 => $second ) );
		$this->assertTrue( $runner->run() );
		$this->assertTrue( $runner->run() );
		$this->assertSame( 1, $calls );
		$this->assertSame( 'preserve me', $wpdb->get_var( 'SELECT label FROM swsltest_swsl_migration_fixture LIMIT 1' ) );
		$this->assertSame( '2', (string) get_option( 'swsl_schema_version' ) );
	}

	public function testFailureStopsSequenceAndRetryResumesAtFailedVersion(): void {
		global $wpdb;
		$attempts = 0;
		$last = 0;
		$second = $this->migration( static function () use ( &$attempts ): void {
			if ( 1 === ++$attempts ) {
				throw new RuntimeException( 'sensitive SQL must not escape' );
			}
		} );
		$third = $this->migration( static function () use ( &$last ): void { ++$last; } );
		$runner = new MigrationRunner( $wpdb, array( 1 => new InitializeSettings(), 2 => $second, 3 => $third ) );
		$result = $runner->run();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'swsl_migration_failed', $result->get_error_code() );
		$this->assertStringNotContainsString( 'sensitive SQL', $result->get_error_message() );
		$this->assertSame( '1', (string) get_option( 'swsl_schema_version' ) );
		$this->assertSame( 0, $last );
		$this->assertTrue( $runner->run() );
		$this->assertSame( 1, $last );
		$this->assertSame( '3', (string) get_option( 'swsl_schema_version' ) );
	}

	public function testCompetingConnectionCannotRunMigrations(): void {
		global $wpdb;
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$lock = 'swsl_' . hash( 'sha224', DB_NAME . ':' . $wpdb->prefix );
		$this->assertSame( '1', $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) );
		try {
			$result = ( new MigrationRunner( $wpdb, array( 1 => new InitializeSettings() ) ) )->run();
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'swsl_migration_busy', $result->get_error_code() );
			$this->assertFalse( get_option( 'swsl_settings' ) );
		} finally {
			$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			$other->close();
		}
		$this->assertTrue( ( new MigrationRunner( $wpdb, array( 1 => new InitializeSettings() ) ) )->run() );
	}

	public function testNewerAndMalformedVersionsFailClosed(): void {
		global $wpdb;
		foreach ( array( '99', 'garbage', '-1', '01' ) as $version ) {
			update_option( 'swsl_schema_version', $version, false );
			$result = ( new MigrationRunner( $wpdb, array( 1 => new InitializeSettings() ) ) )->run();
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertFalse( get_option( 'swsl_settings' ) );
		}
	}

	public function testVersionWriteFailureDoesNotReportSuccess(): void {
		global $wpdb;
		update_option( 'swsl_schema_version', '0', false );
		$reject = static fn( $new, $old ) => $old;
		add_filter( 'pre_update_option_swsl_schema_version', $reject, 10, 2 );
		try {
			$result = ( new MigrationRunner( $wpdb, array( 1 => new InitializeSettings() ) ) )->run();
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( '0', (string) get_option( 'swsl_schema_version' ) );
		} finally {
			remove_filter( 'pre_update_option_swsl_schema_version', $reject );
		}
		$this->assertTrue( ( new MigrationRunner( $wpdb, array( 1 => new InitializeSettings() ) ) )->run() );
	}

	public function testMalformedSettingsStopInitialization(): void {
		global $wpdb;
		foreach ( array( 'invalid', array( 'enabled_modules' => array( 123 ) ), array( 'enabled_modules' => array( 'bad' => 'passkeys' ) ) ) as $settings ) {
			update_option( 'swsl_settings', $settings );
			$result = ( new MigrationRunner( $wpdb, array( 1 => new InitializeSettings() ) ) )->run();
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertFalse( get_option( 'swsl_schema_version' ) );
			$this->assertSame( $settings, get_option( 'swsl_settings' ) );
		}
	}

	public function testInvalidMigrationSequenceIsRejected(): void {
		global $wpdb;
		$this->expectException( InvalidArgumentException::class );
		new MigrationRunner( $wpdb, array( 2 => new InitializeSettings() ) );
	}
}

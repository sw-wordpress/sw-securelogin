<?php
/** Explicit uninstall consent and destructive scope against disposable WordPress. */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use SW\Securelogin\Admin\SettingsPage;
use SW\Securelogin\Core\MigrationRunner;
use SW\Securelogin\Core\Migrations\InitializeSettings;
use SW\Securelogin\Modules\Passkeys\PasskeysSchema;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RemovalPolicyTest extends TestCase {
	protected function setUp(): void {
		global $wpdb;
		wp_set_current_user( get_user_by( 'login', 'swsl-test-admin' )->ID );
		delete_option( 'swsl_delete_data_on_uninstall' );
		delete_option( 'swsl_schema_version' );
		$this->assertTrue( ( new MigrationRunner( $wpdb, array( 1 => new InitializeSettings(), 2 => new PasskeysSchema() ) ) )->run() );
		add_filter( 'wp_redirect', static fn() => false );
		add_filter( 'wp_die_handler', static fn() => static function ( $message, $title, $args ): never {
			throw new RuntimeException( (string) $message, $args['response'] ?? 500 );
		} );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array();
		$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'swsl_uninstall_settings' ) );
	}

	protected function tearDown(): void {
		global $wpdb;
		delete_option( 'swsl_delete_data_on_uninstall' );
		delete_option( 'swsl_unrelated_fixture' );
		$wpdb->query( 'DROP TABLE IF EXISTS swsltest_swsl_unrelated_fixture' );
		// Restore the schema for subsequent suites after destructive fixture tests.
		delete_option( 'swsl_schema_version' );
		( new MigrationRunner( $wpdb, array( 1 => new InitializeSettings(), 2 => new PasskeysSchema() ) ) )->run();
	}

	private function uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'sw-securelogin/sw-securelogin.php' );
		}
		$root = dirname( ( new ReflectionClass( SettingsPage::class ) )->getFileName(), 3 );
		require $root . '/uninstall.php';
	}

	public function testAdministratorCanEnableAndWithdrawConsentWithoutDeletingData(): void {
		global $wpdb;
		$page = new SettingsPage();
		$page->enqueue( 'plugins.php' );
		$this->assertFalse( wp_script_is( 'swsl-settings', 'enqueued' ) );
		$page->enqueue( 'toplevel_page_swsl-overview' );
		$this->assertSame( SWSL_VERSION, wp_scripts()->registered['swsl-settings']->ver );
		ob_start();
		$page->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'id="swsl-removal-settings"', $html );
		$this->assertDoesNotMatchRegularExpression( '/id="swsl-delete-data"[^>]*checked=/', $html );
		$_POST['delete_data'] = '1';
		$page->saveRemovalSettings();
		$this->assertSame( '1', get_option( 'swsl_delete_data_on_uninstall' ) );
		$this->assertSame( 'swsltest_swsl_passkeys', $wpdb->get_var( "SHOW TABLES LIKE 'swsltest_swsl_passkeys'" ) );
		$_POST = array();
		$page->saveRemovalSettings();
		$this->assertFalse( get_option( 'swsl_delete_data_on_uninstall' ) );
	}

	public function testUnauthorizedWrongMethodNonceAndMalformedInputCannotEnableDeletion(): void {
		$admin = get_current_user_id();
		$cases = array( 'anonymous', 'method', 'nonce', 'array', 'unexpected' );
		foreach ( $cases as $case ) {
			wp_set_current_user( 'anonymous' === $case ? 0 : $admin );
			$_SERVER['REQUEST_METHOD'] = 'method' === $case ? 'GET' : 'POST';
			$_REQUEST['_wpnonce'] = 'nonce' === $case ? 'invalid' : wp_create_nonce( 'swsl_uninstall_settings' );
			$_POST['delete_data'] = 'array' === $case ? array( '1' ) : ( 'unexpected' === $case ? 'yes' : '1' );
			try {
				( new SettingsPage() )->saveRemovalSettings();
				$this->fail( 'Invalid request accepted: ' . $case );
			} catch ( RuntimeException ) {
				$this->assertFalse( get_option( 'swsl_delete_data_on_uninstall' ), $case );
			}
		}
	}

	public function testFailedConsentWriteReportsFailure(): void {
		$_POST['delete_data'] = '1';
		add_filter( 'pre_update_option_swsl_delete_data_on_uninstall', static fn() => false );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionCode( 500 );
		( new SettingsPage() )->saveRemovalSettings();
	}

	public function testFailedConsentWithdrawalReportsFailureDespiteOptionCache(): void {
		update_option( 'swsl_delete_data_on_uninstall', '1', false );
		$reject = static fn( $sql ) => str_starts_with( $sql, 'DELETE FROM' ) && str_contains( $sql, "'swsl_delete_data_on_uninstall'" ) ? 'DELETE FROM swsltest_options WHERE 1 = 0' : $sql;
		add_filter( 'query', $reject );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionCode( 500 );
		try {
			( new SettingsPage() )->saveRemovalSettings();
		} catch ( RuntimeException $error ) {
			$this->assertSame( '1', get_option( 'swsl_delete_data_on_uninstall' ), 'Failed withdrawal must not leave consent hidden by a cached miss.' );
			throw $error;
		} finally {
			remove_filter( 'query', $reject );
		}
	}

	public function testDefaultAndMalformedConsentRetainDataAndRemoveCron(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'swsl_user_handles', array( 'user_id' => 987654, 'handle' => random_bytes( 32 ) ) );
		foreach ( array( false, '0', 'yes', array( '1' ), 1 ) as $choice ) {
			if ( false === $choice ) delete_option( 'swsl_delete_data_on_uninstall' );
			else update_option( 'swsl_delete_data_on_uninstall', $choice, false );
			wp_schedule_event( time() + 300, 'hourly', 'swsl_cleanup' );
			$this->uninstall();
			$this->assertFalse( wp_next_scheduled( 'swsl_cleanup' ) );
			$this->assertNotFalse( get_option( 'swsl_schema_version' ) );
			$this->assertSame( '987654', $wpdb->get_var( "SELECT user_id FROM {$wpdb->prefix}swsl_user_handles WHERE user_id = 987654" ) );
		}
		$wpdb->delete( $wpdb->prefix . 'swsl_user_handles', array( 'user_id' => 987654 ) );
	}

	public function testExplicitUninstallRemovesOnlyOwnedTablesAndOptions(): void {
		global $wpdb;
		$admin = get_current_user_id();
		$sessions = WP_Session_Tokens::get_instance( $admin );
		$token = $sessions->create( time() + 300 );
		$wpdb->query( 'CREATE TABLE swsltest_swsl_unrelated_fixture (id int)' );
		update_option( 'swsl_unrelated_fixture', 'keep' );
		update_option( 'swsl_passkeys_rp', array( 'id' => 'example.test' ) );
		update_option( 'swsl_delete_data_on_uninstall', '1', false );
		try {
			$this->uninstall();
			foreach ( array( 'passkeys', 'user_handles', 'transactions', 'limits' ) as $suffix ) {
				$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'swsl_' . $suffix ) ) ) );
			}
			foreach ( array( 'swsl_settings', 'swsl_schema_version', 'swsl_passkeys_rp', 'swsl_delete_data_on_uninstall' ) as $option ) {
				$this->assertFalse( get_option( $option ) );
			}
			$this->assertSame( 'keep', get_option( 'swsl_unrelated_fixture' ) );
			$this->assertSame( 'swsltest_swsl_unrelated_fixture', $wpdb->get_var( "SHOW TABLES LIKE 'swsltest_swsl_unrelated_fixture'" ) );
			$this->assertTrue( $sessions->verify( $token ) );
			$this->assertInstanceOf( WP_User::class, wp_authenticate( 'swsl-test-admin', 'swsl-disposable-test-password' ) );
			// A repeated uninstall after a completed deletion is harmless.
			$this->uninstall();
		} finally {
			$sessions->destroy( $token );
		}
	}

	public function testDeactivationRetainsDataEvenWithRemovalEnabled(): void {
		global $wpdb;
		$plugin = 'sw-securelogin/sw-securelogin.php';
		$wpdb->insert( $wpdb->prefix . 'swsl_user_handles', array( 'user_id' => 987654, 'handle' => random_bytes( 32 ) ) );
		update_option( 'swsl_delete_data_on_uninstall', '1', false );
		try {
			deactivate_plugins( $plugin );
			$this->assertFalse( is_plugin_active( $plugin ) );
			$this->assertSame( '1', get_option( 'swsl_delete_data_on_uninstall' ) );
			$this->assertSame( '987654', $wpdb->get_var( "SELECT user_id FROM {$wpdb->prefix}swsl_user_handles WHERE user_id = 987654" ) );
		} finally {
			$this->assertNull( activate_plugin( $plugin ) );
			$wpdb->delete( $wpdb->prefix . 'swsl_user_handles', array( 'user_id' => 987654 ) );
		}
	}

	public function testTableRemovalFailurePreservesConsentAndSchemaForRetry(): void {
		global $wpdb;
		update_option( 'swsl_delete_data_on_uninstall', '1', false );
		$reject = static fn( $sql ) => str_starts_with( $sql, 'DROP TABLE IF EXISTS' ) ? 'INVALID FIXTURE SQL' : $sql;
		add_filter( 'query', $reject );
		$previous = $wpdb->suppress_errors();
		try {
			$this->uninstall();
			$this->fail( 'Failed removal must be reported.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'data removal failed', $error->getMessage() );
			$this->assertSame( '1', get_option( 'swsl_delete_data_on_uninstall' ) );
			$this->assertSame( '2', (string) get_option( 'swsl_schema_version' ) );
		} finally {
			remove_filter( 'query', $reject );
			$wpdb->suppress_errors( $previous );
		}
		$this->uninstall();
		$this->assertFalse( get_option( 'swsl_delete_data_on_uninstall' ) );
	}

	public function testOptionRemovalFailureIsReportedAndCanBeRetried(): void {
		global $wpdb;
		update_option( 'swsl_delete_data_on_uninstall', '1', false );
		$reject = static fn( $sql ) => str_starts_with( $sql, 'DELETE FROM' ) && str_contains( $sql, "'swsl_delete_data_on_uninstall'" ) ? 'DELETE FROM swsltest_options WHERE 1 = 0' : $sql;
		add_filter( 'query', $reject );
		try {
			$this->uninstall();
			$this->fail( 'Failed option removal must be reported.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'data removal failed', $error->getMessage() );
			$this->assertSame( '1', get_option( 'swsl_delete_data_on_uninstall' ) );
		} finally {
			remove_filter( 'query', $reject );
		}
		$this->uninstall();
		$this->assertFalse( get_option( 'swsl_settings' ) );
	}
}

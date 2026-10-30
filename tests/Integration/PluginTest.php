<?php
/** WordPress lifecycle and access-control integration tests. */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SW\Securelogin\Admin\SettingsPage;
use SW\Securelogin\Core\Plugin;

final class PluginTest extends TestCase {
	protected function setUp(): void {
		delete_option( 'swsl_settings' );
		delete_option( 'swsl_schema_version' );
		wp_set_current_user( 0 );
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'swsl_settings' );
		delete_option( 'swsl_schema_version' );
	}

	public function testUnauthorizedRequestsCannotInitializeSettingsOrRenderOverview(): void {
		( new Plugin() )->upgrade();
		$this->assertFalse( get_option( 'swsl_settings' ) );
		$this->assertFalse( get_option( 'swsl_schema_version' ) );
		$this->expectOutputString( '' );
		( new SettingsPage() )->render();
	}

	public function testAdministratorCanInitializeSettingsAndRenderOverview(): void {
		$user = get_user_by( 'login', 'swsl-test-admin' );
		$this->assertInstanceOf( WP_User::class, $user );
		wp_set_current_user( $user->ID );
		( new Plugin() )->upgrade();
		$this->assertSame( '1', (string) get_option( 'swsl_schema_version' ) );
		$this->assertSame( array( 'enabled_modules' => array() ), get_option( 'swsl_settings' ) );
		ob_start();
		try {
			( new SettingsPage() )->render();
			$output = ob_get_contents();
		} finally {
			ob_end_clean();
		}
		$this->assertStringContainsString( 'SW Securelogin', $output );
		$this->assertStringContainsString( 'passkey login is not available yet', $output );
	}

	public function testPasswordAuthenticationAndSessionSurviveDeactivation(): void {
		$user = wp_authenticate( 'swsl-test-admin', 'swsl-disposable-test-password' );
		$this->assertInstanceOf( WP_User::class, $user );
		$expiry = time() + 300;
		$sessions = WP_Session_Tokens::get_instance( $user->ID );
		$token = $sessions->create( $expiry );
		$cookie = wp_generate_auth_cookie( $user->ID, $expiry, 'auth', $token );
		$plugin = 'sw-securelogin/sw-securelogin.php';
		$this->assertTrue( is_plugin_active( $plugin ) );
		wp_set_current_user( $user->ID );
		( new Plugin() )->upgrade();
		$settings = get_option( 'swsl_settings' );
		wp_schedule_event( time() + 300, 'hourly', 'swsl_cleanup' );
		$this->assertNotFalse( wp_next_scheduled( 'swsl_cleanup' ) );
		try {
			deactivate_plugins( $plugin );
			$this->assertFalse( is_plugin_active( $plugin ) );
			$this->assertFalse( wp_next_scheduled( 'swsl_cleanup' ) );
			$this->assertSame( $settings, get_option( 'swsl_settings' ) );
			$this->assertSame( $user->ID, wp_validate_auth_cookie( $cookie, 'auth' ) );
			$this->assertTrue( $sessions->verify( $token ) );
			$this->assertInstanceOf( WP_User::class, wp_authenticate( 'swsl-test-admin', 'swsl-disposable-test-password' ) );
		} finally {
			$sessions->destroy( $token );
			$this->assertNull( activate_plugin( $plugin ) );
		}
	}
}

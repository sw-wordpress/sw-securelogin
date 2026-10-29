<?php
/**
 * Composition root.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Core;

use SW\Securelogin\Admin\SettingsPage;
use SW\Securelogin\Core\Migrations\InitializeSettings;

defined( 'ABSPATH' ) || exit;

/** Foundation bootstrap; no authentication hooks are installed in M0. */
final class Plugin {
	/**
	 * Additional migrations provided by the composition root.
	 *
	 * @param array<int, MigrationInterface> $migrations Versioned module migrations.
	 */
	public function __construct( private array $migrations = array() ) {}

	/** Register the administration interface. */
	public function register(): void {
		add_action( 'init', array( $this, 'loadTranslations' ) );
		if ( is_multisite() ) {
			add_action( 'admin_notices', array( $this, 'multisiteNotice' ) );
			return;
		}
		add_action( 'admin_init', array( $this, 'upgrade' ) );
		$page = new SettingsPage();
		add_action( 'admin_menu', array( $page, 'register' ) );
	}

	/** Register bundled translations after WordPress has initialized its locale. */
	public function loadTranslations(): void {
		$plugin = dirname( __DIR__, 2 ) . '/sw-securelogin.php';
		load_plugin_textdomain( 'sw-securelogin', false, dirname( plugin_basename( $plugin ) ) . '/languages' );
	}

	/** Upgrade only on privileged administration requests; never during login. */
	public function upgrade(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$result = ( new MigrationRunner( $wpdb, array( 1 => new InitializeSettings() ) + $this->migrations ) )->run();
		if ( is_wp_error( $result ) ) {
			add_action(
				'admin_notices',
				static function () use ( $result ): void {
					echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
				}
			);
		}
	}

	/** Explain the single-site restriction without affecting existing sessions. */
	public function multisiteNotice(): void {
		if ( current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'SW Securelogin is currently available for single-site installations only.', 'sw-securelogin' ) . '</p></div>';
		}
	}
}

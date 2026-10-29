<?php
/**
 * Administration overview.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Admin;

defined( 'ABSPATH' ) || exit;

/** Read-only foundation overview, restricted to administrators. */
final class SettingsPage {
	/** Register the plugin menu. */
	public function register(): void {
		add_menu_page(
			__( 'SW Securelogin', 'sw-securelogin' ),
			__( 'SW Securelogin', 'sw-securelogin' ),
			'manage_options',
			'swsl-overview',
			array( $this, 'render' ),
			'dashicons-lock'
		);
	}

	/** Render only after checking access, including direct callback invocation. */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'SW Securelogin', 'sw-securelogin' ) . '</h1>';
		echo '<p>' . esc_html__( 'Passkey registration can be enabled below. Password login remains available; passkey login is not available yet.', 'sw-securelogin' ) . '</p>';
		echo '<h2>' . esc_html__( 'Modules', 'sw-securelogin' ) . '</h2><ul>';
		echo '<li>' . esc_html__( 'Passkeys — registration preview', 'sw-securelogin' ) . '</li>';
		echo '<li>' . esc_html__( 'External OpenID Connect login — planned for version 1.1+', 'sw-securelogin' ) . '</li>';
		echo '<li>' . esc_html__( 'Authorization Server — requires a separate security review', 'sw-securelogin' ) . '</li></ul>';
		echo '<h2>' . esc_html__( 'Security status', 'sw-securelogin' ) . '</h2><p>';
		echo is_ssl()
			? esc_html__( 'HTTPS is active for this request.', 'sw-securelogin' )
			: esc_html__( 'HTTPS is required before authentication modules can be used.', 'sw-securelogin' );
		echo '</p>';
		do_action( 'swsl_module_overview' );
		echo '</div>';
	}
}

<?php
/**
 * Accessible profile enrollment controls.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Modules\Passkeys;

defined( 'ABSPATH' ) || exit;


use WP_User;

/** UI is limited to the current user's own WordPress profile. */
final class ProfileController {
	/** Register profile UI and context-specific assets. */
	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/** Load only on the current user's profile.
	 *
	 * @param string $hook Current admin screen.
	 */
	public function enqueue( string $hook ): void {
		if ( 'profile.php' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'swsl-passkeys', plugins_url( 'assets/css/passkeys.css', dirname( __DIR__, 3 ) . '/sw-securelogin.php' ), array(), '0.3.0' );
		wp_enqueue_script( 'swsl-assertion', plugins_url( 'assets/js/passkey-assertion.js', dirname( __DIR__, 3 ) . '/sw-securelogin.php' ), array(), '0.3.0', true );
		wp_enqueue_script( 'swsl-passkeys', plugins_url( 'assets/js/passkeys.js', dirname( __DIR__, 3 ) . '/sw-securelogin.php' ), array( 'swsl-assertion' ), '0.3.0', true );
		wp_localize_script(
			'swsl-passkeys',
			'swslPasskeys',
			array(
				'url'         => rest_url( 'sw-securelogin/v1/passkeys' ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'created'     => __( 'Registered (UTC)', 'sw-securelogin' ),
				'lastUsed'    => __( 'Last used (UTC)', 'sw-securelogin' ),
				'never'       => __( 'Never', 'sw-securelogin' ),
				'error'       => __( 'The passkey operation could not be completed. Confirm again with your password or a passkey and retry.', 'sw-securelogin' ),
				'unsupported' => __( 'Passkeys require a supported browser and a secure connection.', 'sw-securelogin' ),
				'empty'       => __( 'No passkeys registered.', 'sw-securelogin' ),
				'rename'      => __( 'Rename', 'sw-securelogin' ),
				'revoke'      => __( 'Revoke', 'sw-securelogin' ),
				'name'        => __( 'Passkey name', 'sw-securelogin' ),
				'done'        => __( 'Passkey operation completed.', 'sw-securelogin' ),
			)
		);
	}

	/** Render escaped fields, never credentials or private material.
	 *
	 * @param WP_User $user Profile owner.
	 */
	public function render( WP_User $user ): void {
		if ( get_current_user_id() !== $user->ID || ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		echo '<section id="swsl-passkeys" class="swsl-passkeys" aria-labelledby="swsl-passkeys-heading">';
		echo '<div class="swsl-passkeys-heading"><span class="swsl-passkeys-icon dashicons dashicons-lock" aria-hidden="true"></span><div><h2 id="swsl-passkeys-heading">' . esc_html__( 'My passkeys', 'sw-securelogin' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Manage the passkeys linked to your account.', 'sw-securelogin' ) . '</p></div></div>';
		echo '<p class="swsl-passkeys-note">' . esc_html__( 'Use your passkeys to sign in. Password login remains available.', 'sw-securelogin' ) . '</p>';
		echo '<div class="swsl-passkeys-auth"><div><h3>' . esc_html__( 'Confirm each change', 'sw-securelogin' ) . '</h3>';
		echo '<p id="swsl-passkey-password-help" class="description">' . esc_html__( 'Confirm with your password or an existing passkey before each change. The password field is cleared after confirmation.', 'sw-securelogin' ) . '</p></div>';
		echo '<div class="swsl-passkeys-proof"><label for="swsl-passkey-proof">' . esc_html__( 'Confirm with', 'sw-securelogin' ) . '</label><select id="swsl-passkey-proof"><option value="password">' . esc_html__( 'Password', 'sw-securelogin' ) . '</option><option value="passkey">' . esc_html__( 'Existing passkey', 'sw-securelogin' ) . '</option></select>';
		echo '<div id="swsl-passkey-password-fields"><label for="swsl-passkey-password">' . esc_html__( 'Current password', 'sw-securelogin' ) . '</label><input id="swsl-passkey-password" type="password" autocomplete="current-password" aria-describedby="swsl-passkey-password-help"></div></div></div>';
		echo '<div id="swsl-passkey-status" class="swsl-passkeys-status" role="status" aria-live="polite" aria-atomic="true"></div>';
		echo '<div class="swsl-passkeys-grid"><div class="swsl-passkeys-card"><h3>' . esc_html__( 'Add a new passkey', 'sw-securelogin' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Choose a name that helps you recognize this passkey later.', 'sw-securelogin' ) . '</p>';
		echo '<label for="swsl-passkey-name">' . esc_html__( 'Passkey name', 'sw-securelogin' ) . '</label><input id="swsl-passkey-name" type="text" maxlength="80" autocomplete="off">';
		echo '<button id="swsl-passkey-add" class="button button-primary" type="button"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>' . esc_html__( 'Add passkey', 'sw-securelogin' ) . '</button></div>';
		echo '<div class="swsl-passkeys-card swsl-passkeys-registered"><div class="swsl-passkeys-list-heading"><h3>' . esc_html__( 'Registered passkeys', 'sw-securelogin' ) . '</h3><span id="swsl-passkey-count" class="swsl-passkeys-count">0</span></div>';
		echo '<ul id="swsl-passkey-list" class="swsl-passkeys-list"></ul></div></div></section>';
	}
}

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
		wp_enqueue_script( 'swsl-passkeys', plugins_url( 'assets/js/passkeys.js', dirname( __DIR__, 3 ) . '/sw-securelogin.php' ), array(), '0.2.0', true );
		wp_localize_script(
			'swsl-passkeys',
			'swslPasskeys',
			array(
				'url'         => rest_url( 'sw-securelogin/v1/passkeys' ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'created'     => __( 'Registered (UTC)', 'sw-securelogin' ),
				'lastUsed'    => __( 'Last used (UTC)', 'sw-securelogin' ),
				'never'       => __( 'Never', 'sw-securelogin' ),
				'error'       => __( 'The passkey operation could not be completed. Please confirm your password again and retry.', 'sw-securelogin' ),
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
		echo '<section id="swsl-passkeys"><h2>' . esc_html__( 'My passkeys', 'sw-securelogin' ) . '</h2>';
		echo '<p>' . esc_html__( 'Passkey registration is available. Passkey login will follow in a later milestone. Password login remains available.', 'sw-securelogin' ) . '</p>';
		echo '<p><label for="swsl-passkey-name">' . esc_html__( 'Passkey name', 'sw-securelogin' ) . '</label><br><input id="swsl-passkey-name" type="text" maxlength="80" autocomplete="off"></p>';
		echo '<p><label for="swsl-passkey-password">' . esc_html__( 'Confirm your current password for each change', 'sw-securelogin' ) . '</label><br><input id="swsl-passkey-password" type="password" autocomplete="current-password"></p>';
		echo '<p><button id="swsl-passkey-add" class="button" type="button">' . esc_html__( 'Add passkey', 'sw-securelogin' ) . '</button></p>';
		echo '<div id="swsl-passkey-status" role="status" aria-live="polite"></div><ul id="swsl-passkey-list"></ul></section>';
	}
}

<?php
/**
 * Passkey controls confined to the WordPress login form.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Modules\Passkeys;

defined( 'ABSPATH' ) || exit;

/** Preserve WordPress' password form; script only hides it until the user asks for it. */
final class LoginPage {
	/** Register only login-specific hooks. */
	public function register(): void {
		add_action( 'login_form', array( $this, 'render' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/** Enqueue the small browser assertion adapter and login controls. */
	public function enqueue(): void {
		$entry = dirname( __DIR__, 3 ) . '/sw-securelogin.php';
		wp_enqueue_script( 'swsl-assertion', plugins_url( 'assets/js/passkey-assertion.js', $entry ), array(), '0.4.0', true );
		wp_enqueue_script( 'swsl-login', plugins_url( 'assets/js/passkey-login.js', $entry ), array( 'swsl-assertion' ), '0.4.0', true );
		wp_enqueue_style( 'swsl-login', plugins_url( 'assets/css/passkey-login.css', $entry ), array(), '0.4.0' );
		wp_localize_script(
			'swsl-login',
			'swslLogin',
			array(
				'url'         => rest_url( 'sw-securelogin/v1/passkeys/login' ),
				'error'       => __( 'Passkey authentication could not be completed. Retry or sign in with your password.', 'sw-securelogin' ),
				'username'    => __( 'Enter your username or email address to sign in with a passkey.', 'sw-securelogin' ),
				'unsupported' => __( 'Passkeys require a supported browser and a secure connection.', 'sw-securelogin' ),
			)
		);
	}

	/** Render passkey controls without changing core password inputs. */
	public function render(): void {
		echo '<div class="swsl-login"><button type="button" id="swsl-passkey-login" class="button button-secondary">' . esc_html__( 'Sign in with a passkey', 'sw-securelogin' ) . '</button>';
		echo '<p class="swsl-login-help">' . esc_html__( 'Enter your username or email address above, then use a passkey.', 'sw-securelogin' ) . '</p>';
		echo '<p id="swsl-login-status" role="status" aria-live="polite" aria-atomic="true"></p>';
		// Without script the password form stays visible, so the switch starts hidden.
		echo '<p class="swsl-login-switch"><button type="button" id="swsl-password-login" class="button-link" aria-controls="loginform" hidden>' . esc_html__( 'Log in with password', 'sw-securelogin' ) . '</button></p></div>';
	}
}
